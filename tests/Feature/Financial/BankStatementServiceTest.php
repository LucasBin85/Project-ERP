<?php

use App\DTOs\Financial\BankStatementFiltersDTO;
use App\Models\BankStatementImport;
use App\Models\BankStatementImportTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\BankStatementService;
use App\Services\Financial\OfxOperationTypePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

$createStatementScenario = function (): array {
    $user = User::factory()->create();

    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.001',
        name: 'Banco Principal',
    );

    $capital = AccountingTestHelper::account($wallet, '3.1', 'Capital Social', 'patrimonio', 'credit');
    $revenue = AccountingTestHelper::account($wallet, '4.1', 'Receita de Serviços', 'receita', 'credit');
    $expense = AccountingTestHelper::account($wallet, '5.1', 'Despesa Administrativa', 'despesa', 'debit');

    $suspense = AccountingTestHelper::account($wallet, '1.1.99', 'A classificar', 'ativo', 'debit');
    $wallet->update(['suspense_account_id' => $suspense->id]);

    AccountingTestHelper::createPostedEntry($wallet, '2026-06-30', [
        [$bankAccount->chartOfAccount, 'debit', 100000],
        [$capital, 'credit', 100000],
    ]);

    $inflowEntry = AccountingTestHelper::createPostedEntry($wallet, '2026-07-01', [
        [$bankAccount->chartOfAccount, 'debit', 50000],
        [$revenue, 'credit', 50000],
    ]);
    $inflowEntry->update([
        'description' => 'Recebimento manual',
    ]);
    $inflowLine = $inflowEntry->lines()
        ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
        ->firstOrFail();
    $inflowLine->update([
        'memo' => 'Memo secundario do recebimento',
    ]);

    $outflowEntry = AccountingTestHelper::createPostedEntry($wallet, '2026-07-02', [
        [$expense, 'debit', 12000],
        [$bankAccount->chartOfAccount, 'credit', 12000],
    ]);
    $outflowEntry->update([
        'description' => null,
        'source' => 'manual',
    ]);
    $outflowLine = $outflowEntry->lines()
        ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
        ->firstOrFail();
    $outflowLine->update([
        'memo' => 'Tarifa bancaria no memo',
    ]);

    $draftEntry = JournalEntry::query()->create([
        'wallet_id' => $wallet->id,
        'source' => 'ofx',
        'entry_date' => '2026-07-03',
        'description' => 'OFX pendente',
        'status' => 'draft',
        'is_balanced' => true,
        'balance_diff_cents' => 0,
    ]);

    JournalLine::query()->create([
        'journal_entry_id' => $draftEntry->id,
        'chart_of_account_id' => $suspense->id,
        'type' => 'debit',
        'amount_cents' => 8000,
    ]);

    $draftBankLine = JournalLine::query()->create([
        'journal_entry_id' => $draftEntry->id,
        'chart_of_account_id' => $bankAccount->chart_of_account_id,
        'type' => 'credit',
        'amount_cents' => 8000,
        'memo' => 'Memo secundario do OFX',
    ]);

    $import = BankStatementImport::query()->create([
        'wallet_id' => $wallet->id,
        'bank_account_id' => $bankAccount->id,
        'source' => 'ofx',
        'original_filename' => 'match-manual.ofx',
        'file_hash' => sha1('match-manual'),
        'total_transactions' => 2,
        'imported_transactions' => 2,
        'status' => 'completed',
    ]);

    BankStatementImportTransaction::query()->create([
        'bank_statement_import_id' => $import->id,
        'wallet_id' => $wallet->id,
        'bank_account_id' => $bankAccount->id,
        'journal_entry_id' => $outflowEntry->id,
        'journal_line_id' => $outflowLine->id,
        'external_id' => 'ofx:bank-account:'.$bankAccount->id.':MATCH-MANUAL',
        'fit_id' => 'MATCH-MANUAL',
        'posted_at' => '2026-07-02',
        'description' => 'Tarifa bancaria no memo',
        'amount_cents' => 12000,
        'direction' => 'out',
        'status' => 'imported',
    ]);

    $draftAudit = BankStatementImportTransaction::query()->create([
        'bank_statement_import_id' => $import->id,
        'wallet_id' => $wallet->id,
        'bank_account_id' => $bankAccount->id,
        'journal_entry_id' => $draftEntry->id,
        'journal_line_id' => $draftBankLine->id,
        'external_id' => 'ofx:bank-account:'.$bankAccount->id.':OFX-DRAFT',
        'fit_id' => 'OFX-DRAFT',
        'posted_at' => '2026-07-03',
        'description' => 'OFX pendente',
        'amount_cents' => 8000,
        'direction' => 'out',
        'status' => 'imported',
    ]);

    return compact(
        'wallet',
        'bankAccount',
        'expense',
        'revenue',
        'inflowEntry',
        'inflowLine',
        'outflowEntry',
        'outflowLine',
        'draftEntry',
        'draftBankLine',
        'draftAudit',
    );
};

