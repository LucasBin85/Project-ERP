<?php

use App\DTOs\Financial\BankStatementFiltersDTO;
use App\DTOs\Financial\OfxClassificationDTO;
use App\Models\BankAccount;
use App\Models\BankStatementImport;
use App\Models\BankStatementImportTransaction;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Accounting\CreateBankImportEntry;
use App\Services\Financial\BankStatementService;
use App\Services\Financial\ClassifyOfxDraftEntry;
use App\Services\Financial\OfxOperationTypePolicy;
use App\Services\Financial\ResolveOfxDraftMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

function classificationWallet(): Wallet
{
    $wallet = Wallet::query()->create([
        'user_id' => User::factory()->create()->id,
        'name' => 'Carteira de classificação OFX',
    ]);

    return $wallet->fresh();
}

function classificationBankAccount(Wallet $wallet): BankAccount
{
    return FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.901',
        name: 'Banco para classificação OFX',
    );
}

function classificationEntry(
    Wallet $wallet,
    BankAccount $bankAccount,
    string $direction,
    int $amountCents,
    string $source = 'ofx',
): JournalEntry {
    $entry = app(CreateBankImportEntry::class)->handle(
        wallet: $wallet,
        bankAccountId: $bankAccount->chart_of_account_id,
        amountCents: $amountCents,
        direction: $direction,
        entryDate: '2026-07-12',
        description: 'Movimento para classificação',
        source: $source,
        autoPostIfBalanced: false,
    );

    $bankLine = $entry->lines->firstWhere('chart_of_account_id', $bankAccount->chart_of_account_id);
    $externalId = 'ofx:classification:'.$entry->id;
    $import = BankStatementImport::query()->create([
        'wallet_id' => $wallet->id,
        'bank_account_id' => $bankAccount->id,
        'source' => 'ofx',
        'original_filename' => 'classification-'.$entry->id.'.ofx',
        'file_hash' => sha1($externalId),
        'total_transactions' => 1,
        'imported_transactions' => 1,
        'status' => 'completed',
    ]);

    BankStatementImportTransaction::query()->create([
        'bank_statement_import_id' => $import->id,
        'wallet_id' => $wallet->id,
        'bank_account_id' => $bankAccount->id,
        'journal_entry_id' => $entry->id,
        'journal_line_id' => $bankLine->id,
        'external_id' => $externalId,
        'fit_id' => 'CLASSIFICATION-'.$entry->id,
        'posted_at' => '2026-07-12',
        'description' => 'Movimento para classificação',
        'amount_cents' => $amountCents,
        'direction' => $direction,
        'status' => 'imported',
    ]);

    return $entry;
}

function classificationLinesSnapshot(JournalEntry $entry): array
{
    return JournalLine::query()
        ->where('journal_entry_id', $entry->id)
        ->orderBy('id')
        ->get(['id', 'chart_of_account_id', 'type', 'amount_cents', 'memo'])
        ->map(fn (JournalLine $line) => [
            'id' => $line->id,
            'chart_of_account_id' => $line->chart_of_account_id,
            'type' => $line->type,
            'amount_cents' => $line->amount_cents,
            'memo' => $line->memo,
        ])
        ->all();
}

function classify(
    Wallet $wallet,
    BankAccount $bankAccount,
    JournalEntry $entry,
    ?ChartOfAccount $destination,
    bool $shouldPost = false,
    string $operationType = OfxOperationTypePolicy::EXPENSE,
): JournalEntry {
    return app(ClassifyOfxDraftEntry::class)->execute(
        wallet: $wallet,
        bankAccount: $bankAccount,
        entry: $entry,
        dto: new OfxClassificationDTO(
            operationType: $operationType,
            destinationAccountId: $destination?->id,
            shouldPost: $shouldPost,
        ),
    );
}

function classificationAudit(JournalEntry $entry): BankStatementImportTransaction
{
    return BankStatementImportTransaction::query()
        ->where('journal_entry_id', $entry->id)
        ->firstOrFail();
}

