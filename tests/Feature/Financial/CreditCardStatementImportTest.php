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
use App\Services\Financial\ResolveCreditCardStatementTarget;
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
    $preview = app(PreviewCreditCardStatement::class)->execute($wallet, $card, $csv, 'fatura_2026-06-08.csv');
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
    $preview = app(PreviewCreditCardStatement::class)->execute($wallet, $card, $csv, 'fatura_2026-06-08.csv');
    $decisions = [['row_key' => $preview['rows'][0]['row_key'], 'action' => 'create']];
    $result = app(ConfirmCreditCardStatement::class)->execute($wallet, $card, $preview, $csv, 'fatura_2026-06-08.csv', $decisions);

    $purchase = CreditCardTransaction::query()->firstOrFail();
    expect($result['created'])->toBe(1)
        ->and($purchase->status)->toBe('draft')
        ->and($purchase->expense_account_id)->toBe($suspense->id)
        ->and($purchase->credit_card_invoice_id)->not->toBeNull()
        ->and(JournalEntry::query()->firstOrFail()->status)->toBe('draft');
    $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $purchase->journal_entry_id, 'chart_of_account_id' => $suspense->id, 'type' => 'debit', 'amount_cents' => 10001]);
    $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $purchase->journal_entry_id, 'chart_of_account_id' => $card->liability_account_id, 'type' => 'credit', 'amount_cents' => 10001]);
    expect(app(PreviewCreditCardStatement::class)->execute($wallet, $card, $csv, 'fatura_2026-06-08.csv')['summary']['already_imported'])->toBe(1);
});

it('links an imported purchase to a child card by its last four digits', function () {
    ['wallet' => $wallet, 'card' => $card] = creditCardImportContext();
    $child = app(CreateCreditCard::class)->execute($wallet, new CreditCardDTO(
        name: 'Nubank Virtual 1234', issuerName: 'Nubank', network: 'mastercard', cardType: 'virtual',
        closingDay: 0, dueDay: 0, bestPurchaseDay: 0, creditLimitCents: 0,
        parentCardId: $card->id, lastFour: '1234',
    ));
    $ofx = '<OFX><SIGNONMSGSRSV1><SONRS><FI><ORG>NUBANK</FI></SONRS></SIGNONMSGSRSV1><CREDITCARDMSGSRSV1><CCSTMTTRNRS><CCSTMTRS><CURDEF>BRL<CCACCTFROM><ACCTID>1234</CCACCTFROM><BANKTRANLIST><DTSTART>20260601<DTEND>20260630<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20260605<TRNAMT>-10.00<FITID>child-1<NAME>Compra Virtual</STMTTRN></BANKTRANLIST></CCSTMTRS></CCSTMTTRNRS></CREDITCARDMSGSRSV1></OFX>';
    $preview = app(PreviewCreditCardStatement::class)->execute($wallet, $card, $ofx, 'fatura_2026-06-08.ofx');
    $decisions = [['row_key' => $preview['rows'][0]['row_key'], 'action' => 'create']];

    app(ConfirmCreditCardStatement::class)->execute($wallet, $card, $preview, $ofx, 'fatura_2026-06-08.ofx', $decisions);

    $purchase = CreditCardTransaction::query()->firstOrFail();
    expect($preview['rows'][0]['credit_card_id'])->toBe($child->id)
        ->and($purchase->credit_card_id)->toBe($child->id)
        ->and($purchase->creditCardInvoice->credit_card_id)->toBe($card->id);
    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $purchase->journal_entry_id,
        'chart_of_account_id' => $card->liability_account_id,
        'type' => 'credit',
        'amount_cents' => 1000,
    ]);
});