$addManualMatch = function (array $scenario, string $description): JournalLine {
    $entry = AccountingTestHelper::createPostedEntry($scenario['wallet'], '2026-07-03', [
        [$scenario['expense'], 'debit', 8000],
        [$scenario['bankAccount']->chartOfAccount, 'credit', 8000],
    ]);
    $entry->update(['description' => $description]);

    return $entry->lines()
        ->where('chart_of_account_id', $scenario['bankAccount']->chart_of_account_id)
        ->firstOrFail();
};

it('keeps an already classified OFX draft editable and exposes its selected account', function () use ($createStatementScenario) {
    $scenario = $createStatementScenario();
    $scenario['draftEntry']->lines()
        ->where('chart_of_account_id', $scenario['wallet']->suspense_account_id)
        ->update(['chart_of_account_id' => $scenario['expense']->id]);
    $scenario['draftAudit']->update([
        'operation_type' => OfxOperationTypePolicy::EXPENSE,
        'classification_account_id' => $scenario['expense']->id,
    ]);

    $statement = app(BankStatementService::class)->build(
        $scenario['wallet'],
        new BankStatementFiltersDTO(
            bankAccountId: $scenario['bankAccount']->id,
            startDate: '2026-07-01',
            endDate: '2026-07-31',
        ),
    );

    $draftTransaction = $statement->transactions->firstWhere(
        'journal_entry_id',
        $scenario['draftEntry']->id,
    );

    expect($draftTransaction['accounting_status'])->toBe('draft')
        ->and($draftTransaction['source'])->toBe('ofx')
        ->and($draftTransaction['classification_status'])->toBe('classified')
        ->and($draftTransaction['classification_label'])->toBe('Despesa Administrativa')
        ->and($draftTransaction['classification_account_id'])->toBe($scenario['expense']->id)
        ->and($draftTransaction['operation_type'])->toBe(OfxOperationTypePolicy::EXPENSE)
        ->and($draftTransaction['workflow_status'])->toBe('classified')
        ->and($draftTransaction['can_edit_operation_type'])->toBeTrue()
        ->and($draftTransaction['match_status'])->toBe('none')
        ->and($draftTransaction['can_classify'])->toBeTrue();
});

it('keeps a posted OFX classification read only', function () use ($createStatementScenario) {
    $scenario = $createStatementScenario();
    $scenario['draftEntry']->lines()
        ->where('chart_of_account_id', $scenario['wallet']->suspense_account_id)
        ->update(['chart_of_account_id' => $scenario['expense']->id]);
    $scenario['draftEntry']->update([
        'status' => 'posted',
        'posted_at' => now(),
    ]);

    $statement = app(BankStatementService::class)->build(
        $scenario['wallet'],
        new BankStatementFiltersDTO(
            bankAccountId: $scenario['bankAccount']->id,
            startDate: '2026-07-01',
            endDate: '2026-07-31',
        ),
    );

    $postedTransaction = $statement->transactions->firstWhere(
        'journal_entry_id',
        $scenario['draftEntry']->id,
    );

    expect($postedTransaction['accounting_status'])->toBe('posted')
        ->and($postedTransaction['source'])->toBe('ofx')
        ->and($postedTransaction['classification_account_id'])->toBe($scenario['expense']->id)
        ->and($postedTransaction['can_classify'])->toBeFalse();
});

