<?php

use App\DTOs\Financial\CreditCardDTO;
use App\Models\Bank;
use App\Models\ChartOfAccount;
use App\Models\CreditCardInstallmentPlan;
use App\Models\CreditCardInstallmentPlanItem;
use App\Models\CreditCardTransaction;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Accounting\AssessJournalEntryPostingReadiness;
use App\Services\Accounting\PostJournalEntry;
use App\Services\Financial\ClassifyCreditCardPurchase;
use App\Services\Financial\ConfirmCreditCardStatement;
use App\Services\Financial\CreateCreditCard;
use App\Services\Financial\ParseNubankCreditCardPdf;
use App\Services\Financial\PreviewCreditCardStatement;
use App\Services\Financial\ResolveCreditCardStatementTarget;
use App\Services\Financial\SuggestCreditCardPurchaseClassification;
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
        ->and($preview['summary']['installments_pending'])->toBe(1)
        ->and($preview['rows'][0]['situation'])->toBe('installment_detected')
        ->and($preview['rows'][0]['installment_number'])->toBe(1)
        ->and($preview['rows'][0]['installments_total'])->toBe(3);

    $ofx = '<OFX><SIGNONMSGSRSV1><SONRS><FI><ORG>NUBANK</FI></SONRS></SIGNONMSGSRSV1><CREDITCARDMSGSRSV1><CCSTMTTRNRS><CCSTMTRS><CURDEF>BRL<CCACCTFROM><ACCTID>1234</CCACCTFROM><BANKTRANLIST><DTSTART>20260601<DTEND>20260630<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20260605<TRNAMT>-10.00<FITID>safe-1<NAME>Compra Segura</STMTTRN></BANKTRANLIST></CCSTMTRS></CCSTMTTRNRS></CREDITCARDMSGSRSV1></OFX>';
    expect(app(PreviewCreditCardStatement::class)->execute($wallet, $card, $ofx, 'fatura.ofx')['summary']['new'])->toBe(1);
});

it('blocks bank statement files in the credit card preview without creating financial records', function (string $contents, string $filename) {
    ['wallet' => $wallet, 'card' => $card] = creditCardImportContext();

    expect(fn () => app(PreviewCreditCardStatement::class)->execute($wallet, $card, $contents, $filename))
        ->toThrow(\Illuminate\Validation\ValidationException::class, 'Arquivo incompatível: extrato bancário detectado')
        ->and(CreditCardTransaction::query()->count())->toBe(0)
        ->and(CreditCardInstallmentPlan::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);
})->with([
    'bank OFX' => ['<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKACCTFROM><BANKID>260<ACCTID>123</BANKACCTFROM><BANKTRANLIST></BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>', 'extrato.ofx'],
    'bank CSV' => ["data,descricao,valor,saldo\n01/07/2026,PIX,10.00,100.00", 'extrato.csv'],
    'bank PDF' => ["%PDF-1.4\n(Extrato de conta saldo inicial saldo final agencia conta entradas saidas) Tj\n%%EOF", 'extrato.pdf'],
]);

it('detects supported installment descriptions and removes the marker from the base description', function (string $description, int $number, int $total) {
    $detected = app(\App\Services\Financial\DetectCreditCardInstallment::class)->execute($description);

    expect($detected)->not->toBeNull()
        ->and($detected['installment_number'])->toBe($number)
        ->and($detected['installments_total'])->toBe($total)
        ->and($detected['description_base'])->toBe('Havan Guaíba')
        ->and($detected['normalized_description'])->toBe('havan guaiba');
})->with([
    ['Havan Guaíba - Parcela 2/5', 2, 5],
    ['Havan Guaíba 02/10', 2, 10],
    ['Havan Guaíba 3 de 12', 3, 12],
    ['Havan Guaíba Parcela 3 de 12', 3, 12],
]);

