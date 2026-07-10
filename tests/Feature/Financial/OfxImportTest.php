<?php

use App\Models\BankStatementImport;
use App\Models\BankStatementImportTransaction;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\ImportOfxBankStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

function createWalletForOfxImport(): Wallet
{
    $user = User::factory()->create();

    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    $suspense = ChartOfAccount::query()->create([
        'wallet_id' => $wallet->id,
        'code' => '1.1.99',
        'name' => 'A classificar',
        'type' => 'ativo',
        'normal_balance' => 'debit',
        'allows_posting' => true,
    ]);

    $wallet->update(['suspense_account_id' => $suspense->id]);

    return $wallet->fresh();
}

function sampleOfxContent(): string
{
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
        ->and(BankStatementImportTransaction::query()->where('status', 'imported')->count())->toBe(2);

    $outEntry = JournalEntry::query()
        ->where('external_id', 'ofx:bank-account:' . $bankAccount->id . ':TX-001')
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
        ->where('external_id', 'ofx:bank-account:' . $bankAccount->id . ':TX-002')
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

it('skips duplicated OFX transactions by external id', function () {
    $wallet = createWalletForOfxImport();

    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.001',
        name: 'Banco Principal',
    );

    app(ImportOfxBankStatement::class)->execute(
        wallet: $wallet,
        bankAccount: $bankAccount,
        contents: sampleOfxContent(),
        originalFilename: 'extrato.ofx',
    );

    $secondImport = app(ImportOfxBankStatement::class)->execute(
        wallet: $wallet,
        bankAccount: $bankAccount,
        contents: sampleOfxContent(),
        originalFilename: 'extrato-repetido.ofx',
    );

    expect($secondImport->total_transactions)->toBe(2)
        ->and($secondImport->imported_transactions)->toBe(0)
        ->and($secondImport->skipped_duplicates)->toBe(2)
        ->and(JournalEntry::query()->count())->toBe(2)
        ->and(BankStatementImport::query()->count())->toBe(2)
        ->and(BankStatementImportTransaction::query()->where('status', 'skipped_duplicate')->count())->toBe(2);
});
