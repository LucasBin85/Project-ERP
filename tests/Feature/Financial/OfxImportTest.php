<?php

use App\Models\BankAccount;
use App\Models\BankStatementImport;
use App\Models\BankStatementImportTransaction;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\ImportOfxBankStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

function createWalletForOfxImport(): Wallet
{
    $user = User::factory()->create();

    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    $suspense = ChartOfAccount::query()->updateOrCreate(
        [
            'wallet_id' => $wallet->id,
            'code' => '1.1.99',
        ],
        [
            'name' => 'A classificar',
            'type' => 'ativo',
            'normal_balance' => 'debit',
            'allows_posting' => true,
        ],
    );

    $wallet->update(['suspense_account_id' => $suspense->id]);

    return $wallet->fresh();
}

function sampleOfxContent(): string
{
    return <<<'OFX'
OFXHEADER:100
DATA:OFXSGML
VERSION:102
SECURITY:NONE
ENCODING:USASCII
CHARSET:1252
COMPRESSION:NONE
OLDFILEUID:NONE
NEWFILEUID:NONE

<OFX>
<BANKMSGSRSV1>
<STMTTRNRS>
<STMTRS>
<BANKTRANLIST>
<DTSTART>20260701000000[-3:BRT]
<DTEND>20260731000000[-3:BRT]
<STMTTRN>
<TRNTYPE>DEBIT
<DTPOSTED>20260710120000[-3:BRT]
<TRNAMT>-125.90
<FITID>TX-001
<NAME>Mercado Central
<MEMO>Compra no mercado
</STMTTRN>
<STMTTRN>
<TRNTYPE>CREDIT
<DTPOSTED>20260711120000[-3:BRT]
<TRNAMT>3500.00
<FITID>TX-002
<NAME>Cliente Alpha
<MEMO>Recebimento PIX
</STMTTRN>
</BANKTRANLIST>
</STMTRS>
</STMTTRNRS>
</BANKMSGSRSV1>
</OFX>
OFX;
}

function singleOfxTransaction(
    string $fitId,
    string $date,
    int $amountCents,
    string $direction = 'out',
): string {
    $ofxDate = str_replace('-', '', $date);
    $signedAmount = $direction === 'in' ? $amountCents : -$amountCents;
    $amount = number_format($signedAmount / 100, 2, '.', '');
    $transactionType = $direction === 'in' ? 'CREDIT' : 'DEBIT';

    return <<<OFX
OFXHEADER:100
DATA:OFXSGML
VERSION:102
SECURITY:NONE
ENCODING:USASCII
CHARSET:1252
COMPRESSION:NONE
OLDFILEUID:NONE
NEWFILEUID:NONE

<OFX>
<BANKMSGSRSV1>
<STMTTRNRS>
<STMTRS>
<BANKTRANLIST>
<DTSTART>{$ofxDate}000000[-3:BRT]
<DTEND>{$ofxDate}235959[-3:BRT]
<STMTTRN>
<TRNTYPE>{$transactionType}
<DTPOSTED>{$ofxDate}120000[-3:BRT]
<TRNAMT>{$amount}
<FITID>{$fitId}
<NAME>Movimento {$fitId}
<MEMO>Teste de conciliacao automatica
</STMTTRN>
</BANKTRANLIST>
</STMTRS>
</STMTTRNRS>
</BANKMSGSRSV1>
</OFX>
OFX;
}

function createManualBankMovement(
    Wallet $wallet,
    BankAccount $bankAccount,
    string $date,
    int $amountCents,
    string $direction,
    string $description = 'Movimento manual',
): array {
    $counterpart = AccountingTestHelper::account(
        $wallet,
        '9.9.99',
        'Contrapartida de teste',
        'despesa',
        'debit',
    );

    $bankLineType = $direction === 'in' ? 'debit' : 'credit';
    $counterpartType = $direction === 'in' ? 'credit' : 'debit';

    $entry = JournalEntry::query()->create([
        'wallet_id' => $wallet->id,
        'source' => 'manual',
        'entry_date' => $date,
        'description' => $description,
        'status' => 'posted',
        'posted_at' => now(),
        'is_balanced' => true,
        'balance_diff_cents' => 0,
    ]);

    $bankLine = JournalLine::query()->create([
        'journal_entry_id' => $entry->id,
        'chart_of_account_id' => $bankAccount->chart_of_account_id,
        'type' => $bankLineType,
        'amount_cents' => $amountCents,
    ]);

    JournalLine::query()->create([
        'journal_entry_id' => $entry->id,
        'chart_of_account_id' => $counterpart->id,
        'type' => $counterpartType,
        'amount_cents' => $amountCents,
    ]);

    return [$entry, $bankLine];
}

