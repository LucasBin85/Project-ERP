<?php

use App\DTOs\Financial\BankReconciliationDTO;
use App\Models\BankReconciliationStatementItem;
use App\Models\BankStatementImportTransaction;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\BankReconciliationPreviewService;
use App\Services\Financial\BuildOfxReconciliationStatementItems;
use App\Services\Financial\CreateBankReconciliation;
use App\Services\Financial\ImportOfxBankStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

function createWalletForOfxReconciliation(): Wallet
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

function sampleOfxContentForReconciliation(): string
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
<FITID>REC-001
<NAME>Mercado Central
<MEMO>Compra no mercado
</STMTTRN>
<STMTTRN>
<TRNTYPE>CREDIT
<DTPOSTED>20260711120000[-3:BRT]
<TRNAMT>3500.00
<FITID>REC-002
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

it('builds reconciliation statement items from imported OFX transactions and completes matches', function () {
    $wallet = createWalletForOfxReconciliation();

    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.001',
        name: 'Banco Principal',
    );

    app(ImportOfxBankStatement::class)->execute(
        wallet: $wallet,
        bankAccount: $bankAccount,
        contents: sampleOfxContentForReconciliation(),
        originalFilename: 'extrato-conciliacao.ofx',
    );

    JournalEntry::query()
        ->where('wallet_id', $wallet->id)
        ->where('source', 'ofx')
        ->update([
            'status' => 'posted',
            'posted_at' => now(),
        ]);

    $preview = app(BankReconciliationPreviewService::class)->build(
        wallet: $wallet,
        bankAccount: $bankAccount,
        periodStart: '2026-07-01',
        periodEnd: '2026-07-31',
    );

    $ofxItems = app(BuildOfxReconciliationStatementItems::class)->build(
        wallet: $wallet,
        bankAccount: $bankAccount,
        periodStart: '2026-07-01',
        periodEnd: '2026-07-31',
        availableLineIds: collect($preview['lines'])->pluck('id')->all(),
    );

    expect($ofxItems)->toHaveCount(2)
        ->and(collect($ofxItems)->pluck('journal_line_id')->filter())->toHaveCount(2)
        ->and(collect($ofxItems)->sum('amount_cents'))->toBe(337410);

    $reconciliation = app(CreateBankReconciliation::class)->execute(
        $wallet,
        new BankReconciliationDTO(
            bankAccountId: $bankAccount->id,
            periodStart: '2026-07-01',
            periodEnd: '2026-07-31',
            statementBalanceCents: 0,
            statementItems: collect($ofxItems)
                ->map(fn (array $item) => [
                    'bank_statement_import_transaction_id' => $item['bank_statement_import_transaction_id'],
                    'transaction_date' => $item['transaction_date'],
                    'description' => $item['description'],
                    'amount_cents' => $item['amount_cents'],
                    'journal_line_id' => $item['journal_line_id'],
                ])
                ->all(),
        ),
    );

    expect($reconciliation->status)->toBe('completed')
        ->and($reconciliation->statement_balance_cents)->toBe(337410)
        ->and($reconciliation->reconciled_balance_cents)->toBe(337410)
        ->and($reconciliation->difference_cents)->toBe(0)
        ->and($reconciliation->statementItems)->toHaveCount(2)
        ->and($reconciliation->statementItems->pluck('status')->unique()->values()->all())->toBe(['reconciled']);

    expect(BankReconciliationStatementItem::query()->whereNotNull('bank_statement_import_transaction_id')->count())->toBe(2)
        ->and(BankStatementImportTransaction::query()->where('status', 'imported')->count())->toBe(2);
});