/** @return array{entry: JournalEntry, bankLine: JournalLine} */
function classificationManualCandidate(
    Wallet $wallet,
    BankAccount $bankAccount,
    string $direction,
    int $amountCents,
    string $description,
): array {
    $entry = JournalEntry::query()->create([
        'wallet_id' => $wallet->id,
        'source' => 'manual',
        'entry_date' => '2026-07-12',
        'description' => $description,
        'status' => 'draft',
        'is_balanced' => true,
        'balance_diff_cents' => 0,
    ]);

    $bankLine = JournalLine::query()->create([
        'journal_entry_id' => $entry->id,
        'chart_of_account_id' => $bankAccount->chart_of_account_id,
        'type' => $direction === 'in' ? 'debit' : 'credit',
        'amount_cents' => $amountCents,
    ]);

    JournalLine::query()->create([
        'journal_entry_id' => $entry->id,
        'chart_of_account_id' => $wallet->suspense_account_id,
        'type' => $direction === 'in' ? 'credit' : 'debit',
        'amount_cents' => $amountCents,
    ]);

    return compact('entry', 'bankLine');
}

it('classifies an OFX outflow as an expense without changing the bank line', function () {
    $wallet = classificationWallet();
    $bankAccount = classificationBankAccount($wallet);
    $expense = AccountingTestHelper::account($wallet, '5.9.1', 'Despesa classificada', 'despesa', 'debit');
    $entry = classificationEntry($wallet, $bankAccount, 'out', 12_590);

    $bankLine = $entry->lines->firstWhere('chart_of_account_id', $bankAccount->chart_of_account_id);
    $suspenseLine = $entry->lines->firstWhere('chart_of_account_id', $wallet->suspense_account_id);

    $classified = classify($wallet, $bankAccount, $entry, $expense)->fresh('lines');

    $unchangedBankLine = $classified->lines->firstWhere('id', $bankLine->id);
    $classifiedLine = $classified->lines->firstWhere('id', $suspenseLine->id);
    $debitTotal = $classified->lines->where('type', 'debit')->sum('amount_cents');
    $creditTotal = $classified->lines->where('type', 'credit')->sum('amount_cents');

    expect($classified->source)->toBe('ofx')
        ->and($classified->status)->toBe('draft')
        ->and($classified->lines)->toHaveCount(2)
        ->and($unchangedBankLine->chart_of_account_id)->toBe($bankAccount->chart_of_account_id)
        ->and($unchangedBankLine->type)->toBe('credit')
        ->and($unchangedBankLine->amount_cents)->toBe(12_590)
        ->and($classifiedLine->chart_of_account_id)->toBe($expense->id)
        ->and($classifiedLine->type)->toBe('debit')
        ->and($classifiedLine->amount_cents)->toBe(12_590)
        ->and($classified->lines->contains('chart_of_account_id', $wallet->suspense_account_id))->toBeFalse()
        ->and($debitTotal)->toBe(12_590)
        ->and($creditTotal)->toBe(12_590)
        ->and($classified->is_balanced)->toBeTrue()
        ->and($classified->balance_diff_cents)->toBe(0)
        ->and(classificationAudit($entry)->operation_type)->toBe(OfxOperationTypePolicy::EXPENSE)
        ->and(classificationAudit($entry)->classification_account_id)->toBe($expense->id);
});

