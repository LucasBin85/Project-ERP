<?php

use App\DTOs\Financial\OfxImportResultDTO;
use App\Exceptions\OfxImportException;
use App\Models\BankAccount;
use App\Models\BankStatementImport;
use App\Models\BankStatementImportTransaction;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Accounting\CreateBankImportEntry;
use App\Services\Financial\ConfirmOfxBankStatement;
use App\Services\Financial\ParseOfxStatement;
use App\Services\Financial\PreviewOfxBankStatement;
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
        'name' => 'Carteira Teste OFX',
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
    ?string $fitId,
    string $date,
    int $amountCents,
    string $direction = 'out',
): string {
    $ofxDate = str_replace('-', '', $date);
    $signedAmount = $direction === 'in' ? $amountCents : -$amountCents;
    $amount = number_format($signedAmount / 100, 2, '.', '');
    $transactionType = $direction === 'in' ? 'CREDIT' : 'DEBIT';
    $label = $fitId ?? 'SEM-FITID';
    $fitIdLine = $fitId === null ? '' : "<FITID>{$fitId}\n";

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
{$fitIdLine}<NAME>Movimento {$label}
<MEMO>Teste de conciliacao automatica
</STMTTRN>
</BANKTRANLIST>
</STMTRS>
</STMTTRNRS>
</BANKMSGSRSV1>
</OFX>
OFX;
}

function twoTransactionsWithoutFitId(): string
{
    return <<<'OFX'
OFXHEADER:100
DATA:OFXSGML
VERSION:102
SECURITY:NONE
ENCODING:USASCII

<OFX>
<BANKMSGSRSV1>
<STMTTRNRS>
<STMTRS>
<BANKTRANLIST>
<DTSTART>20260724000000[-3:BRT]
<DTEND>20260724235959[-3:BRT]
<STMTTRN>
<TRNTYPE>CHECK
<DTPOSTED>20260724120000[-3:BRT]
<TRNAMT>-100.00
<NAME>Pagamento sem FITID
<MEMO>Mesmo memo
<PAYEE>Fornecedor A
<CHECKNUM>1001
</STMTTRN>
<STMTTRN>
<TRNTYPE>CHECK
<DTPOSTED>20260724120000[-3:BRT]
<TRNAMT>-100.00
<NAME>Pagamento sem FITID
<MEMO>Mesmo memo
<PAYEE>Fornecedor B
<CHECKNUM>1002
</STMTTRN>
</BANKTRANLIST>
</STMTRS>
</STMTTRNRS>
</BANKMSGSRSV1>
</OFX>
OFX;
}

function multiAccountOfxContent(): string
{
    return <<<'OFX'
OFXHEADER:100
DATA:OFXSGML
VERSION:102
SECURITY:NONE
ENCODING:USASCII

<OFX>
<BANKMSGSRSV1>
<STMTTRNRS>
<STMTRS>
<BANKACCTFROM>
<BANKID>001
<BRANCHID>0001
<ACCTID>11111-1
<ACCTTYPE>CHECKING
</BANKACCTFROM>
<BANKTRANLIST>
<STMTTRN>
<TRNTYPE>DEBIT
<DTPOSTED>20260724120000[-3:BRT]
<TRNAMT>-10.00
<FITID>CONTA-1
<NAME>Conta um
</STMTTRN>
</BANKTRANLIST>
</STMTRS>
</STMTTRNRS>
<STMTTRNRS>
<STMTRS>
<BANKACCTFROM>
<BANKID>001
<BRANCHID>0001
<ACCTID>22222-2
<ACCTTYPE>CHECKING
</BANKACCTFROM>
<BANKTRANLIST>
<STMTTRN>
<TRNTYPE>DEBIT
<DTPOSTED>20260724130000[-3:BRT]
<TRNAMT>-20.00
<FITID>CONTA-2
<NAME>Conta dois
</STMTTRN>
</BANKTRANLIST>
</STMTRS>
</STMTTRNRS>
</BANKMSGSRSV1>
</OFX>
OFX;
}

/** @return array{0: JournalEntry, 1: JournalLine} */
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