it('keeps a manual draft classification read only', function () use ($createStatementScenario) {
    $scenario = $createStatementScenario();
    $scenario['draftEntry']->lines()
        ->where('chart_of_account_id', $scenario['wallet']->suspense_account_id)
        ->update(['chart_of_account_id' => $scenario['expense']->id]);
    $scenario['draftEntry']->update(['source' => 'manual']);

    $statement = app(BankStatementService::class)->build(
        $scenario['wallet'],
        new BankStatementFiltersDTO(
            bankAccountId: $scenario['bankAccount']->id,
            startDate: '2026-07-01',
            endDate: '2026-07-31',
        ),
    );

    $manualTransaction = $statement->transactions->firstWhere(
        'journal_entry_id',
        $scenario['draftEntry']->id,
    );

    expect($manualTransaction['accounting_status'])->toBe('draft')
        ->and($manualTransaction['source'])->toBe('manual')
        ->and($manualTransaction['classification_account_id'])->toBe($scenario['expense']->id)
        ->and($manualTransaction['can_classify'])->toBeFalse();
});

it('builds a bank statement with complete draft and posted entries ordered from latest to oldest', function () use ($createStatementScenario) {
    $scenario = $createStatementScenario();

    $statement = app(BankStatementService::class)->build(
        $scenario['wallet'],
        new BankStatementFiltersDTO(
            bankAccountId: $scenario['bankAccount']->id,
            startDate: '2026-07-01',
            endDate: '2026-07-31',
        ),
    );

    $transactions = $statement->transactions;

    expect($statement->ready)->toBeTrue()
        ->and($statement->openingBalanceCents)->toBe(100000)
        ->and($statement->totalInflowsCents)->toBe(50000)
        ->and($statement->totalOutflowsCents)->toBe(20000)
        ->and($statement->closingBalanceCents)->toBe(130000)
        ->and($transactions)->toHaveCount(3)
        ->and($transactions->map(fn (array $transaction) => $transaction['date']->toDateString())->all())
        ->toBe(['2026-07-03', '2026-07-02', '2026-07-01'])
        ->and($transactions->pluck('id')->all())->toBe([
            $scenario['draftBankLine']->id,
            $scenario['outflowLine']->id,
            $scenario['inflowLine']->id,
        ])
        ->and($transactions->pluck('journal_entry_id')->all())->toBe([
            $scenario['draftEntry']->id,
            $scenario['outflowEntry']->id,
            $scenario['inflowEntry']->id,
        ])
        ->and($transactions->pluck('description')->all())->toBe([
            'OFX pendente',
            'Tarifa bancaria no memo',
            'Recebimento manual',
        ])
        ->and($transactions->pluck('accounting_status')->all())->toBe(['draft', 'posted', 'posted'])
        ->and($transactions->pluck('source')->all())->toBe(['ofx', 'manual', 'manual'])
        ->and($transactions->pluck('source_label')->all())->toBe(['OFX', 'Manual', 'Manual'])
        ->and($transactions->pluck('reconciliation_status')->all())->toBe(['reconciled_via_import', 'reconciled', 'pending'])
        ->and($transactions->pluck('classification_status')->all())->toBe(['unclassified', 'classified', 'classified'])
        ->and($transactions->pluck('workflow_status')->all())->toBe(['pending_classification', 'posted', 'posted'])
        ->and($transactions->pluck('classification_label')->all())->toBe(['A classificar', 'Despesa Administrativa', 'Receita de Serviços'])
        ->and($transactions->pluck('classification_account_id')->all())->toBe([null, $scenario['expense']->id, $scenario['revenue']->id])
        ->and($transactions->pluck('operation_type')->all())->toBe([null, null, null])
        ->and($transactions->pluck('allowed_operation_types')->all())->toBe([
            [
                OfxOperationTypePolicy::TRANSFER,
                OfxOperationTypePolicy::PAYMENT,
                OfxOperationTypePolicy::INVESTMENT,
                OfxOperationTypePolicy::EXPENSE,
                OfxOperationTypePolicy::FEE,
                OfxOperationTypePolicy::OTHER,
            ],
            [
                OfxOperationTypePolicy::TRANSFER,
                OfxOperationTypePolicy::PAYMENT,
                OfxOperationTypePolicy::INVESTMENT,
                OfxOperationTypePolicy::EXPENSE,
                OfxOperationTypePolicy::FEE,
                OfxOperationTypePolicy::OTHER,
            ],
            [
                OfxOperationTypePolicy::TRANSFER,
                OfxOperationTypePolicy::INCOME,
                OfxOperationTypePolicy::INVESTMENT,
                OfxOperationTypePolicy::OTHER,
            ],
        ])
        ->and($transactions->pluck('can_edit_operation_type')->all())->toBe([true, false, false])
        ->and($transactions->pluck('can_classify')->all())->toBe([false, false, false])
        ->and($transactions->pluck('match_status')->all())->toBe(['none', 'none', 'none'])
        ->and($transactions->pluck('type')->all())->toBe(['outflow', 'outflow', 'inflow'])
        ->and($transactions->pluck('inflow_cents')->all())->toBe([null, null, 50000])
        ->and($transactions->pluck('outflow_cents')->all())->toBe([8000, 12000, null])
        ->and($transactions->pluck('amount_cents')->all())->toBe([-8000, -12000, 50000])
        ->and($transactions->pluck('running_balance_cents')->all())->toBe([130000, 138000, 150000])
        ->and($transactions->every(fn (array $transaction) => ! array_key_exists('journal_entry_url', $transaction)))
        ->toBeTrue();
});