it('keeps a legacy OFX audit without a journal line editable and repairs its link', function () {
    $wallet = classificationWallet();
    $bankAccount = classificationBankAccount($wallet);
    $expense = AccountingTestHelper::account($wallet, '5.9.0', 'Despesa legada', 'despesa', 'debit');
    $entry = classificationEntry($wallet, $bankAccount, 'out', 9_900);
    $bankLine = $entry->lines->firstWhere('chart_of_account_id', $bankAccount->chart_of_account_id);
    classificationAudit($entry)->update([
        'journal_line_id' => null,
        'status' => 'skipped_duplicate',
        'resolution' => null,
    ]);

    $statement = app(BankStatementService::class)->build(
        $wallet,
        BankStatementFiltersDTO::fromArray([
            'bank_account_id' => $bankAccount->id,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
            'search' => '',
        ]),
    )->toArray();

    expect($statement['transactions'][0]['operation_type'])->toBeNull()
        ->and($statement['transactions'][0]['can_edit_operation_type'])->toBeTrue()
        ->and($statement['transactions'][0]['can_classify'])->toBeFalse();

    classify(
        $wallet,
        $bankAccount,
        $entry,
        destination: null,
        operationType: OfxOperationTypePolicy::EXPENSE,
    );

    $afterTypeSelection = app(BankStatementService::class)->build(
        $wallet,
        BankStatementFiltersDTO::fromArray([
            'bank_account_id' => $bankAccount->id,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
            'search' => '',
        ]),
    )->toArray();

    expect($afterTypeSelection['transactions'][0]['operation_type'])->toBe(OfxOperationTypePolicy::EXPENSE)
        ->and($afterTypeSelection['transactions'][0]['can_edit_operation_type'])->toBeTrue()
        ->and($afterTypeSelection['transactions'][0]['can_classify'])->toBeTrue()
        ->and(classificationAudit($entry)->journal_line_id)->toBe($bankLine->id);

    $classified = classify($wallet, $bankAccount, $entry, $expense)->fresh('lines');

    expect($classified->lines->contains('chart_of_account_id', $expense->id))->toBeTrue()
        ->and($classified->lines->firstWhere('id', $bankLine->id)->chart_of_account_id)
        ->toBe($bankAccount->chart_of_account_id)
        ->and($classified->is_balanced)->toBeTrue()
        ->and($classified->balance_diff_cents)->toBe(0);
});

it('classifies an OFX inflow as revenue without changing the bank line', function () {
    $wallet = classificationWallet();
    $bankAccount = classificationBankAccount($wallet);
    $revenue = AccountingTestHelper::account($wallet, '4.9.1', 'Receita classificada', 'receita', 'credit');
    $entry = classificationEntry($wallet, $bankAccount, 'in', 350_000);

    $bankLine = $entry->lines->firstWhere('chart_of_account_id', $bankAccount->chart_of_account_id);
    $suspenseLine = $entry->lines->firstWhere('chart_of_account_id', $wallet->suspense_account_id);

    $classified = classify(
        $wallet,
        $bankAccount,
        $entry,
        $revenue,
        operationType: OfxOperationTypePolicy::INCOME,
    )->fresh('lines');

    $unchangedBankLine = $classified->lines->firstWhere('id', $bankLine->id);
    $classifiedLine = $classified->lines->firstWhere('id', $suspenseLine->id);

    expect($classified->status)->toBe('draft')
        ->and($unchangedBankLine->chart_of_account_id)->toBe($bankAccount->chart_of_account_id)
        ->and($unchangedBankLine->type)->toBe('debit')
        ->and($unchangedBankLine->amount_cents)->toBe(350_000)
        ->and($classifiedLine->chart_of_account_id)->toBe($revenue->id)
        ->and($classifiedLine->type)->toBe('credit')
        ->and($classifiedLine->amount_cents)->toBe(350_000)
        ->and($classified->lines->where('type', 'debit')->sum('amount_cents'))->toBe(350_000)
        ->and($classified->lines->where('type', 'credit')->sum('amount_cents'))->toBe(350_000)
        ->and($classified->is_balanced)->toBeTrue()
        ->and($classified->balance_diff_cents)->toBe(0)
        ->and(classificationAudit($entry)->operation_type)->toBe(OfxOperationTypePolicy::INCOME)
        ->and(classificationAudit($entry)->classification_account_id)->toBe($revenue->id);
});

it('requires an operation type before enabling OFX classification in the statement', function () {
    $wallet = classificationWallet();
    $bankAccount = classificationBankAccount($wallet);
    $entry = classificationEntry($wallet, $bankAccount, 'out', 19_900);

    $before = app(BankStatementService::class)->build(
        $wallet,
        new BankStatementFiltersDTO(
            bankAccountId: $bankAccount->id,
            startDate: '2026-07-01',
            endDate: '2026-07-31',
        ),
    )->transactions->firstWhere('journal_entry_id', $entry->id);

    expect($before['operation_type'])->toBeNull()
        ->and($before['can_edit_operation_type'])->toBeTrue()
        ->and($before['can_classify'])->toBeFalse()
        ->and($before['match_status'])->toBe('none');

    classify(
        $wallet,
        $bankAccount,
        $entry,
        destination: null,
        operationType: OfxOperationTypePolicy::EXPENSE,
    );

    $after = app(BankStatementService::class)->build(
        $wallet,
        new BankStatementFiltersDTO(
            bankAccountId: $bankAccount->id,
            startDate: '2026-07-01',
            endDate: '2026-07-31',
        ),
    )->transactions->firstWhere('journal_entry_id', $entry->id);

    expect(classificationAudit($entry)->operation_type)->toBe(OfxOperationTypePolicy::EXPENSE)
        ->and(classificationAudit($entry)->classification_account_id)->toBeNull()
        ->and($after['operation_type'])->toBe(OfxOperationTypePolicy::EXPENSE)
        ->and($after['can_edit_operation_type'])->toBeTrue()
        ->and($after['can_classify'])->toBeTrue();
});