/** @return array<string, mixed> */
function previewOfx(
    Wallet $wallet,
    BankAccount $bankAccount,
    string $contents,
    string $filename = 'extrato.ofx',
): array {
    return app(PreviewOfxBankStatement::class)->execute(
        wallet: $wallet,
        bankAccount: $bankAccount,
        contents: $contents,
        originalFilename: $filename,
    );
}

/** @return array<int, array{row_key: string, action: string}> */
function defaultOfxDecisions(array $preview): array
{
    return collect($preview['rows'])
        ->map(fn (array $row) => [
            'row_key' => $row['row_key'],
            'action' => $row['default_action'],
        ])
        ->all();
}

function confirmOfxPreview(
    Wallet $wallet,
    BankAccount $bankAccount,
    string $contents,
    array $preview,
    ?array $decisions = null,
    string $filename = 'extrato.ofx',
): OfxImportResultDTO {
    return app(ConfirmOfxBankStatement::class)->execute(
        wallet: $wallet,
        bankAccount: $bankAccount,
        contents: $contents,
        originalFilename: $filename,
        expectedFileHash: $preview['file_hash'],
        decisions: $decisions ?? defaultOfxDecisions($preview),
        expectedRows: $preview['rows'],
    );
}

it('previews OFX fields and matching situations without creating accounting or audit records', function () {
    $wallet = createWalletForOfxImport();
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.001', 'Banco Principal');

    $preview = previewOfx($wallet, $bankAccount, sampleOfxContent());

    expect($preview['file_name'])->toBe('extrato.ofx')
        ->and($preview['file_hash'])->toHaveLength(64)
        ->and($preview['bank_account_id'])->toBe($bankAccount->id)
        ->and($preview['statement_started_at'])->toBe('2026-07-01')
        ->and($preview['statement_ended_at'])->toBe('2026-07-31')
        ->and($preview['has_errors'])->toBeFalse()
        ->and($preview['summary'])->toBe(['new' => 2])
        ->and($preview['rows'])->toHaveCount(2);

    expect(array_key_exists('operation_types', $preview))->toBeFalse();

    $outflow = $preview['rows'][0];
    $inflow = $preview['rows'][1];

    expect($outflow['date'])->toBe('2026-07-10')
        ->and($outflow['description'])->toBe('Mercado Central - Compra no mercado')
        ->and($outflow['amount_cents'])->toBe(12_590)
        ->and($outflow['signed_amount_cents'])->toBe(-12_590)
        ->and($outflow['direction'])->toBe('out')
        ->and($outflow['situation'])->toBe('new')
        ->and($outflow['default_action'])->toBe('create')
        ->and($outflow['allowed_actions'])->toBe(['create', 'ignore'])
        ->and($inflow['date'])->toBe('2026-07-11')
        ->and($inflow['amount_cents'])->toBe(350_000)
        ->and($inflow['signed_amount_cents'])->toBe(350_000)
        ->and($inflow['direction'])->toBe('in')
        ->and($inflow['situation'])->toBe('new');

    foreach ($preview['rows'] as $row) {
        expect(array_key_exists('raw_type', $row))->toBeFalse()
            ->and(array_key_exists('suggested_operation_type', $row))->toBeFalse()
            ->and(array_key_exists('operation_type', $row))->toBeFalse()
            ->and(array_key_exists('classification_account_id', $row))->toBeFalse()
            ->and(array_key_exists('account_id', $row['suggestion']))->toBeFalse();
    }

    expect(JournalEntry::query()->count())->toBe(0)
        ->and(JournalLine::query()->count())->toBe(0)
        ->and(BankStatementImport::query()->count())->toBe(0)
        ->and(BankStatementImportTransaction::query()->count())->toBe(0);
});