it('requires resolution before importing a detected installment', function () {
    ['wallet' => $wallet, 'card' => $card] = creditCardImportContext();
    $csv = "date,title,amount\n2026-07-05,Havan Guaiba Parcela 2/5,19.99\n";
    $preview = app(PreviewCreditCardStatement::class)->execute($wallet, $card, $csv, 'fatura_2026-07-08.csv');

    expect(fn () => app(ConfirmCreditCardStatement::class)->execute(
        $wallet, $card, $preview, $csv, 'fatura_2026-07-08.csv',
        [['row_key' => $preview['rows'][0]['row_key'], 'action' => 'create']],
    ))->toThrow(\Illuminate\Validation\ValidationException::class);

    expect(CreditCardInstallmentPlan::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('keeps an imported plan operationally pending until it has a valid classification', function () {
    ['wallet' => $wallet, 'card' => $card] = creditCardImportContext();
    $csv = "date,title,amount\n2026-07-05,Havan Guaiba Parcela 2/5,19.99\n";
    $preview = app(PreviewCreditCardStatement::class)->execute($wallet, $card, $csv, 'fatura_2026-07-08.csv');
    $row = $preview['rows'][0];

    expect(fn () => app(ConfirmCreditCardStatement::class)->execute(
        $wallet, $card, $preview, $csv, 'fatura_2026-07-08.csv',
        [['row_key' => $row['row_key'], 'action' => 'confirm_plan']],
    ))->toThrow(\Illuminate\Validation\ValidationException::class);

    app(ConfirmCreditCardStatement::class)->execute(
        $wallet, $card, $preview, $csv, 'fatura_2026-07-08.csv',
        [['row_key' => $row['row_key'], 'action' => 'pending_plan']],
    );

    expect(CreditCardInstallmentPlan::query()->value('status'))->toBe('pending_confirmation')
        ->and(CreditCardInstallmentPlan::query()->value('recognition_journal_entry_id'))->toBeNull()
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('recognizes an in-progress installment plan once and keeps previous and future items financial only', function () {
    ['wallet' => $wallet, 'card' => $card] = creditCardImportContext();
    $expense = ChartOfAccount::query()->where('wallet_id', $wallet->id)
        ->where('type', 'despesa')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $csv = "date,title,amount\n2026-07-05,Havan Guaiba Parcela 2/5,19.99\n";
    $preview = app(PreviewCreditCardStatement::class)->execute($wallet, $card, $csv, 'fatura_2026-07-08.csv');
    $row = $preview['rows'][0];
    app(ConfirmCreditCardStatement::class)->execute(
        $wallet, $card, $preview, $csv, 'fatura_2026-07-08.csv',
        [[
            'row_key' => $row['row_key'], 'action' => 'confirm_plan',
            'classification_account_id' => $expense->id,
            'recognized_total_cents' => 7996,
            'installments' => collect(range(1, 5))->map(fn ($number) => [
                'installment_number' => $number, 'amount_cents' => 1999,
            ])->all(),
        ]],
    );

    $plan = CreditCardInstallmentPlan::query()->with('items')->firstOrFail();
    expect($plan->started_before_erp)->toBeTrue()
        ->and($plan->recognized_from_installment)->toBe(2)
        ->and($plan->recognized_total_cents)->toBe(7996)
        ->and($plan->items)->toHaveCount(5)
        ->and($plan->items->firstWhere('installment_number', 1)->status)->toBe('previous_before_erp')
        ->and($plan->items->firstWhere('installment_number', 2)->status)->toBe('matched')
        ->and($plan->items->firstWhere('installment_number', 3)->status)->toBe('expected')
        ->and(JournalEntry::query()->count())->toBe(1);
    $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $plan->recognition_journal_entry_id, 'chart_of_account_id' => $expense->id, 'type' => 'debit', 'amount_cents' => 7996]);
    $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $plan->recognition_journal_entry_id, 'chart_of_account_id' => $card->liability_account_id, 'type' => 'credit', 'amount_cents' => 7996]);
});

it('matches the next invoice installment without a new plan or expense entry', function () {
    ['wallet' => $wallet, 'card' => $card] = creditCardImportContext();
    $expense = ChartOfAccount::query()->where('wallet_id', $wallet->id)
        ->where('type', 'despesa')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $firstCsv = "date,title,amount\n2026-07-05,Havan Guaiba Parcela 2/5,19.99\n";
    $first = app(PreviewCreditCardStatement::class)->execute($wallet, $card, $firstCsv, 'fatura_2026-07-08.csv');
    app(ConfirmCreditCardStatement::class)->execute($wallet, $card, $first, $firstCsv, 'fatura_2026-07-08.csv', [[
        'row_key' => $first['rows'][0]['row_key'], 'action' => 'confirm_plan',
        'classification_account_id' => $expense->id, 'recognized_total_cents' => 7996,
    ]]);

    $nextCsv = "date,title,amount\n2026-08-05,Havan Guaíba - Parcela 3/5,19.99\n";
    $next = app(PreviewCreditCardStatement::class)->execute($wallet, $card, $nextCsv, 'fatura_2026-08-08.csv');
    expect($next['rows'][0]['situation'])->toBe('installment_matched');
    app(ConfirmCreditCardStatement::class)->execute($wallet, $card, $next, $nextCsv, 'fatura_2026-08-08.csv', [[
        'row_key' => $next['rows'][0]['row_key'], 'action' => 'link_plan',
        'plan_id' => $next['rows'][0]['installment_plan_matches'][0]['id'],
    ]]);

    expect(CreditCardInstallmentPlan::query()->count())->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(1)
        ->and(CreditCardTransaction::query()->count())->toBe(2)
        ->and(CreditCardInstallmentPlanItem::query()->where('installment_number', 3)->value('status'))->toBe('matched')
        ->and(CreditCardTransaction::query()->latest('id')->value('expense_account_id'))->toBe($expense->id)
        ->and(CreditCardTransaction::query()->latest('id')->value('journal_entry_id'))
        ->toBe(CreditCardInstallmentPlan::query()->value('recognition_journal_entry_id'));

    $reimport = app(PreviewCreditCardStatement::class)->execute($wallet, $card, $nextCsv, 'fatura_2026-08-08.csv');
    expect($reimport['rows'][0]['situation'])->toBe('already_imported');
    app(ConfirmCreditCardStatement::class)->execute($wallet, $card, $reimport, $nextCsv, 'fatura_2026-08-08.csv', [[
        'row_key' => $reimport['rows'][0]['row_key'], 'action' => 'ignore',
    ]]);
    expect(CreditCardInstallmentPlan::query()->count())->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(1)
        ->and(CreditCardTransaction::query()->count())->toBe(2);
});

it('links a divergent expected installment for review without a new plan or journal entry', function () {
    ['wallet' => $wallet, 'card' => $card] = creditCardImportContext();
    $expense = ChartOfAccount::query()->where('wallet_id', $wallet->id)
        ->where('type', 'despesa')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $firstCsv = "date,title,amount\n2026-07-05,Munhoz Pneus - Parcela 2/3,262.66\n";
    $first = app(PreviewCreditCardStatement::class)->execute($wallet, $card, $firstCsv, 'fatura_2026-07-08.csv');
    app(ConfirmCreditCardStatement::class)->execute($wallet, $card, $first, $firstCsv, 'fatura_2026-07-08.csv', [[
        'row_key' => $first['rows'][0]['row_key'], 'action' => 'confirm_plan',
        'classification_account_id' => $expense->id, 'recognized_total_cents' => 52532,
    ]]);

    $nextCsv = "date,title,amount\n2026-08-05,Munhoz Pneus Parcela 3 de 3,263.00\n";
    $next = app(PreviewCreditCardStatement::class)->execute($wallet, $card, $nextCsv, 'fatura_2026-08-08.csv');
    expect($next['rows'][0]['situation'])->toBe('installment_divergent')
        ->and($next['rows'][0]['installment_plan_matches'][0]['expected_amount_cents'])->toBe(26266)
        ->and($next['rows'][0]['default_action'])->toBe('resolve_divergence');
    app(ConfirmCreditCardStatement::class)->execute($wallet, $card, $next, $nextCsv, 'fatura_2026-08-08.csv', [[
        'row_key' => $next['rows'][0]['row_key'], 'action' => 'reconcile_divergence',
        'plan_id' => $next['rows'][0]['installment_plan_matches'][0]['id'],
        'expected_amount_cents' => 26300, 'recognized_total_cents' => 52566,
    ]]);

    expect(CreditCardInstallmentPlan::query()->count())->toBe(1)
        ->and(CreditCardInstallmentPlan::query()->value('status'))->toBe('completed')
        ->and(CreditCardInstallmentPlan::query()->value('recognized_total_cents'))->toBe(52566)
        ->and(JournalEntry::query()->count())->toBe(1)
        ->and(JournalEntry::query()->firstOrFail()->lines()->pluck('amount_cents')->unique()->all())->toBe([52566])
        ->and(CreditCardInstallmentPlanItem::query()->where('installment_number', 3)->value('status'))->toBe('matched');

    $reimport = app(PreviewCreditCardStatement::class)->execute($wallet, $card, $nextCsv, 'fatura_2026-08-08.csv');
    expect($reimport['rows'][0]['situation'])->toBe('already_imported')
        ->and(CreditCardInstallmentPlan::query()->count())->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(1);
});

it('links a future installment to a pending plan without duplicating recognition', function () {
    ['wallet' => $wallet, 'card' => $card] = creditCardImportContext();
    $firstCsv = "date,title,amount\n2026-07-05,Munhoz Pneus - Parcela 2/3,262.66\n";
    $first = app(PreviewCreditCardStatement::class)->execute($wallet, $card, $firstCsv, 'fatura_2026-07-08.csv');
    app(ConfirmCreditCardStatement::class)->execute($wallet, $card, $first, $firstCsv, 'fatura_2026-07-08.csv', [[
        'row_key' => $first['rows'][0]['row_key'], 'action' => 'pending_plan',
    ]]);

    $nextCsv = "date,title,amount\n2026-08-05,Munhoz Pneus Parcela 3 de 3,262.66\n";
    $next = app(PreviewCreditCardStatement::class)->execute($wallet, $card, $nextCsv, 'fatura_2026-08-08.csv');
    expect($next['rows'][0]['situation'])->toBe('installment_plan_pending')
        ->and($next['rows'][0]['default_action'])->toBe('link_pending_plan');
    app(ConfirmCreditCardStatement::class)->execute($wallet, $card, $next, $nextCsv, 'fatura_2026-08-08.csv', [[
        'row_key' => $next['rows'][0]['row_key'], 'action' => 'link_pending_plan',
        'plan_id' => $next['rows'][0]['installment_plan_matches'][0]['id'],
    ]]);

    expect(CreditCardInstallmentPlan::query()->count())->toBe(1)
        ->and(CreditCardInstallmentPlan::query()->value('status'))->toBe('pending_confirmation')
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(CreditCardInstallmentPlanItem::query()->where('installment_number', 3)->value('status'))->toBe('possible_match');
});

it('keeps posted installment recognition immutable while allowing the divergent invoice item to be linked', function () {
    ['wallet' => $wallet, 'card' => $card] = creditCardImportContext();
    $expense = ChartOfAccount::query()->where('wallet_id', $wallet->id)
        ->where('type', 'despesa')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $firstCsv = "date,title,amount\n2026-07-05,Loja Teste Parcela 2/3,20.03\n";
    $first = app(PreviewCreditCardStatement::class)->execute($wallet, $card, $firstCsv, 'fatura_2026-07-08.csv');
    app(ConfirmCreditCardStatement::class)->execute($wallet, $card, $first, $firstCsv, 'fatura_2026-07-08.csv', [[
        'row_key' => $first['rows'][0]['row_key'], 'action' => 'confirm_plan',
        'classification_account_id' => $expense->id, 'recognized_total_cents' => 4006,
    ]]);
    $plan = CreditCardInstallmentPlan::query()->firstOrFail();
    app(PostJournalEntry::class)->handle($plan->recognitionJournalEntry);

    $nextCsv = "date,title,amount\n2026-08-05,Loja Teste Parcela 3/3,19.99\n";
    $next = app(PreviewCreditCardStatement::class)->execute($wallet, $card, $nextCsv, 'fatura_2026-08-08.csv');
    expect(fn () => app(ConfirmCreditCardStatement::class)->execute($wallet, $card, $next, $nextCsv, 'fatura_2026-08-08.csv', [[
        'row_key' => $next['rows'][0]['row_key'], 'action' => 'reconcile_divergence', 'plan_id' => $plan->id,
        'expected_amount_cents' => 1999, 'recognized_total_cents' => 4002,
    ]]))->toThrow(\Illuminate\Validation\ValidationException::class, 'já foi contabilizado');

    app(ConfirmCreditCardStatement::class)->execute($wallet, $card, $next, $nextCsv, 'fatura_2026-08-08.csv', [[
        'row_key' => $next['rows'][0]['row_key'], 'action' => 'reconcile_divergence', 'plan_id' => $plan->id,
        'expected_amount_cents' => 1999, 'recognized_total_cents' => 4006,
    ]]);
    expect($plan->fresh()->recognized_total_cents)->toBe(4006)
        ->and($plan->recognitionJournalEntry->fresh()->status)->toBe('posted')
        ->and(CreditCardInstallmentPlanItem::query()->where('installment_number', 3)->value('status'))->toBe('matched')
        ->and(JournalEntry::query()->count())->toBe(1);
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

it('classifies only the suspense debit and makes the credit card purchase ready for accounting', function () {
    ['wallet' => $wallet, 'card' => $card] = creditCardImportContext();
    $csv = "date,title,amount\n2026-06-05,Mercado Central,100.01\n";
    $preview = app(PreviewCreditCardStatement::class)->execute($wallet, $card, $csv, 'fatura-classificar.csv');
    app(ConfirmCreditCardStatement::class)->execute(
        $wallet,
        $card,
        $preview,
        $csv,
        'fatura-classificar.csv',
        [['row_key' => $preview['rows'][0]['row_key'], 'action' => 'create']],
        2026,
        6,
    );
    $purchase = CreditCardTransaction::query()->firstOrFail();
    $expense = ChartOfAccount::query()->where('wallet_id', $wallet->id)
        ->where('type', 'despesa')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();

    app(ClassifyCreditCardPurchase::class)->execute($wallet, $purchase, $expense->id);
    $entry = $purchase->journalEntry()->with('lines.chartOfAccount.children')->firstOrFail();

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $entry->id,
        'chart_of_account_id' => $expense->id,
        'type' => 'debit',
        'amount_cents' => 10001,
    ]);
    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $entry->id,
        'chart_of_account_id' => $card->liability_account_id,
        'type' => 'credit',
        'amount_cents' => 10001,
    ]);
    expect(app(AssessJournalEntryPostingReadiness::class)->handle($wallet, $entry)->ready)->toBeTrue();
});