it('applies the operation type policy and resets an incompatible existing classification', function () {
    $wallet = classificationWallet();
    $bankAccount = classificationBankAccount($wallet);
    $expense = AccountingTestHelper::account($wallet, '5.9.14', 'Despesa anterior', 'despesa', 'debit');
    $revenue = AccountingTestHelper::account($wallet, '4.9.14', 'Receita permitida', 'receita', 'credit');
    $entry = classificationEntry($wallet, $bankAccount, 'out', 29_000);

    classify($wallet, $bankAccount, $entry, $expense);

    $changedType = classify(
        $wallet,
        $bankAccount,
        $entry,
        destination: null,
        operationType: OfxOperationTypePolicy::INCOME,
    )->fresh('lines');

    expect($changedType->lines->contains('chart_of_account_id', $expense->id))->toBeFalse()
        ->and($changedType->lines->contains('chart_of_account_id', $wallet->suspense_account_id))->toBeTrue()
        ->and($changedType->is_balanced)->toBeTrue()
        ->and(classificationAudit($entry)->operation_type)->toBe(OfxOperationTypePolicy::INCOME)
        ->and(classificationAudit($entry)->classification_account_id)->toBeNull();

    $beforeInvalidAttempt = classificationLinesSnapshot($changedType);

    expect(fn () => classify(
        $wallet,
        $bankAccount,
        $changedType,
        $expense,
        operationType: OfxOperationTypePolicy::INCOME,
    ))->toThrow(\RuntimeException::class);

    expect(classificationLinesSnapshot($changedType))->toBe($beforeInvalidAttempt);

    $classified = classify(
        $wallet,
        $bankAccount,
        $changedType,
        $revenue,
        operationType: OfxOperationTypePolicy::INCOME,
    )->fresh('lines');

    expect($classified->lines->contains('chart_of_account_id', $revenue->id))->toBeTrue()
        ->and($classified->is_balanced)->toBeTrue()
        ->and(classificationAudit($entry)->classification_account_id)->toBe($revenue->id);
});