it('confirms new OFX transactions as balanced drafts using the suspense account', function () {
    $wallet = createWalletForOfxImport();
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.001', 'Banco Principal');
    $contents = sampleOfxContent();
    $preview = previewOfx($wallet, $bankAccount, $contents);

    $result = confirmOfxPreview($wallet, $bankAccount, $contents, $preview);
    $import = $result->import;

    expect($result->created)->toBe(2)
        ->and($result->linked)->toBe(0)
        ->and($result->duplicates)->toBe(0)
        ->and($result->ignored)->toBe(0)
        ->and($import->total_transactions)->toBe(2)
        ->and($import->imported_transactions)->toBe(2)
        ->and($import->skipped_duplicates)->toBe(0)
        ->and($import->total_in_cents)->toBe(350_000)
        ->and($import->total_out_cents)->toBe(12_590)
        ->and($import->statement_started_at->toDateString())->toBe('2026-07-01')
        ->and($import->statement_ended_at->toDateString())->toBe('2026-07-31');

    expect(JournalEntry::query()->count())->toBe(2)
        ->and(JournalLine::query()->count())->toBe(4)
        ->and(BankStatementImportTransaction::query()->where('resolution', 'created')->count())->toBe(2)
        ->and(BankStatementImportTransaction::query()->whereNotNull('operation_type')->count())->toBe(0)
        ->and(BankStatementImportTransaction::query()->whereNotNull('classification_account_id')->count())->toBe(0)
        ->and(BankStatementImportTransaction::query()->whereNull('journal_line_id')->count())->toBe(0);

    $outEntry = JournalEntry::query()
        ->where('external_id', 'ofx:bank-account:'.$bankAccount->id.':TX-001')
        ->firstOrFail();
    $inEntry = JournalEntry::query()
        ->where('external_id', 'ofx:bank-account:'.$bankAccount->id.':TX-002')
        ->firstOrFail();

    expect($outEntry->source)->toBe('ofx')
        ->and($outEntry->status)->toBe('draft')
        ->and($outEntry->is_balanced)->toBeTrue()
        ->and($outEntry->balance_diff_cents)->toBe(0)
        ->and($inEntry->status)->toBe('draft')
        ->and($inEntry->is_balanced)->toBeTrue();

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $outEntry->id,
        'chart_of_account_id' => $bankAccount->chart_of_account_id,
        'type' => 'credit',
        'amount_cents' => 12_590,
    ]);
    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $outEntry->id,
        'chart_of_account_id' => $wallet->suspense_account_id,
        'type' => 'debit',
        'amount_cents' => 12_590,
    ]);
    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $inEntry->id,
        'chart_of_account_id' => $bankAccount->chart_of_account_id,
        'type' => 'debit',
        'amount_cents' => 350_000,
    ]);
    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $inEntry->id,
        'chart_of_account_id' => $wallet->suspense_account_id,
        'type' => 'credit',
        'amount_cents' => 350_000,
    ]);
});

it('previews and links exactly one compatible manual movement without duplicating it', function () {
    $wallet = createWalletForOfxImport();
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.001', 'Banco Principal');
    [$manualEntry, $manualBankLine] = createManualBankMovement(
        $wallet,
        $bankAccount,
        '2026-07-10',
        12_590,
        'out',
    );
    $contents = singleOfxTransaction('MATCH-001', '2026-07-10', 12_590, 'out');

    $preview = previewOfx($wallet, $bankAccount, $contents, 'match.ofx');
    $row = $preview['rows'][0];

    expect($row['situation'])->toBe('possible_match')
        ->and($row['default_action'])->toBe('link')
        ->and($row['candidate_count'])->toBe(1)
        ->and($row['suggestion']['journal_entry_id'])->toBe($manualEntry->id)
        ->and($row['suggestion']['journal_line_id'])->toBe($manualBankLine->id);

    $result = confirmOfxPreview($wallet, $bankAccount, $contents, $preview, filename: 'match.ofx');
    $audit = $result->import->transactions()->sole();

    expect($result->created)->toBe(0)
        ->and($result->linked)->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(1)
        ->and(JournalLine::query()->count())->toBe(2)
        ->and($audit->resolution)->toBe('linked')
        ->and($audit->status)->toBe('imported')
        ->and($audit->journal_entry_id)->toBe($manualEntry->id)
        ->and($audit->journal_line_id)->toBe($manualBankLine->id)
        ->and($manualEntry->fresh()->source)->toBe('manual')
        ->and($manualEntry->fresh()->status)->toBe('posted')
        ->and(JournalEntry::query()->where('source', 'ofx')->exists())->toBeFalse();
});