it('suggests a high confidence classification after two consistent historical purchases', function () {
    ['wallet' => $wallet, 'card' => $card] = creditCardImportContext();
    $expense = ChartOfAccount::query()->where('wallet_id', $wallet->id)
        ->where('type', 'despesa')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();

    foreach ([5, 12, 19] as $index => $day) {
        $csv = sprintf("date,title,amount\n2026-06-%02d,Mercado Central,10.00\n", $day);
        $filename = "historico-{$index}.csv";
        $preview = app(PreviewCreditCardStatement::class)->execute($wallet, $card, $csv, $filename);
        app(ConfirmCreditCardStatement::class)->execute(
            $wallet,
            $card,
            $preview,
            $csv,
            $filename,
            [['row_key' => $preview['rows'][0]['row_key'], 'action' => 'create']],
            2026,
            6,
        );
    }

    $purchases = CreditCardTransaction::query()->orderBy('purchase_date')->get();
    app(ClassifyCreditCardPurchase::class)->execute($wallet, $purchases[0], $expense->id);
    app(ClassifyCreditCardPurchase::class)->execute($wallet, $purchases[1], $expense->id);
    $suggestion = app(SuggestCreditCardPurchaseClassification::class)->execute($wallet, $purchases[2]);

    expect($suggestion)
        ->not->toBeNull()
        ->and($suggestion['chart_of_account_id'])->toBe($expense->id)
        ->and($suggestion['confidence'])->toBe('high')
        ->and($suggestion['history_count'])->toBe(2)
        ->and($suggestion['can_bulk_apply'])->toBeTrue();
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

it('renders installment amounts as BRL fields inside a review modal instead of raw-cent labels', function () {
    $component = file_get_contents(resource_path('js/components/financial/creditCards/CreditCardStatementImport.vue'));
    $plans = file_get_contents(resource_path('js/pages/Financial/CreditCards/Show.vue'));

    expect($component)
        ->toContain('Revisar parcelamento')
        ->toContain('Confirmar parcelamento')
        ->toContain('formatCurrency(reviewingDecision.recognized_total_cents)')
        ->toContain('formatCurrency(item.amount_cents)')
        ->toContain('Conciliada com parcelamento existente')
        ->toContain('Reconhecimento contábil já realizado no plano.')
        ->toContain('Valor esperado:')
        ->toContain('Recalcular parcelas')
        ->toContain('Ajustar diferença na primeira parcela')
        ->toContain('Ajustar diferença na última parcela')
        ->toContain('Confirmar conciliação')
        ->toContain('O parcelamento já foi contabilizado.')
        ->not->toContain('Valor a reconhecer (centavos)')
        ->and($plans)
        ->toContain('Valor reconhecido:')
        ->toContain('Valor futuro:')
        ->toContain('Fatura vinculada')
        ->toContain('Reconhecida no plano');
});