it('reclassifies an already classified OFX draft without changing the bank line', function () {
    $wallet = classificationWallet();
    $bankAccount = classificationBankAccount($wallet);
    $firstExpense = AccountingTestHelper::account($wallet, '5.9.9', 'Despesa inicial', 'despesa', 'debit');
    $correctExpense = AccountingTestHelper::account($wallet, '5.9.10', 'Despesa corrigida', 'despesa', 'debit');
    $entry = classificationEntry($wallet, $bankAccount, 'out', 31_500);

    $firstClassification = classify($wallet, $bankAccount, $entry, $firstExpense)->fresh('lines');
    $bankLineBefore = $firstClassification->lines->firstWhere(
        'chart_of_account_id',
        $bankAccount->chart_of_account_id,
    );
    $classificationLineBefore = $firstClassification->lines->firstWhere(
        'chart_of_account_id',
        $firstExpense->id,
    );
    classificationAudit($entry)->update([
        'journal_line_id' => null,
        'status' => 'skipped_duplicate',
    ]);

    $statementTransaction = app(BankStatementService::class)->build(
        $wallet,
        new BankStatementFiltersDTO(
            bankAccountId: $bankAccount->id,
            startDate: '2026-07-01',
            endDate: '2026-07-31',
        ),
    )->transactions->firstWhere('journal_entry_id', $entry->id);

    expect($statementTransaction['classification_account_id'])->toBe($firstExpense->id)
        ->and($statementTransaction['can_edit_operation_type'])->toBeTrue()
        ->and($statementTransaction['can_classify'])->toBeTrue();

    $reclassified = classify($wallet, $bankAccount, $firstClassification, $correctExpense)->fresh('lines');
    $bankLineAfter = $reclassified->lines->firstWhere('id', $bankLineBefore->id);
    $classificationLineAfter = $reclassified->lines->firstWhere('id', $classificationLineBefore->id);

    expect($reclassified->status)->toBe('draft')
        ->and($reclassified->lines)->toHaveCount(2)
        ->and($bankLineAfter->chart_of_account_id)->toBe($bankAccount->chart_of_account_id)
        ->and($bankLineAfter->type)->toBe($bankLineBefore->type)
        ->and($bankLineAfter->amount_cents)->toBe($bankLineBefore->amount_cents)
        ->and($bankLineAfter->memo)->toBe($bankLineBefore->memo)
        ->and($classificationLineAfter->chart_of_account_id)->toBe($correctExpense->id)
        ->and($classificationLineAfter->type)->toBe('debit')
        ->and($classificationLineAfter->amount_cents)->toBe(31_500)
        ->and($classificationLineAfter->memo)->toBe('Classificação OFX: '.$correctExpense->name)
        ->and($reclassified->lines->contains('chart_of_account_id', $firstExpense->id))->toBeFalse()
        ->and($reclassified->lines->where('type', 'debit')->sum('amount_cents'))->toBe(31_500)
        ->and($reclassified->lines->where('type', 'credit')->sum('amount_cents'))->toBe(31_500)
        ->and($reclassified->is_balanced)->toBeTrue()
        ->and($reclassified->balance_diff_cents)->toBe(0)
        ->and(classificationAudit($entry)->journal_line_id)->toBe($bankLineBefore->id);
});

it('only allows inline classification from the OFX origin bank line', function () {
    $wallet = classificationWallet();
    $originBankAccount = classificationBankAccount($wallet);
    $otherBankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.902',
        name: 'Banco de contrapartida',
    );
    $otherBankAccount->chartOfAccount->update(['financial_group' => 'available']);
    $expense = AccountingTestHelper::account($wallet, '5.9.13', 'Despesa final', 'despesa', 'debit');
    $entry = classificationEntry($wallet, $originBankAccount, 'out', 33_000);

    $classifiedAsTransfer = classify(
        $wallet,
        $originBankAccount,
        $entry,
        $otherBankAccount->chartOfAccount,
        operationType: OfxOperationTypePolicy::TRANSFER,
    )->fresh('lines');
    $beforeInvalidAttempt = classificationLinesSnapshot($classifiedAsTransfer);

    $otherStatement = app(BankStatementService::class)->build(
        $wallet,
        new BankStatementFiltersDTO(
            bankAccountId: $otherBankAccount->id,
            startDate: '2026-07-01',
            endDate: '2026-07-31',
        ),
    );
    $otherStatementTransaction = $otherStatement->transactions->firstWhere(
        'journal_entry_id',
        $entry->id,
    );

    expect($otherStatementTransaction['can_classify'])->toBeFalse();

    expect(fn () => classify($wallet, $otherBankAccount, $classifiedAsTransfer, $expense))
        ->toThrow(\RuntimeException::class);

    expect(classificationLinesSnapshot($classifiedAsTransfer))->toBe($beforeInvalidAttempt);

    $corrected = classify($wallet, $originBankAccount, $classifiedAsTransfer, $expense)->fresh('lines');

    expect($corrected->lines->contains('chart_of_account_id', $originBankAccount->chart_of_account_id))->toBeTrue()
        ->and($corrected->lines->contains('chart_of_account_id', $otherBankAccount->chart_of_account_id))->toBeFalse()
        ->and($corrected->lines->contains('chart_of_account_id', $expense->id))->toBeTrue()
        ->and($corrected->is_balanced)->toBeTrue();
});