it('requires the same account date amount and direction for an automatic match', function () {
    $wallet = createWalletForOfxImport();
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.001', 'Banco Principal');
    $otherBankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.002', 'Banco Secundario');

    $cases = [
        'bank account' => ['MISMATCH-ACCOUNT', '2026-07-12', 10_100, 'out', $otherBankAccount, '2026-07-12', 10_100, 'out'],
        'date' => ['MISMATCH-DATE', '2026-07-14', 20_200, 'out', $bankAccount, '2026-07-13', 20_200, 'out'],
        'amount' => ['MISMATCH-AMOUNT', '2026-07-16', 30_300, 'out', $bankAccount, '2026-07-16', 30_301, 'out'],
        'direction' => ['MISMATCH-DIRECTION', '2026-07-18', 40_400, 'out', $bankAccount, '2026-07-18', 40_400, 'in'],
    ];

    foreach ($cases as $name => [$fitId, $ofxDate, $ofxAmount, $ofxDirection, $manualBank, $manualDate, $manualAmount, $manualDirection]) {
        [$manualEntry] = createManualBankMovement(
            $wallet,
            $manualBank,
            $manualDate,
            $manualAmount,
            $manualDirection,
            'Candidato divergente: '.$name,
        );
        $contents = singleOfxTransaction($fitId, $ofxDate, $ofxAmount, $ofxDirection);
        $preview = previewOfx($wallet, $bankAccount, $contents, $fitId.'.ofx');

        expect($preview['rows'][0]['situation'], $name)->toBe('new');

        $result = confirmOfxPreview(
            $wallet,
            $bankAccount,
            $contents,
            $preview,
            filename: $fitId.'.ofx',
        );
        $audit = $result->import->transactions()->sole();

        expect($audit->resolution, $name)->toBe('created')
            ->and($audit->journal_entry_id, $name)->not->toBe($manualEntry->id)
            ->and($audit->journalEntry->source, $name)->toBe('ofx')
            ->and($audit->journalEntry->status, $name)->toBe('draft');

        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $audit->journal_entry_id,
            'chart_of_account_id' => $wallet->suspense_account_id,
            'amount_cents' => $ofxAmount,
        ]);
    }
});

it('ignores an ambiguous match by default and only creates when explicitly requested', function () {
    $wallet = createWalletForOfxImport();
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.001', 'Banco Principal');
    [$firstManualEntry] = createManualBankMovement($wallet, $bankAccount, '2026-07-20', 50_500, 'in', 'Candidato 1');
    [$secondManualEntry] = createManualBankMovement($wallet, $bankAccount, '2026-07-20', 50_500, 'in', 'Candidato 2');
    $contents = singleOfxTransaction('AMBIGUOUS-001', '2026-07-20', 50_500, 'in');

    $preview = previewOfx($wallet, $bankAccount, $contents, 'ambiguo.ofx');
    $row = $preview['rows'][0];

    expect($row['situation'])->toBe('ambiguous_match')
        ->and($row['candidate_count'])->toBe(2)
        ->and($row['default_action'])->toBe('ignore')
        ->and($row['allowed_actions'])->toBe(['ignore', 'create']);

    $ignored = confirmOfxPreview($wallet, $bankAccount, $contents, $preview, filename: 'ambiguo.ofx');
    $ignoredAudit = $ignored->import->transactions()->sole();

    expect($ignored->ignored)->toBe(1)
        ->and($ignored->created)->toBe(0)
        ->and($ignoredAudit->resolution)->toBe('ignored')
        ->and($ignoredAudit->status)->toBe('skipped_duplicate')
        ->and($ignoredAudit->journal_entry_id)->toBeNull()
        ->and(JournalEntry::query()->count())->toBe(2);

    $secondPreview = previewOfx($wallet, $bankAccount, $contents, 'ambiguo.ofx');
    $decisions = defaultOfxDecisions($secondPreview);
    $decisions[0]['action'] = 'create';

    $created = confirmOfxPreview(
        $wallet,
        $bankAccount,
        $contents,
        $secondPreview,
        $decisions,
        'ambiguo.ofx',
    );
    $createdAudit = $created->import->transactions()->sole();

    expect($created->created)->toBe(1)
        ->and($createdAudit->resolution)->toBe('kept')
        ->and($createdAudit->journal_entry_id)->not->toBeIn([$firstManualEntry->id, $secondManualEntry->id])
        ->and($createdAudit->journalEntry->source)->toBe('ofx')
        ->and(JournalEntry::query()->count())->toBe(3);

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $createdAudit->journal_entry_id,
        'chart_of_account_id' => $wallet->suspense_account_id,
        'amount_cents' => 50_500,
    ]);
});