it('imports purchases from different months into one sovereign file invoice', function () {
    ['wallet' => $wallet, 'card' => $card] = creditCardImportContext();
    $csv = "date,title,amount\n2026-06-01,Compra Junho,10.00\n2026-07-01,Compra Julho,20.00\n";
    $preview = app(PreviewCreditCardStatement::class)->execute($wallet, $card, $csv, 'Nubank_2026-07-08.csv');
    $decisions = collect($preview['rows'])->map(fn (array $row) => ['row_key' => $row['row_key'], 'action' => 'create'])->all();

    app(ConfirmCreditCardStatement::class)->execute($wallet, $card, $preview, $csv, 'Nubank_2026-07-08.csv', $decisions);

    expect($preview['target_invoice']['reference'])->toBe('07/2026')
        ->and($preview['target_invoice']['nominal_due_at'])->toBe('2026-07-08')
        ->and(\App\Models\CreditCardInvoice::query()->count())->toBe(1)
        ->and(\App\Models\CreditCardInvoice::query()->firstOrFail()->reference_month)->toBe(7)
        ->and(CreditCardTransaction::query()->pluck('credit_card_invoice_id')->unique())->toHaveCount(1)
        ->and(CreditCardTransaction::query()->pluck('purchase_date')->map->toDateString()->all())->toBe(['2026-06-01', '2026-07-01']);
});

it('requires an explicit invoice target when statement metadata and filename are inconclusive', function () {
    ['wallet' => $wallet, 'card' => $card] = creditCardImportContext();
    $csv = "date,title,amount\n2026-06-01,Compra,10.00\n";
    $preview = app(PreviewCreditCardStatement::class)->execute($wallet, $card, $csv, 'fatura.csv');
    $decisions = [['row_key' => $preview['rows'][0]['row_key'], 'action' => 'create']];

    expect($preview['target_invoice'])->toBeNull()
        ->and(fn () => app(ConfirmCreditCardStatement::class)->execute($wallet, $card, $preview, $csv, 'fatura.csv', $decisions))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('prioritizes a detected due date when resolving the statement invoice target', function () {
    ['card' => $card] = creditCardImportContext();

    $target = app(ResolveCreditCardStatementTarget::class)->execute(
        $card,
        ['due_date' => '2026-07-08', 'reference_year' => 2026, 'reference_month' => 6],
        'arquivo-sem-data.pdf',
    );

    expect($target['reference'])->toBe('07/2026')
        ->and($target['nominal_due_at'])->toBe('2026-07-08')
        ->and($target['source'])->toBe('due_date');
});

it('parses a sanitized Nubank PDF text layout', function () {
    $text = file_get_contents(base_path('tests/Fixtures/financial/nubank-credit-card-statement.txt'));
    $transactions = app(ParseNubankCreditCardPdf::class)->parse($text);
    $purchases = collect($transactions)->where('direction', 'out');
    $credits = collect($transactions)->where('direction', 'in');

    expect($transactions)->toHaveCount(18)
        ->and($purchases)->toHaveCount(17)
        ->and($purchases->sum('amountCents'))->toBe(144889)
        ->and($credits)->toHaveCount(1)
        ->and($credits->first()->description)->toContain('Pagamento em')
        ->and($transactions[0]->postedAt)->toBe('2026-06-01')
        ->and(collect($transactions)->pluck('description')->implode('|'))
        ->not->toContain('a 01 JUL')
        ->not->toContain('CLIENTE SANITIZADO')
        ->not->toContain('Total a pagar');
});

it('renders an accessible statement file selector with defensive preview states', function () {
    $component = file_get_contents(resource_path('js/components/financial/creditCards/CreditCardStatementImport.vue'));

    expect($component)
        ->toContain('Selecionar arquivo')
        ->toContain('Nenhum arquivo selecionado')
        ->toContain(':disabled="!file || upload.processing"')
        ->toContain('showPreview.value = false')
        ->toContain('upload.clearErrors()')
        ->toContain('total_cents: Number(props.preview.summary?.total_cents ?? 0)')
        ->toContain('ignored_items: Array.isArray(props.preview.ignored_items)')
        ->toContain('O PDF foi lido, mas nenhuma compra da fatura foi reconhecida.');
});