it('classifies and posts an OFX entry', function () {
    $wallet = classificationWallet();
    $bankAccount = classificationBankAccount($wallet);
    $expense = AccountingTestHelper::account($wallet, '5.9.2', 'Despesa postada', 'despesa', 'debit');
    $entry = classificationEntry($wallet, $bankAccount, 'out', 42_000);

    $classified = classify($wallet, $bankAccount, $entry, $expense, shouldPost: true)->fresh('lines');

    expect($classified->status)->toBe('posted')
        ->and($classified->posted_at)->not->toBeNull()
        ->and($classified->lines->contains('chart_of_account_id', $wallet->suspense_account_id))->toBeFalse()
        ->and($classified->lines->contains('chart_of_account_id', $expense->id))->toBeTrue()
        ->and($classified->is_balanced)->toBeTrue()
        ->and($classified->balance_diff_cents)->toBe(0);
});

it('does not classify an entry that is already posted', function () {
    $wallet = classificationWallet();
    $bankAccount = classificationBankAccount($wallet);
    $expense = AccountingTestHelper::account($wallet, '5.9.3', 'Despesa inválida', 'despesa', 'debit');
    $entry = classificationEntry($wallet, $bankAccount, 'out', 18_500);
    $entry->update(['status' => 'posted', 'posted_at' => now()]);
    $before = classificationLinesSnapshot($entry);

    expect(fn () => classify($wallet, $bankAccount, $entry, $expense))
        ->toThrow(\RuntimeException::class);

    expect($entry->fresh()->status)->toBe('posted')
        ->and(classificationLinesSnapshot($entry))->toBe($before);
});

it('does not classify into an account from another wallet', function () {
    $wallet = classificationWallet();
    $bankAccount = classificationBankAccount($wallet);
    $otherWallet = classificationWallet();
    $otherAccount = AccountingTestHelper::account($otherWallet, '5.9.4', 'Despesa de outra carteira', 'despesa', 'debit');
    $entry = classificationEntry($wallet, $bankAccount, 'out', 21_000);
    $before = classificationLinesSnapshot($entry);

    expect(fn () => classify($wallet, $bankAccount, $entry, $otherAccount))
        ->toThrow(\RuntimeException::class);

    expect(classificationLinesSnapshot($entry))->toBe($before);
});

it('does not classify into a synthetic account', function () {
    $wallet = classificationWallet();
    $bankAccount = classificationBankAccount($wallet);
    $synthetic = ChartOfAccount::query()->create([
        'wallet_id' => $wallet->id,
        'code' => '5.9',
        'name' => 'Grupo sintético',
        'type' => 'despesa',
        'normal_balance' => 'debit',
        'allows_posting' => false,
    ]);
    $entry = classificationEntry($wallet, $bankAccount, 'out', 22_000);
    $before = classificationLinesSnapshot($entry);

    expect(fn () => classify($wallet, $bankAccount, $entry, $synthetic))
        ->toThrow(\RuntimeException::class);

    expect(classificationLinesSnapshot($entry))->toBe($before);
});

it('does not classify back into the suspense account', function () {
    $wallet = classificationWallet();
    $bankAccount = classificationBankAccount($wallet);
    $entry = classificationEntry($wallet, $bankAccount, 'out', 23_000);
    $suspense = ChartOfAccount::query()->findOrFail($wallet->suspense_account_id);
    $before = classificationLinesSnapshot($entry);

    expect(fn () => classify($wallet, $bankAccount, $entry, $suspense))
        ->toThrow(\RuntimeException::class);

    expect(classificationLinesSnapshot($entry))->toBe($before);
});

it('does not classify a manual entry even when it uses the suspense account', function () {
    $wallet = classificationWallet();
    $bankAccount = classificationBankAccount($wallet);
    $expense = AccountingTestHelper::account($wallet, '5.9.5', 'Despesa manual', 'despesa', 'debit');
    $entry = classificationEntry($wallet, $bankAccount, 'out', 24_000, source: 'manual');
    $before = classificationLinesSnapshot($entry);

    expect(fn () => classify($wallet, $bankAccount, $entry, $expense))
        ->toThrow(\RuntimeException::class);

    expect($entry->fresh()->source)->toBe('manual')
        ->and(classificationLinesSnapshot($entry))->toBe($before);
});