it('marks a repeated FITID as already imported without creating another entry', function () {
    $wallet = createWalletForOfxImport();
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.001', 'Banco Principal');
    $contents = singleOfxTransaction('REIMPORT-001', '2026-07-22', 60_600, 'out');

    $firstPreview = previewOfx($wallet, $bankAccount, $contents, 'primeiro.ofx');
    $firstResult = confirmOfxPreview($wallet, $bankAccount, $contents, $firstPreview, filename: 'primeiro.ofx');
    $firstAudit = $firstResult->import->transactions()->sole();

    $secondPreview = previewOfx($wallet, $bankAccount, $contents, 'repetido.ofx');

    expect($secondPreview['rows'][0]['situation'])->toBe('already_imported')
        ->and($secondPreview['rows'][0]['suggestion']['journal_entry_id'])->toBe($firstAudit->journal_entry_id);

    $secondResult = confirmOfxPreview($wallet, $bankAccount, $contents, $secondPreview, filename: 'repetido.ofx');
    $duplicateAudit = $secondResult->import->transactions()->sole();

    expect($secondResult->created)->toBe(0)
        ->and($secondResult->duplicates)->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(1)
        ->and(BankStatementImport::query()->count())->toBe(2)
        ->and(BankStatementImportTransaction::query()->count())->toBe(2)
        ->and($duplicateAudit->resolution)->toBe('duplicate')
        ->and($duplicateAudit->status)->toBe('skipped_duplicate')
        ->and($duplicateAudit->journal_entry_id)->toBe($firstAudit->journal_entry_id)
        ->and($duplicateAudit->journal_line_id)->toBe($firstAudit->journal_line_id);
});

it('uses the canonical transaction hash when FITID is absent', function () {
    $wallet = createWalletForOfxImport();
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.001', 'Banco Principal');
    $contents = singleOfxTransaction(null, '2026-07-23', 71_700, 'in');

    $firstPreview = previewOfx($wallet, $bankAccount, $contents, 'sem-fitid.ofx');

    expect($firstPreview['rows'][0]['has_fit_id'])->toBeFalse()
        ->and($firstPreview['rows'][0]['transaction_hash'])->toHaveLength(64);

    $firstResult = confirmOfxPreview($wallet, $bankAccount, $contents, $firstPreview, filename: 'sem-fitid.ofx');
    $firstAudit = $firstResult->import->transactions()->sole();

    expect($firstAudit->fit_id)->toBeNull()
        ->and($firstAudit->transaction_hash)->toBe($firstPreview['rows'][0]['transaction_hash']);

    $secondPreview = previewOfx($wallet, $bankAccount, $contents, 'sem-fitid-repetido.ofx');
    $secondResult = confirmOfxPreview($wallet, $bankAccount, $contents, $secondPreview, filename: 'sem-fitid-repetido.ofx');

    expect($secondPreview['rows'][0]['situation'])->toBe('already_imported')
        ->and($secondResult->duplicates)->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(1);
});

