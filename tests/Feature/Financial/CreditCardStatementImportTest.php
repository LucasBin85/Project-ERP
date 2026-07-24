<?php

use App\DTOs\Financial\CreditCardDTO;
use App\Models\Bank;
use App\Models\ChartOfAccount;
use App\Models\CreditCardTransaction;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\ConfirmCreditCardStatement;
use App\Services\Financial\CreateCreditCard;
use App\Services\Financial\ParseNubankCreditCardPdf;
use App\Services\Financial\PreviewCreditCardStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function creditCardImportContext(): array
{
    $wallet = Wallet::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Importação']);
    $asset = ChartOfAccount::query()->where('wallet_id', $wallet->id)->where('code', '1')->firstOrFail();
    $suspense = ChartOfAccount::query()->firstOrCreate([
        'wallet_id' => $wallet->id, 'code' => '1.9.999',
    ], [
        'parent_id' => $asset->id, 'name' => 'A classificar',
        'type' => 'ativo', 'normal_balance' => 'debit', 'allows_posting' => true,
    ]);
    $wallet->update(['suspense_account_id' => $suspense->id]);
    Bank::query()->firstOrCreate(['code' => '999'], [
        'name' => 'Nubank', 'short_name' => 'Nubank', 'ispb' => '99999999', 'active' => true,
    ]);
    $card = app(CreateCreditCard::class)->execute($wallet, new CreditCardDTO(
        name: 'Nubank', issuerName: 'Nubank', network: 'mastercard', cardType: 'main',
        closingDay: 1, dueDay: 8, bestPurchaseDay: 2, creditLimitCents: 100000,
    ));

    return compact('wallet', 'suspense', 'card');
}

it('previews OFX and CSV credit card statements with installments', function () {
    ['wallet' => $wallet, 'card' => $card] = creditCardImportContext();
    $csv = "date,title,amount\n2026-06-05,Compra Sanitizada 1/3,100.01\n";
    $preview = app(PreviewCreditCardStatement::class)->execute($wallet, $card, $csv, 'fatura.csv');
    expect($preview['format'])->toBe('CSV')
        ->and($preview['summary']['new'])->toBe(1)
        ->and($preview['rows'][0]['installment_number'])->toBe(1)
        ->and($preview['rows'][0]['installments_total'])->toBe(3);

    $ofx = '<OFX><SIGNONMSGSRSV1><SONRS><FI><ORG>NUBANK</FI></SONRS></SIGNONMSGSRSV1><CREDITCARDMSGSRSV1><CCSTMTTRNRS><CCSTMTRS><CURDEF>BRL<CCACCTFROM><ACCTID>1234</CCACCTFROM><BANKTRANLIST><DTSTART>20260601<DTEND>20260630<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20260605<TRNAMT>-10.00<FITID>safe-1<NAME>Compra Segura</STMTTRN></BANKTRANLIST></CCSTMTRS></CCSTMTTRNRS></CREDITCARDMSGSRSV1></OFX>';
    expect(app(PreviewCreditCardStatement::class)->execute($wallet, $card, $ofx, 'fatura.ofx')['summary']['new'])->toBe(1);
});

it('confirms statement purchases as deduplicated drafts without moving a bank account', function () {
    ['wallet' => $wallet, 'suspense' => $suspense, 'card' => $card] = creditCardImportContext();
    $csv = "date,title,amount\n2026-06-05,Compra Sanitizada,100.01\n";
    $preview = app(PreviewCreditCardStatement::class)->execute($wallet, $card, $csv, 'fatura.csv');
    $decisions = [['row_key' => $preview['rows'][0]['row_key'], 'action' => 'create']];
    $result = app(ConfirmCreditCardStatement::class)->execute($wallet, $card, $preview, $csv, 'fatura.csv', $decisions);

    $purchase = CreditCardTransaction::query()->firstOrFail();
    expect($result['created'])->toBe(1)
        ->and($purchase->status)->toBe('draft')
        ->and($purchase->expense_account_id)->toBe($suspense->id)
        ->and($purchase->credit_card_invoice_id)->not->toBeNull()
        ->and(JournalEntry::query()->firstOrFail()->status)->toBe('draft');
    $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $purchase->journal_entry_id, 'chart_of_account_id' => $suspense->id, 'type' => 'debit', 'amount_cents' => 10001]);
    $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $purchase->journal_entry_id, 'chart_of_account_id' => $card->liability_account_id, 'type' => 'credit', 'amount_cents' => 10001]);
    expect(app(PreviewCreditCardStatement::class)->execute($wallet, $card, $csv, 'fatura.csv')['summary']['already_imported'])->toBe(1);
});

it('parses a sanitized Nubank PDF text layout', function () {
    $text = file_get_contents(base_path('tests/Fixtures/financial/nubank-credit-card-statement.txt'));
    $transactions = app(ParseNubankCreditCardPdf::class)->parse($text);

    expect($transactions)->toHaveCount(2)
        ->and($transactions[0]->amountCents)->toBe(10001)
        ->and($transactions[0]->postedAt)->toBe('2026-06-05');
});