it('imports OFX transactions as draft journal entries using suspense account', function () {
    $wallet = createWalletForOfxImport();

    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.001',
        name: 'Banco Principal',
    );

    $import = app(ImportOfxBankStatement::class)->execute(
        wallet: $wallet,
        bankAccount: $bankAccount,
        contents: sampleOfxContent(),
        originalFilename: 'extrato.ofx',
    );

    expect($import->total_transactions)->toBe(2)
        ->and($import->imported_transactions)->toBe(2)
        ->and($import->skipped_duplicates)->toBe(0)
        ->and($import->total_in_cents)->toBe(350000)
        ->and($import->total_out_cents)->toBe(12590)
        ->and($import->statement_started_at->toDateString())->toBe('2026-07-01')
        ->and($import->statement_ended_at->toDateString())->toBe('2026-07-31');

    expect(JournalEntry::query()->count())->toBe(2)
        ->and(JournalLine::query()->count())->toBe(4)
        ->and(BankStatementImportTransaction::query()->where('status', 'imported')->count())->toBe(2)
        ->and(BankStatementImportTransaction::query()->whereNull('journal_line_id')->count())->toBe(0);

    $outEntry = JournalEntry::query()
        ->where('external_id', 'ofx:bank-account:'.$bankAccount->id.':TX-001')
        ->firstOrFail();

    expect($outEntry->source)->toBe('ofx')
        ->and($outEntry->status)->toBe('draft')
        ->and($outEntry->is_balanced)->toBeTrue()
        ->and($outEntry->balance_diff_cents)->toBe(0);

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $outEntry->id,
        'chart_of_account_id' => $bankAccount->chart_of_account_id,
        'type' => 'credit',
        'amount_cents' => 12590,
    ]);

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $outEntry->id,
        'chart_of_account_id' => $wallet->suspense_account_id,
        'type' => 'debit',
        'amount_cents' => 12590,
    ]);

    $inEntry = JournalEntry::query()
        ->where('external_id', 'ofx:bank-account:'.$bankAccount->id.':TX-002')
        ->firstOrFail();

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $inEntry->id,
        'chart_of_account_id' => $bankAccount->chart_of_account_id,
        'type' => 'debit',
        'amount_cents' => 350000,
    ]);

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $inEntry->id,
        'chart_of_account_id' => $wallet->suspense_account_id,
        'type' => 'credit',
        'amount_cents' => 350000,
    ]);
});

it('automatically matches one manual movement with the same bank account, date, amount and direction', function () {
    $wallet = createWalletForOfxImport();

    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.001',
        name: 'Banco Principal',
    );

    [$manualEntry, $manualBankLine] = createManualBankMovement(
        wallet: $wallet,
        bankAccount: $bankAccount,
        date: '2026-07-10',
        amountCents: 12590,
        direction: 'out',
    );

    $import = app(ImportOfxBankStatement::class)->execute(
        wallet: $wallet,
        bankAccount: $bankAccount,
        contents: singleOfxTransaction('MATCH-001', '2026-07-10', 12590, 'out'),
        originalFilename: 'match.ofx',
    );

    $auditTransaction = $import->transactions()->sole();

    expect($import->imported_transactions)->toBe(1)
        ->and($import->skipped_duplicates)->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(1)
        ->and(JournalLine::query()->count())->toBe(2)
        ->and($auditTransaction->status)->toBe('imported')
        ->and($auditTransaction->journal_entry_id)->toBe($manualEntry->id)
        ->and($auditTransaction->journal_line_id)->toBe($manualBankLine->id)
        ->and($manualEntry->fresh()->source)->toBe('manual')
        ->and(JournalEntry::query()->where('source', 'ofx')->exists())->toBeFalse();
});

it('does not automatically match a manual movement when any bank matching key differs', function () {
    $wallet = createWalletForOfxImport();

    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.001',
        name: 'Banco Principal',
    );

    $otherBankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.002',
        name: 'Banco Secundario',
    );

    $cases = [
        'bank account' => [
            'fit_id' => 'MISMATCH-ACCOUNT',
            'ofx_date' => '2026-07-12',
            'ofx_amount' => 10100,
            'ofx_direction' => 'out',
            'manual_bank_account' => $otherBankAccount,
            'manual_date' => '2026-07-12',
            'manual_amount' => 10100,
            'manual_direction' => 'out',
        ],
        'date' => [
            'fit_id' => 'MISMATCH-DATE',
            'ofx_date' => '2026-07-14',
            'ofx_amount' => 20200,
            'ofx_direction' => 'out',
            'manual_bank_account' => $bankAccount,
            'manual_date' => '2026-07-13',
            'manual_amount' => 20200,
            'manual_direction' => 'out',
        ],
        'amount' => [
            'fit_id' => 'MISMATCH-AMOUNT',
            'ofx_date' => '2026-07-16',
            'ofx_amount' => 30300,
            'ofx_direction' => 'out',
            'manual_bank_account' => $bankAccount,
            'manual_date' => '2026-07-16',
            'manual_amount' => 30301,
            'manual_direction' => 'out',
        ],
        'direction' => [
            'fit_id' => 'MISMATCH-DIRECTION',
            'ofx_date' => '2026-07-18',
            'ofx_amount' => 40400,
            'ofx_direction' => 'out',
            'manual_bank_account' => $bankAccount,
            'manual_date' => '2026-07-18',
            'manual_amount' => 40400,
            'manual_direction' => 'in',
        ],
    ];

    foreach ($cases as $name => $case) {
        [$manualEntry] = createManualBankMovement(
            wallet: $wallet,
            bankAccount: $case['manual_bank_account'],
            date: $case['manual_date'],
            amountCents: $case['manual_amount'],
            direction: $case['manual_direction'],
            description: 'Candidato divergente: '.$name,
        );

        $import = app(ImportOfxBankStatement::class)->execute(
            wallet: $wallet,
            bankAccount: $bankAccount,
            contents: singleOfxTransaction(
                $case['fit_id'],
                $case['ofx_date'],
                $case['ofx_amount'],
                $case['ofx_direction'],
            ),
            originalFilename: $case['fit_id'].'.ofx',
        );

        $auditTransaction = $import->transactions()->sole();
        $ofxEntry = $auditTransaction->journalEntry;

        expect($auditTransaction->journal_entry_id, $name)->not->toBe($manualEntry->id)
            ->and($auditTransaction->journal_line_id, $name)->not->toBeNull()
            ->and($ofxEntry->source, $name)->toBe('ofx')
            ->and($ofxEntry->status, $name)->toBe('draft');

        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $ofxEntry->id,
            'chart_of_account_id' => $wallet->suspense_account_id,
            'amount_cents' => $case['ofx_amount'],
        ]);
    }
});