it('recognizes the legacy external id when reimporting a transaction without FITID', function () {
    $wallet = createWalletForOfxImport();
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.001', 'Banco Principal');
    $contents = singleOfxTransaction(null, '2026-07-25', 42_000, 'out');
    $transaction = app(ParseOfxStatement::class)->parse($contents)['transactions'][0];
    $legacyExternalId = 'ofx:bank-account:'.$bankAccount->id.':'.$transaction->fitId;
    $entry = app(CreateBankImportEntry::class)->handle(
        wallet: $wallet,
        bankAccountId: $bankAccount->chart_of_account_id,
        amountCents: $transaction->amountCents,
        direction: $transaction->direction,
        entryDate: $transaction->postedAt,
        description: $transaction->description,
        source: 'ofx',
        externalId: $legacyExternalId,
        autoPostIfBalanced: false,
    );
    $bankLine = $entry->lines->firstWhere('chart_of_account_id', $bankAccount->chart_of_account_id);
    $import = BankStatementImport::query()->create([
        'wallet_id' => $wallet->id,
        'bank_account_id' => $bankAccount->id,
        'source' => 'ofx',
        'original_filename' => 'legado-sem-fitid.ofx',
        'file_hash' => hash('sha256', 'legado-sem-fitid'),
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
        'external_id' => $legacyExternalId,
        'transaction_hash' => null,
        'fit_id' => null,
        'posted_at' => $transaction->postedAt,
        'description' => $transaction->description,
        'amount_cents' => $transaction->amountCents,
        'direction' => $transaction->direction,
        'status' => 'imported',
        'raw_payload' => $transaction->raw,
    ]);

    $preview = previewOfx($wallet, $bankAccount, $contents, 'legado-reimportado.ofx');
    $result = confirmOfxPreview(
        $wallet,
        $bankAccount,
        $contents,
        $preview,
        filename: 'legado-reimportado.ofx',
    );

    expect($preview['rows'][0]['situation'])->toBe('already_imported')
        ->and($result->created)->toBe(0)
        ->and($result->duplicates)->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(1);
});

it('keeps distinct transactions without FITID when secondary identifiers differ', function () {
    $wallet = createWalletForOfxImport();
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.001', 'Banco Principal');
    $contents = twoTransactionsWithoutFitId();
    $preview = previewOfx($wallet, $bankAccount, $contents, 'sem-fitid-distintos.ofx');

    expect($preview['rows'])->toHaveCount(2)
        ->and(collect($preview['rows'])->pluck('situation')->all())->toBe(['new', 'new'])
        ->and(collect($preview['rows'])->pluck('transaction_hash')->unique())->toHaveCount(2)
        ->and(collect($preview['rows'])->pluck('external_id')->unique())->toHaveCount(2);

    $result = confirmOfxPreview(
        $wallet,
        $bankAccount,
        $contents,
        $preview,
        filename: 'sem-fitid-distintos.ofx',
    );

    expect($result->created)->toBe(2)
        ->and(JournalEntry::query()->count())->toBe(2)
        ->and(BankStatementImportTransaction::query()->count())->toBe(2);
});

it('rejects a multi-account OFX instead of mixing transactions from different accounts', function () {
    $wallet = createWalletForOfxImport();
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.001', 'Banco Principal');

    expect(fn () => previewOfx($wallet, $bankAccount, multiAccountOfxContent(), 'multiplas-contas.ofx'))
        ->toThrow(RuntimeException::class, 'mais de uma conta');

    expect(JournalEntry::query()->count())->toBe(0)
        ->and(BankStatementImport::query()->count())->toBe(0)
        ->and(BankStatementImportTransaction::query()->count())->toBe(0);
});