it('does not classify an OFX entry without a single editable counterpart line', function () {
    $wallet = classificationWallet();
    $bankAccount = classificationBankAccount($wallet);
    $firstExpense = AccountingTestHelper::account($wallet, '5.9.6', 'Primeira despesa', 'despesa', 'debit');
    $secondExpense = AccountingTestHelper::account($wallet, '5.9.11', 'Segunda despesa', 'despesa', 'debit');
    $newDestination = AccountingTestHelper::account($wallet, '5.9.12', 'Novo destino', 'despesa', 'debit');
    $entry = classificationEntry($wallet, $bankAccount, 'out', 25_000);
    $suspenseLine = $entry->lines()
        ->where('chart_of_account_id', $wallet->suspense_account_id)
        ->firstOrFail();
    $suspenseLine->update([
        'chart_of_account_id' => $firstExpense->id,
        'amount_cents' => 10_000,
    ]);
    $entry->lines()->create([
        'chart_of_account_id' => $secondExpense->id,
        'type' => 'debit',
        'amount_cents' => 15_000,
    ]);
    $entry->recalcBalance();
    $entry->save();
    $before = classificationLinesSnapshot($entry);

    expect(fn () => classify($wallet, $bankAccount, $entry, $newDestination))
        ->toThrow(\RuntimeException::class);

    expect(classificationLinesSnapshot($entry))->toBe($before)
        ->and($entry->fresh()->is_balanced)->toBeTrue()
        ->and($entry->fresh()->balance_diff_cents)->toBe(0);
});

it('classifies an OFX entry through the bank statement endpoint', function () {
    $wallet = classificationWallet();
    $bankAccount = classificationBankAccount($wallet);
    $expense = AccountingTestHelper::account($wallet, '5.9.7', 'Despesa pelo extrato', 'despesa', 'debit');
    $entry = classificationEntry($wallet, $bankAccount, 'out', 26_000);
    $statementUrl = route('bank-accounts.statement', $bankAccount);

    $response = $this
        ->actingAs($wallet->user)
        ->withSession(['active_wallet' => $wallet->id])
        ->from($statementUrl)
        ->post(route('bank-accounts.statement.classify', [$bankAccount, $entry]), [
            'operation_type' => OfxOperationTypePolicy::EXPENSE,
            'chart_of_account_id' => $expense->id,
            'should_post' => false,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect($statementUrl);

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $entry->id,
        'chart_of_account_id' => $expense->id,
        'type' => 'debit',
        'amount_cents' => 26_000,
    ]);

    expect($entry->fresh()->status)->toBe('draft')
        ->and($entry->fresh()->is_balanced)->toBeTrue()
        ->and(classificationAudit($entry)->operation_type)->toBe(OfxOperationTypePolicy::EXPENSE)
        ->and(classificationAudit($entry)->classification_account_id)->toBe($expense->id);
});

it('rejects inline classification when operation type was not selected', function () {
    $wallet = classificationWallet();
    $bankAccount = classificationBankAccount($wallet);
    $expense = AccountingTestHelper::account($wallet, '5.9.15', 'Destino sem tipo', 'despesa', 'debit');
    $entry = classificationEntry($wallet, $bankAccount, 'out', 26_500);
    $before = classificationLinesSnapshot($entry);
    $statementUrl = route('bank-accounts.statement', $bankAccount);

    $response = $this
        ->actingAs($wallet->user)
        ->withSession(['active_wallet' => $wallet->id])
        ->from($statementUrl)
        ->post(route('bank-accounts.statement.classify', [$bankAccount, $entry]), [
            'chart_of_account_id' => $expense->id,
            'should_post' => false,
        ]);

    $response
        ->assertSessionHasErrors(['operation_type'])
        ->assertRedirect($statementUrl);

    expect(classificationLinesSnapshot($entry))->toBe($before)
        ->and(classificationAudit($entry)->operation_type)->toBeNull()
        ->and(classificationAudit($entry)->classification_account_id)->toBeNull();
});