it('exposes a unique exact manual match and blocks operation type and classification until resolution', function () use ($createStatementScenario, $addManualMatch) {
    $scenario = $createStatementScenario();
    $candidate = $addManualMatch($scenario, 'Candidato manual exato');
    $scenario['draftAudit']->update(['operation_type' => OfxOperationTypePolicy::EXPENSE]);

    $statement = app(BankStatementService::class)->build(
        $scenario['wallet'],
        new BankStatementFiltersDTO(
            bankAccountId: $scenario['bankAccount']->id,
            startDate: '2026-07-01',
            endDate: '2026-07-31',
        ),
    );

    $transaction = $statement->transactions->firstWhere(
        'journal_entry_id',
        $scenario['draftEntry']->id,
    );

    expect($transaction['match_status'])->toBe('unique')
        ->and($transaction['match_candidates'])->toHaveCount(1)
        ->and($transaction['match_candidates'][0]['journal_line_id'])->toBe($candidate->id)
        ->and($transaction['match_candidates'][0]['journal_entry_id'])->toBe($candidate->journal_entry_id)
        ->and($transaction['match_candidates'][0]['description'])->toBe('Candidato manual exato')
        ->and($transaction['can_edit_operation_type'])->toBeFalse()
        ->and($transaction['can_classify'])->toBeFalse();
});

it('exposes all compatible candidates when an OFX draft has an ambiguous match', function () use ($createStatementScenario, $addManualMatch) {
    $scenario = $createStatementScenario();
    $first = $addManualMatch($scenario, 'Primeiro candidato');
    $second = $addManualMatch($scenario, 'Segundo candidato');

    $statement = app(BankStatementService::class)->build(
        $scenario['wallet'],
        new BankStatementFiltersDTO(
            bankAccountId: $scenario['bankAccount']->id,
            startDate: '2026-07-01',
            endDate: '2026-07-31',
        ),
    );

    $transaction = $statement->transactions->firstWhere(
        'journal_entry_id',
        $scenario['draftEntry']->id,
    );

    expect($transaction['match_status'])->toBe('ambiguous')
        ->and(collect($transaction['match_candidates'])->pluck('journal_line_id')->all())
        ->toBe([$first->id, $second->id])
        ->and($transaction['can_edit_operation_type'])->toBeFalse()
        ->and($transaction['can_classify'])->toBeFalse()
        ->and($transaction['match_resolution'])->toBeNull();
});

it('filters displayed entries without changing totals or chronological balances', function () use ($createStatementScenario) {
    $scenario = $createStatementScenario();

    $statement = app(BankStatementService::class)->build(
        $scenario['wallet'],
        new BankStatementFiltersDTO(
            bankAccountId: $scenario['bankAccount']->id,
            startDate: '2026-07-01',
            endDate: '2026-07-31',
            search: 'tarifa BANCARIA',
        ),
    );

    expect($statement->totalInflowsCents)->toBe(50000)
        ->and($statement->totalOutflowsCents)->toBe(20000)
        ->and($statement->closingBalanceCents)->toBe(130000)
        ->and($statement->transactions)->toHaveCount(1)
        ->and($statement->transactions->first()['id'])->toBe($scenario['outflowLine']->id)
        ->and($statement->transactions->first()['description'])->toBe('Tarifa bancaria no memo')
        ->and($statement->transactions->first()['running_balance_cents'])->toBe(138000);
});

it('is not ready without required filters', function () {
    $user = User::factory()->create();

    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    $statement = app(BankStatementService::class)->build(
        $wallet,
        new BankStatementFiltersDTO(
            bankAccountId: null,
            startDate: null,
            endDate: null,
        ),
    );

    expect($statement->ready)->toBeFalse()
        ->and($statement->transactions)->toHaveCount(0);
});