it('does not automatically match when more than one manual movement is eligible', function () {
    $wallet = createWalletForOfxImport();

    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.001',
        name: 'Banco Principal',
    );

    [$firstManualEntry] = createManualBankMovement($wallet, $bankAccount, '2026-07-20', 50500, 'in', 'Candidato 1');
    [$secondManualEntry] = createManualBankMovement($wallet, $bankAccount, '2026-07-20', 50500, 'in', 'Candidato 2');

    $import = app(ImportOfxBankStatement::class)->execute(
        wallet: $wallet,
        bankAccount: $bankAccount,
        contents: singleOfxTransaction('AMBIGUOUS-001', '2026-07-20', 50500, 'in'),
        originalFilename: 'ambiguo.ofx',
    );

    $auditTransaction = $import->transactions()->sole();

    expect($auditTransaction->journal_entry_id)->not->toBeIn([$firstManualEntry->id, $secondManualEntry->id])
        ->and($auditTransaction->journal_line_id)->not->toBeNull()
        ->and($auditTransaction->journalEntry->source)->toBe('ofx')
        ->and($auditTransaction->journalEntry->status)->toBe('draft')
        ->and(JournalEntry::query()->count())->toBe(3);

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $auditTransaction->journal_entry_id,
        'chart_of_account_id' => $wallet->suspense_account_id,
        'amount_cents' => 50500,
    ]);
});

it('detects a reimport through the audit transaction and reuses the original manual link', function () {
    $wallet = createWalletForOfxImport();

    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.001',
        name: 'Banco Principal',
    );

    [$manualEntry, $manualBankLine] = createManualBankMovement(
        $wallet,
        $bankAccount,
        '2026-07-22',
        60600,
        'out',
    );

    $firstImport = app(ImportOfxBankStatement::class)->execute(
        wallet: $wallet,
        bankAccount: $bankAccount,
        contents: singleOfxTransaction('REIMPORT-001', '2026-07-22', 60600, 'out'),
        originalFilename: 'extrato.ofx',
    );

    $secondImport = app(ImportOfxBankStatement::class)->execute(
        wallet: $wallet,
        bankAccount: $bankAccount,
        contents: singleOfxTransaction('REIMPORT-001', '2026-07-22', 60600, 'out'),
        originalFilename: 'extrato-repetido.ofx',
    );

    $firstAuditTransaction = $firstImport->transactions()->sole();
    $duplicateAuditTransaction = $secondImport->transactions()->sole();

    expect($secondImport->total_transactions)->toBe(1)
        ->and($secondImport->imported_transactions)->toBe(0)
        ->and($secondImport->skipped_duplicates)->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(1)
        ->and(BankStatementImport::query()->count())->toBe(2)
        ->and(BankStatementImportTransaction::query()->count())->toBe(2)
        ->and($firstAuditTransaction->status)->toBe('imported')
        ->and($firstAuditTransaction->journal_entry_id)->toBe($manualEntry->id)
        ->and($firstAuditTransaction->journal_line_id)->toBe($manualBankLine->id)
        ->and($duplicateAuditTransaction->status)->toBe('skipped_duplicate')
        ->and($duplicateAuditTransaction->journal_entry_id)->toBe($manualEntry->id)
        ->and($duplicateAuditTransaction->journal_line_id)->toBe($manualBankLine->id);
});

it('imports OFX from the bank statement flow and redirects back to the statement', function () {
    $wallet = createWalletForOfxImport();

    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.001',
        name: 'Banco Principal',
    );

    $response = $this
        ->actingAs($wallet->user)
        ->withSession(['active_wallet' => $wallet->id])
        ->post(route('ofx-imports.store'), [
            'bank_account_id' => $bankAccount->id,
            'ofx_file' => UploadedFile::fake()->createWithContent('extrato.ofx', sampleOfxContent()),
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('bank-accounts.statement', $bankAccount));
});