it('requires an explicit keep decision before classifying when a unique manual match exists', function () {
    $wallet = classificationWallet();
    $bankAccount = classificationBankAccount($wallet);
    $expense = AccountingTestHelper::account($wallet, '5.9.16', 'Despesa após manter OFX', 'despesa', 'debit');
    $entry = classificationEntry($wallet, $bankAccount, 'out', 28_000);
    classificationManualCandidate($wallet, $bankAccount, 'out', 28_000, 'Candidato manual');
    $bankLine = $entry->lines->firstWhere('chart_of_account_id', $bankAccount->chart_of_account_id);
    classificationAudit($entry)->update([
        'journal_line_id' => null,
        'status' => 'skipped_duplicate',
    ]);
    $before = classificationLinesSnapshot($entry);

    expect(fn () => classify($wallet, $bankAccount, $entry, $expense))
        ->toThrow(\RuntimeException::class);

    expect(classificationLinesSnapshot($entry))->toBe($before);

    $kept = app(ResolveOfxDraftMatch::class)->execute(
        wallet: $wallet,
        bankAccount: $bankAccount,
        entry: $entry,
        action: 'keep',
    );

    expect($kept?->id)->toBe($entry->id)
        ->and(classificationAudit($entry)->status)->toBe('imported')
        ->and(classificationAudit($entry)->resolution)->toBe('kept')
        ->and(classificationAudit($entry)->journal_line_id)->toBe($bankLine->id);

    $classified = classify($wallet, $bankAccount, $entry, $expense)->fresh('lines');

    expect($classified->lines->contains('chart_of_account_id', $expense->id))->toBeTrue()
        ->and($classified->is_balanced)->toBeTrue();
});

it('links an OFX draft to an explicitly selected manual match without duplicating the movement', function () {
    $wallet = classificationWallet();
    $bankAccount = classificationBankAccount($wallet);
    $entry = classificationEntry($wallet, $bankAccount, 'in', 74_000);
    $manual = classificationManualCandidate($wallet, $bankAccount, 'in', 74_000, 'Recebimento manual existente');
    $audit = classificationAudit($entry);
    $audit->update([
        'journal_line_id' => null,
        'status' => 'skipped_duplicate',
    ]);

    $resolved = app(ResolveOfxDraftMatch::class)->execute(
        wallet: $wallet,
        bankAccount: $bankAccount,
        entry: $entry,
        action: 'link',
        candidateJournalLineId: $manual['bankLine']->id,
    );

    $audit = $audit->fresh();

    $manualStatementTransaction = app(BankStatementService::class)->build(
        $wallet,
        new BankStatementFiltersDTO(
            bankAccountId: $bankAccount->id,
            startDate: '2026-07-01',
            endDate: '2026-07-31',
        ),
    )->transactions->firstWhere('journal_entry_id', $manual['entry']->id);

    expect($resolved?->id)->toBe($manual['entry']->id)
        ->and($resolved?->source)->toBe('manual')
        ->and(JournalEntry::query()->whereKey($entry->id)->exists())->toBeFalse()
        ->and($audit->journal_entry_id)->toBe($manual['entry']->id)
        ->and($audit->journal_line_id)->toBe($manual['bankLine']->id)
        ->and($audit->status)->toBe('imported')
        ->and($audit->resolution)->toBe('linked')
        ->and($audit->operation_type)->toBeNull()
        ->and($audit->classification_account_id)->toBeNull()
        ->and($manualStatementTransaction['reconciliation_status'])->toBe('reconciled')
        ->and($manual['entry']->fresh()->is_balanced)->toBeTrue();
});

it('rejects a posted OFX entry through the bank statement endpoint', function () {
    $wallet = classificationWallet();
    $bankAccount = classificationBankAccount($wallet);
    $expense = AccountingTestHelper::account($wallet, '5.9.8', 'Destino bloqueado', 'despesa', 'debit');
    $entry = classificationEntry($wallet, $bankAccount, 'out', 27_000);
    $entry->update(['status' => 'posted', 'posted_at' => now()]);
    $before = classificationLinesSnapshot($entry);
    $statementUrl = route('bank-accounts.statement', $bankAccount);

    $response = $this
        ->actingAs($wallet->user)
        ->withSession(['active_wallet' => $wallet->id])
        ->from($statementUrl)
        ->post(route('bank-accounts.statement.classify', [$bankAccount, $entry]), [
            'operation_type' => OfxOperationTypePolicy::EXPENSE,
            'chart_of_account_id' => $expense->id,
            'should_post' => false,
        ]);

    $response
        ->assertSessionHasErrors(['chart_of_account_id'])
        ->assertRedirect($statementUrl);

    expect($entry->fresh()->status)->toBe('posted')
        ->and(classificationLinesSnapshot($entry))->toBe($before);
});