it('rolls back the whole confirmation when a later row has an invalid decision', function () {
    $wallet = createWalletForOfxImport();
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.001', 'Banco Principal');
    $contents = sampleOfxContent();
    $preview = previewOfx($wallet, $bankAccount, $contents);
    $decisions = [
        [
            'row_key' => $preview['rows'][0]['row_key'],
            'action' => 'create',
        ],
        [
            'row_key' => $preview['rows'][1]['row_key'],
            'action' => 'link',
        ],
    ];

    expect(fn () => confirmOfxPreview($wallet, $bankAccount, $contents, $preview, $decisions))
        ->toThrow(OfxImportException::class);

    expect(BankStatementImport::query()->count())->toBe(0)
        ->and(BankStatementImportTransaction::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(JournalLine::query()->count())->toBe(0);
});

it('requires confirmation decisions for exactly every preview row', function () {
    $wallet = createWalletForOfxImport();
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.001', 'Banco Principal');
    $contents = sampleOfxContent();
    $preview = previewOfx($wallet, $bankAccount, $contents);
    $decisions = defaultOfxDecisions($preview);

    expect(fn () => confirmOfxPreview(
        $wallet,
        $bankAccount,
        $contents,
        $preview,
        [$decisions[0]],
    ))->toThrow(OfxImportException::class);

    $decisions[1]['row_key'] = str_repeat('a', 64);

    expect(fn () => confirmOfxPreview(
        $wallet,
        $bankAccount,
        $contents,
        $preview,
        $decisions,
    ))->toThrow(OfxImportException::class);

    expect(BankStatementImport::query()->count())->toBe(0)
        ->and(BankStatementImportTransaction::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(JournalLine::query()->count())->toBe(0);
});

it('rejects an invalid suspense account before previewing or writing records', function () {
    $wallet = createWalletForOfxImport();
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.001', 'Banco Principal');
    $wallet->suspenseAccount()->update(['allows_posting' => false]);

    expect(fn () => previewOfx($wallet, $bankAccount, sampleOfxContent()))
        ->toThrow(OfxImportException::class);

    expect(BankStatementImport::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('keeps confirmation atomic when the preview contains an invalid OFX row', function () {
    $wallet = createWalletForOfxImport();
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.001', 'Banco Principal');
    $contents = str_replace('<TRNAMT>3500.00', '', sampleOfxContent());
    $preview = previewOfx($wallet, $bankAccount, $contents);

    expect(collect($preview['rows'])->pluck('situation')->all())
        ->toBe(['new', 'error'])
        ->and($preview['has_errors'])->toBeTrue();

    expect(fn () => confirmOfxPreview($wallet, $bankAccount, $contents, $preview))
        ->toThrow(OfxImportException::class);

    expect(BankStatementImport::query()->count())->toBe(0)
        ->and(BankStatementImportTransaction::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(JournalLine::query()->count())->toBe(0);
});

it('previews and confirms through a wallet-bound cached token and prevents token replay', function () {
    $wallet = createWalletForOfxImport();
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.001', 'Banco Principal');

    $previewResponse = $this
        ->actingAs($wallet->user)
        ->withSession(['active_wallet' => $wallet->id])
        ->post(route('ofx-imports.preview'), [
            'bank_account_id' => $bankAccount->id,
            'ofx_file' => UploadedFile::fake()->createWithContent('extrato.ofx', sampleOfxContent()),
        ]);

    $previewResponse
        ->assertSessionHasNoErrors()
        ->assertSessionHas('ofx_preview')
        ->assertRedirect(route('bank-accounts.statement', $bankAccount));

    $storedPreview = session('ofx_preview');

    expect($storedPreview)->toBeArray()
        ->and($storedPreview['token'])->toHaveLength(64)
        ->and($storedPreview['rows'])->toHaveCount(2)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(BankStatementImport::query()->count())->toBe(0);

    $payload = [
        'preview_token' => $storedPreview['token'],
        'rows' => defaultOfxDecisions($storedPreview),
    ];

    $confirmResponse = $this->post(route('ofx-imports.confirm'), $payload);

    $confirmResponse
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'OFX importado: 2 novos, 0 vinculados, 0 duplicados ignorados.')
        ->assertRedirect(route('bank-accounts.statement', $bankAccount));

    expect(JournalEntry::query()->count())->toBe(2)
        ->and(BankStatementImport::query()->count())->toBe(1);

    $this->post(route('ofx-imports.confirm'), $payload)
        ->assertSessionHasErrors('preview_token');

    expect(JournalEntry::query()->count())->toBe(2)
        ->and(BankStatementImport::query()->count())->toBe(1);
});
