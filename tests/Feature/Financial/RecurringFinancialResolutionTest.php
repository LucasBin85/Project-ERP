<?php

use App\DTOs\Financial\AccountPayableDTO;
use App\DTOs\Financial\AccountReceivableDTO;
use App\DTOs\Financial\PayAccountPayableDTO;
use App\DTOs\Financial\RecurringFinancialExpectationDTO;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\BankStatementImport;
use App\Models\BankStatementImportTransaction;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\RecurringFinancialExpectation;
use App\Models\RecurringFinancialOccurrence;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\CreateBankImportEntry;
use App\Services\Accounting\PostJournalEntry;
use App\Services\Financial\CancelAccountPayable;
use App\Services\Financial\ConfirmRecurringFinancialExpectation;
use App\Services\Financial\CreateAccountPayable;
use App\Services\Financial\CreateAccountReceivable;
use App\Services\Financial\CreateRecurringAccountPayable;
use App\Services\Financial\CreateRecurringAccountReceivable;
use App\Services\Financial\CreateRecurringFinancialExpectation;
use App\Services\Financial\EstimateRecurringFinancialExpectationAmount;
use App\Services\Financial\LinkAccountPayableFromBankStatement;
use App\Services\Financial\ListRecurringFinancialExpectationsForRange;
use App\Services\Financial\OfxOperationTypePolicy;
use App\Services\Financial\PayAccountPayable;
use App\Services\Financial\ReverseAccountPayableSettlement;
use App\Services\Financial\SkipRecurringFinancialExpectation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

function recurringResolutionContext(): array
{
    $wallet = User::factory()->create()->wallets()->firstOrFail();
    $expenseA = $wallet->chartOfAccounts()->where('type', 'despesa')->where('allows_posting', true)
        ->whereDoesntHave('children')->firstOrFail();
    $revenueA = $wallet->chartOfAccounts()->where('type', 'receita')->where('allows_posting', true)
        ->whereDoesntHave('children')->firstOrFail();
    $expenses = collect([
        $expenseA,
        AccountingTestHelper::account($wallet, '5.9.998', 'Internet e Telefonia', 'despesa', 'debit'),
    ]);
    $revenues = collect([
        $revenueA,
        AccountingTestHelper::account($wallet, '4.9.998', 'Receita Contratual', 'receita', 'credit'),
    ]);
    $payableControl = $wallet->chartOfAccounts()->where('financial_group', 'accounts_payable')
        ->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $receivableControl = $wallet->chartOfAccounts()->where('financial_group', 'accounts_receivable')
        ->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $supplier = Supplier::query()->create([
        'wallet_id' => $wallet->id, 'name' => 'Vivo', 'payable_account_id' => $payableControl->id,
        'default_expense_account_id' => $expenses[0]->id, 'active' => true,
    ]);
    $customer = Customer::query()->create([
        'wallet_id' => $wallet->id, 'name' => 'Cliente Contrato', 'receivable_account_id' => $receivableControl->id,
        'default_revenue_account_id' => $revenues[0]->id, 'active' => true,
    ]);

    return compact('wallet', 'expenses', 'revenues', 'payableControl', 'receivableControl', 'supplier', 'customer');
}

function recurringDto(array $overrides = []): RecurringFinancialExpectationDTO
{
    $context = $overrides['context'] ?? recurringResolutionContext();
    unset($overrides['context']);
    $attributes = array_merge([
        'type' => 'payable', 'description' => 'Internet Escritório', 'frequency' => 'monthly',
        'dueDay' => 10, 'amountMode' => 'variable', 'expectedAmountCents' => null,
        'defaultAccountId' => $context['expenses'][1]->id, 'startsOn' => '2026-01-23',
        'supplierId' => $context['supplier']->id,
    ], $overrides);

    return new RecurringFinancialExpectationDTO(...$attributes);
}

function recurringExpectation(array $overrides = []): RecurringFinancialExpectation
{
    $context = $overrides['context'] ?? recurringResolutionContext();

    return app(CreateRecurringFinancialExpectation::class)->execute(
        $context['wallet'],
        recurringDto(array_merge(['context' => $context], $overrides)),
    );
}

function addConfirmedOccurrence(RecurringFinancialExpectation $expectation, string $period, int $amount): void
{
    RecurringFinancialOccurrence::query()->create([
        'wallet_id' => $expectation->wallet_id,
        'recurring_financial_expectation_id' => $expectation->id,
        'period_date' => $period,
        'due_date' => $period,
        'actual_amount_cents' => $amount,
        'status' => 'confirmed',
        'confirmed_at' => now(),
    ]);
}

it('keeps normal payable and receivable account precedence backward compatible', function () {
    $context = recurringResolutionContext();
    $payable = app(CreateAccountPayable::class)->execute($context['wallet'], new AccountPayableDTO(
        expenseAccountId: $context['expenses'][1]->id, payeeName: 'Ignored', description: 'AP normal',
        dueDate: '2026-08-10', amountCents: 10000, supplierId: $context['supplier']->id,
    ));
    $receivable = app(CreateAccountReceivable::class)->execute($context['wallet'], new AccountReceivableDTO(
        revenueAccountId: $context['revenues'][1]->id, customerName: 'Ignored', description: 'AR normal',
        dueDate: '2026-08-10', amountCents: 10000, customerId: $context['customer']->id,
    ));

    expect($payable->expense_account_id)->toBe($context['expenses'][0]->id)
        ->and($receivable->revenue_account_id)->toBe($context['revenues'][0]->id);
});

it('preserves a confirmed recurring occurrence and its snapshots after title cancellation', function () {
    $context = recurringResolutionContext();
    $expectation = recurringExpectation(['context' => $context, 'amountMode' => 'fixed', 'expectedAmountCents' => 25_000]);
    $occurrence = app(ConfirmRecurringFinancialExpectation::class)->execute(
        $context['wallet'], $expectation, CarbonImmutable::parse('2026-08-01'), 25_000,
    );
    $snapshotKeys = ['status', 'period_date', 'due_date', 'expected_amount_cents', 'actual_amount_cents', 'account_payable_id'];
    $snapshot = collect($occurrence->fresh()->attributesToArray())->only($snapshotKeys)->all();

    app(CancelAccountPayable::class)->execute(
        $context['wallet'], $occurrence->accountPayable, User::query()->findOrFail($context['wallet']->user_id), 'Serviço encerrado',
    );

    expect(collect($occurrence->fresh()->attributesToArray())->only($snapshotKeys)->all())->toBe($snapshot)
        ->and($occurrence->fresh()->status)->toBe('confirmed')
        ->and($occurrence->fresh()->account_payable_id)->toBe($snapshot['account_payable_id'])
        ->and($occurrence->accountPayable->fresh()->status)->toBe('cancelled')
        ->and(RecurringFinancialOccurrence::query()->count())->toBe(1)
        ->and(app(ListRecurringFinancialExpectationsForRange::class)->execute(
            $context['wallet'], 'payable', CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'),
        ))->toBeEmpty();
});

it('preserves recurring history when a posted provision is cancelled through reversal', function () {
    $context = recurringResolutionContext();
    $expectation = recurringExpectation(['context' => $context, 'amountMode' => 'fixed', 'expectedAmountCents' => 25_000]);
    $occurrence = app(ConfirmRecurringFinancialExpectation::class)->execute(
        $context['wallet'], $expectation, CarbonImmutable::parse('2026-08-01'), 25_000,
    );
    $snapshotKeys = ['status', 'period_date', 'due_date', 'expected_amount_cents', 'actual_amount_cents', 'account_payable_id'];
    $snapshot = collect($occurrence->fresh()->attributesToArray())->only($snapshotKeys)->all();
    app(PostJournalEntry::class)->handle($occurrence->accountPayable->provisionJournalEntry);

    app(CancelAccountPayable::class)->execute(
        $context['wallet'], $occurrence->accountPayable, User::query()->findOrFail($context['wallet']->user_id),
        'Serviço encerrado após contabilização', '2026-08-20',
    );

    expect(collect($occurrence->fresh()->attributesToArray())->only($snapshotKeys)->all())->toBe($snapshot)
        ->and($occurrence->accountPayable->fresh()->cancellationJournalEntry->status)->toBe('posted')
        ->and(RecurringFinancialOccurrence::query()->count())->toBe(1)
        ->and($expectation->fresh()->status)->toBe($expectation->status)
        ->and(app(ListRecurringFinancialExpectationsForRange::class)->execute(
            $context['wallet'], 'payable', CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'),
        ))->toBeEmpty();
});

it('preserves recurring history when a manual settlement is reversed', function () {
    $context = recurringResolutionContext();
    $expectation = recurringExpectation(['context' => $context, 'amountMode' => 'fixed', 'expectedAmountCents' => 25_000]);
    $occurrence = app(ConfirmRecurringFinancialExpectation::class)->execute(
        $context['wallet'], $expectation, CarbonImmutable::parse('2026-08-01'), 25_000,
    );
    $snapshotKeys = ['status', 'period_date', 'due_date', 'expected_amount_cents', 'actual_amount_cents', 'account_payable_id'];
    $snapshot = collect($occurrence->fresh()->attributesToArray())->only($snapshotKeys)->all();
    $bank = FinancialTestHelper::bankAccount($context['wallet'], '1.1.2.991', 'Banco recurring reversal');
    app(PayAccountPayable::class)->execute(
        $context['wallet'], $occurrence->accountPayable, new PayAccountPayableDTO($bank->id, '2026-08-15'),
    );

    app(ReverseAccountPayableSettlement::class)->execute(
        $context['wallet'], $occurrence->accountPayable, User::query()->findOrFail($context['wallet']->user_id), 'Pagamento corrigido',
    );

    expect(collect($occurrence->fresh()->attributesToArray())->only($snapshotKeys)->all())->toBe($snapshot)
        ->and($occurrence->accountPayable->fresh()->status)->toBe('pending')
        ->and($occurrence->accountPayable->settlementReversals()->count())->toBe(1)
        ->and(RecurringFinancialOccurrence::query()->count())->toBe(1)
        ->and(app(ListRecurringFinancialExpectationsForRange::class)->execute(
            $context['wallet'], 'payable', CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'),
        ))->toBeEmpty();
});

it('preserves recurring history when a bank settlement is unlinked', function () {
    $context = recurringResolutionContext();
    $expectation = recurringExpectation(['context' => $context, 'amountMode' => 'fixed', 'expectedAmountCents' => 25_000]);
    $occurrence = app(ConfirmRecurringFinancialExpectation::class)->execute(
        $context['wallet'], $expectation, CarbonImmutable::parse('2026-08-01'), 25_000,
    );
    $snapshotKeys = ['status', 'period_date', 'due_date', 'expected_amount_cents', 'actual_amount_cents', 'account_payable_id'];
    $snapshot = collect($occurrence->fresh()->attributesToArray())->only($snapshotKeys)->all();
    $bank = FinancialTestHelper::bankAccount($context['wallet'], '1.1.2.992', 'Banco recurring bank reversal');
    $import = BankStatementImport::query()->create([
        'wallet_id' => $context['wallet']->id, 'bank_account_id' => $bank->id, 'source' => 'ofx',
        'original_filename' => 'recurring-bank.ofx', 'file_hash' => hash('sha256', 'recurring-bank'), 'status' => 'completed',
    ]);
    $entry = app(CreateBankImportEntry::class)->handle(
        $context['wallet'], $bank->chart_of_account_id, 25_000, 'out', '2026-08-15',
        'Recurring bank settlement', 'ofx', 'recurring-bank-settlement', false,
    );
    $bankLine = $entry->lines->firstWhere('chart_of_account_id', $bank->chart_of_account_id);
    BankStatementImportTransaction::query()->create([
        'bank_statement_import_id' => $import->id, 'wallet_id' => $context['wallet']->id, 'bank_account_id' => $bank->id,
        'journal_entry_id' => $entry->id, 'journal_line_id' => $bankLine->id, 'external_id' => 'RECURRING-BANK',
        'transaction_hash' => hash('sha256', 'recurring-bank-audit'), 'posted_at' => '2026-08-15',
        'description' => 'Recurring bank settlement', 'amount_cents' => 25_000, 'direction' => 'out',
        'operation_type' => OfxOperationTypePolicy::PAYMENT, 'status' => 'imported', 'resolution' => 'created',
    ]);
    app(LinkAccountPayableFromBankStatement::class)->execute(
        $context['wallet'], $bank, $entry, $occurrence->accountPayable,
    );

    app(ReverseAccountPayableSettlement::class)->execute(
        $context['wallet'], $occurrence->accountPayable, User::query()->findOrFail($context['wallet']->user_id), 'Vínculo bancário corrigido',
    );

    expect(collect($occurrence->fresh()->attributesToArray())->only($snapshotKeys)->all())->toBe($snapshot)
        ->and($occurrence->accountPayable->fresh()->status)->toBe('pending')
        ->and(RecurringFinancialOccurrence::query()->count())->toBe(1)
        ->and(app(ListRecurringFinancialExpectationsForRange::class)->execute(
            $context['wallet'], 'payable', CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'),
        ))->toBeEmpty();
});

it('confirms payable using the recurring expense and supplier control accounts', function () {
    $context = recurringResolutionContext();
    $expectation = recurringExpectation(['context' => $context, 'expectedAmountCents' => 18000]);
    $occurrence = app(ConfirmRecurringFinancialExpectation::class)->execute(
        $context['wallet'], $expectation, CarbonImmutable::parse('2026-08-18'), 20000,
    );
    $payable = $occurrence->accountPayable;

    expect($payable->supplier_id)->toBe($context['supplier']->id)
        ->and($payable->expense_account_id)->toBe($context['expenses'][1]->id)
        ->and($payable->payable_account_id)->toBe($context['payableControl']->id)
        ->and($occurrence->expected_amount_cents)->toBe(18000)
        ->and($occurrence->actual_amount_cents)->toBe(20000);
    $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $payable->provision_journal_entry_id,
        'chart_of_account_id' => $context['expenses'][1]->id, 'type' => 'debit', 'amount_cents' => 20000]);
    $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $payable->provision_journal_entry_id,
        'chart_of_account_id' => $context['payableControl']->id, 'type' => 'credit', 'amount_cents' => 20000]);
});

it('confirms receivable using the recurring revenue and customer control accounts', function () {
    $context = recurringResolutionContext();
    $expectation = recurringExpectation([
        'context' => $context, 'type' => 'receivable', 'supplierId' => null,
        'customerId' => $context['customer']->id, 'defaultAccountId' => $context['revenues'][1]->id,
    ]);
    $occurrence = app(ConfirmRecurringFinancialExpectation::class)->execute(
        $context['wallet'], $expectation, CarbonImmutable::parse('2026-08-01'), 25000,
    );
    $receivable = $occurrence->accountReceivable;

    expect($receivable->customer_id)->toBe($context['customer']->id)
        ->and($receivable->revenue_account_id)->toBe($context['revenues'][1]->id)
        ->and($receivable->receivable_account_id)->toBe($context['receivableControl']->id);
    $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $receivable->provision_journal_entry_id,
        'chart_of_account_id' => $context['receivableControl']->id, 'type' => 'debit', 'amount_cents' => 25000]);
    $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $receivable->provision_journal_entry_id,
        'chart_of_account_id' => $context['revenues'][1]->id, 'type' => 'credit', 'amount_cents' => 25000]);
});

it('enforces fixed amounts and rolls back failed confirmation', function () {
    $context = recurringResolutionContext();
    $expectation = recurringExpectation([
        'context' => $context, 'amountMode' => 'fixed', 'expectedAmountCents' => 10000,
    ]);

    expect(fn () => app(ConfirmRecurringFinancialExpectation::class)->execute(
        $context['wallet'], $expectation, CarbonImmutable::parse('2026-08-01'), 12000,
    ))->toThrow(ValidationException::class)
        ->and(AccountPayable::count())->toBe(0)
        ->and(JournalEntry::count())->toBe(0)
        ->and(RecurringFinancialOccurrence::count())->toBe(0);

    $occurrence = app(ConfirmRecurringFinancialExpectation::class)->execute(
        $context['wallet'], $expectation, CarbonImmutable::parse('2026-08-01'), 10000,
    );
    expect($occurrence->expected_amount_cents)->toBe(10000)
        ->and($occurrence->actual_amount_cents)->toBe(10000);
});

it('estimates variable amounts from up to three prior confirmed occurrences', function () {
    $expectation = recurringExpectation(['expectedAmountCents' => 18000]);
    $estimate = app(EstimateRecurringFinancialExpectationAmount::class);
    expect($estimate->execute($expectation, CarbonImmutable::parse('2026-05-01')))->toBe(18000);

    addConfirmedOccurrence($expectation, '2026-05-01', 10000);
    expect($estimate->execute($expectation, CarbonImmutable::parse('2026-06-01')))->toBe(10000);
    addConfirmedOccurrence($expectation, '2026-06-01', 20000);
    expect($estimate->execute($expectation, CarbonImmutable::parse('2026-07-01')))->toBe(15000);
    addConfirmedOccurrence($expectation, '2026-07-01', 22000);
    expect($estimate->execute($expectation, CarbonImmutable::parse('2026-08-01')))->toBe(17333);
    addConfirmedOccurrence($expectation, '2026-08-01', 19600);
    expect($estimate->execute($expectation, CarbonImmutable::parse('2026-09-01')))->toBe(20533);
});

it('rounds the three month average and ignores skipped null and future occurrences', function () {
    $expectation = recurringExpectation();
    addConfirmedOccurrence($expectation, '2026-05-01', 18400);
    addConfirmedOccurrence($expectation, '2026-06-01', 21300);
    addConfirmedOccurrence($expectation, '2026-07-01', 19600);
    RecurringFinancialOccurrence::query()->create([
        'wallet_id' => $expectation->wallet_id, 'recurring_financial_expectation_id' => $expectation->id,
        'period_date' => '2026-04-15', 'due_date' => '2026-04-10', 'status' => 'skipped', 'skipped_at' => now(),
    ]);
    addConfirmedOccurrence($expectation, '2026-09-01', 99999);

    expect(app(EstimateRecurringFinancialExpectationAmount::class)
        ->execute($expectation, CarbonImmutable::parse('2026-08-01')))->toBe(19767);
});

it('preserves the expected snapshot after later history changes', function () {
    $context = recurringResolutionContext();
    $expectation = recurringExpectation(['context' => $context]);
    addConfirmedOccurrence($expectation, '2026-05-01', 18400);
    addConfirmedOccurrence($expectation, '2026-06-01', 21300);
    addConfirmedOccurrence($expectation, '2026-07-01', 19600);
    $august = app(ConfirmRecurringFinancialExpectation::class)->execute(
        $context['wallet'], $expectation, CarbonImmutable::parse('2026-08-01'), 22135,
    );
    addConfirmedOccurrence($expectation, '2026-09-01', 50000);

    expect($august->fresh()->expected_amount_cents)->toBe(19767)
        ->and($august->actual_amount_cents)->toBe(22135)
        ->and($august->accountPayable->amount_cents)->toBe(22135);
});

it('accepts a real due date only inside the resolved competence', function () {
    $context = recurringResolutionContext();
    $expectation = recurringExpectation(['context' => $context]);
    $occurrence = app(ConfirmRecurringFinancialExpectation::class)->execute(
        $context['wallet'], $expectation, CarbonImmutable::parse('2026-08-01'), 10000,
        CarbonImmutable::parse('2026-08-12'),
    );
    expect($occurrence->due_date->toDateString())->toBe('2026-08-12');

    $september = recurringExpectation(['context' => $context]);
    expect(fn () => app(ConfirmRecurringFinancialExpectation::class)->execute(
        $context['wallet'], $september, CarbonImmutable::parse('2026-09-01'), 10000,
        CarbonImmutable::parse('2026-10-01'),
    ))->toThrow(ValidationException::class)
        ->and(AccountPayable::count())->toBe(1)
        ->and(RecurringFinancialOccurrence::count())->toBe(1);
});

it('prevents duplicate resolution in either confirm and skip order', function () {
    $firstContext = recurringResolutionContext();
    $confirmed = recurringExpectation(['context' => $firstContext]);
    app(ConfirmRecurringFinancialExpectation::class)->execute(
        $firstContext['wallet'], $confirmed, CarbonImmutable::parse('2026-08-01'), 10000,
    );
    expect(fn () => app(SkipRecurringFinancialExpectation::class)->execute(
        $firstContext['wallet'], $confirmed, CarbonImmutable::parse('2026-08-25'),
    ))->toThrow(ValidationException::class)
        ->and(fn () => app(ConfirmRecurringFinancialExpectation::class)->execute(
            $firstContext['wallet'], $confirmed, CarbonImmutable::parse('2026-08-31'), 10000,
        ))->toThrow(ValidationException::class);

    $secondContext = recurringResolutionContext();
    $skipped = recurringExpectation(['context' => $secondContext, 'expectedAmountCents' => 9000]);
    app(SkipRecurringFinancialExpectation::class)->execute(
        $secondContext['wallet'], $skipped, CarbonImmutable::parse('2026-08-01'),
    );
    expect(fn () => app(ConfirmRecurringFinancialExpectation::class)->execute(
        $secondContext['wallet'], $skipped, CarbonImmutable::parse('2026-08-20'), 10000,
    ))->toThrow(ValidationException::class);
});

it('skips with a forecast and creates no financial or accounting record', function () {
    $context = recurringResolutionContext();
    $expectation = recurringExpectation(['context' => $context, 'expectedAmountCents' => 19000]);
    $occurrence = app(SkipRecurringFinancialExpectation::class)->execute(
        $context['wallet'], $expectation, CarbonImmutable::parse('2026-08-18'), 'Sem cobrança',
    );

    expect($occurrence->status)->toBe('skipped')
        ->and($occurrence->period_date->toDateString())->toBe('2026-08-01')
        ->and($occurrence->expected_amount_cents)->toBe(19000)
        ->and($occurrence->actual_amount_cents)->toBeNull()
        ->and($occurrence->account_payable_id)->toBeNull()
        ->and($occurrence->account_receivable_id)->toBeNull()
        ->and(AccountPayable::count())->toBe(0)
        ->and(AccountReceivable::count())->toBe(0)
        ->and(JournalEntry::count())->toBe(0);
});

it('rejects non applicable inactive cross wallet and invalid counterparties', function () {
    $context = recurringResolutionContext();
    $quarterly = recurringExpectation(['context' => $context, 'frequency' => 'quarterly']);
    expect($quarterly->interval_months)->toBe(3)
        ->and(fn () => app(ConfirmRecurringFinancialExpectation::class)->execute(
            $context['wallet'], $quarterly, CarbonImmutable::parse('2026-02-01'), 10000,
        ))->toThrow(ValidationException::class);

    $quarterly->update(['status' => 'inactive']);
    expect(fn () => app(ConfirmRecurringFinancialExpectation::class)->execute(
        $context['wallet'], $quarterly, CarbonImmutable::parse('2026-04-01'), 10000,
    ))->toThrow(ValidationException::class);

    $otherWallet = User::factory()->create()->wallets()->firstOrFail();
    expect(fn () => app(SkipRecurringFinancialExpectation::class)->execute(
        $otherWallet, $quarterly, CarbonImmutable::parse('2026-04-01'),
    ))->toThrow(ValidationException::class);

    $active = recurringExpectation(['context' => $context]);
    expect(fn () => app(ConfirmRecurringFinancialExpectation::class)->execute(
        $context['wallet'], $active, CarbonImmutable::parse('2026-08-01'), 0,
    ))->toThrow(ValidationException::class);

    $context['supplier']->update(['active' => false]);
    expect(fn () => app(CreateRecurringFinancialExpectation::class)->execute(
        $context['wallet'], recurringDto(['context' => $context]),
    ))->toThrow(ValidationException::class);

    $context['customer']->update(['active' => false]);
    expect(fn () => app(CreateRecurringFinancialExpectation::class)->execute(
        $context['wallet'], recurringDto([
            'context' => $context, 'type' => 'receivable', 'supplierId' => null,
            'customerId' => $context['customer']->id, 'defaultAccountId' => $context['revenues'][1]->id,
        ]),
    ))->toThrow(ValidationException::class);
});

it('creates recurring payable with its first title atomically and no future periods', function () {
    $context = recurringResolutionContext();
    $expectation = app(CreateRecurringAccountPayable::class)->execute(
        $context['wallet'], recurringDto(['context' => $context]), CarbonImmutable::parse('2026-08-01'),
        11990, CarbonImmutable::parse('2026-08-15'),
    );
    $occurrence = $expectation->occurrences->sole();

    expect($expectation->expected_amount_cents)->toBeNull()
        ->and($occurrence->expected_amount_cents)->toBeNull()
        ->and($occurrence->actual_amount_cents)->toBe(11990)
        ->and($occurrence->accountPayable->amount_cents)->toBe(11990)
        ->and(RecurringFinancialOccurrence::count())->toBe(1)
        ->and(AccountPayable::count())->toBe(1)
        ->and(JournalEntry::count())->toBe(1);
});

it('creates fixed recurring receivable with its first title and derived fixed amount', function () {
    $context = recurringResolutionContext();
    $dto = recurringDto([
        'context' => $context, 'type' => 'receivable', 'supplierId' => null,
        'customerId' => $context['customer']->id, 'defaultAccountId' => $context['revenues'][1]->id,
        'amountMode' => 'fixed', 'expectedAmountCents' => null,
    ]);
    $expectation = app(CreateRecurringAccountReceivable::class)->execute(
        $context['wallet'], $dto, CarbonImmutable::parse('2026-08-01'), 150000,
    );
    $occurrence = $expectation->occurrences->sole();

    expect($expectation->expected_amount_cents)->toBe(150000)
        ->and($occurrence->expected_amount_cents)->toBe(150000)
        ->and($occurrence->actual_amount_cents)->toBe(150000)
        ->and($occurrence->accountReceivable->amount_cents)->toBe(150000)
        ->and(RecurringFinancialOccurrence::count())->toBe(1)
        ->and(AccountReceivable::count())->toBe(1)
        ->and(JournalEntry::count())->toBe(1);
});

it('rolls back expectation when first title confirmation fails', function () {
    $context = recurringResolutionContext();

    expect(fn () => app(CreateRecurringAccountPayable::class)->execute(
        $context['wallet'], recurringDto(['context' => $context]), CarbonImmutable::parse('2026-08-01'),
        11990, CarbonImmutable::parse('2026-09-01'),
    ))->toThrow(ValidationException::class)
        ->and(RecurringFinancialExpectation::count())->toBe(0)
        ->and(RecurringFinancialOccurrence::count())->toBe(0)
        ->and(AccountPayable::count())->toBe(0)
        ->and(JournalEntry::count())->toBe(0);

    $receivableDto = recurringDto([
        'context' => $context, 'type' => 'receivable', 'supplierId' => null,
        'customerId' => $context['customer']->id, 'defaultAccountId' => $context['revenues'][1]->id,
    ]);
    expect(fn () => app(CreateRecurringAccountReceivable::class)->execute(
        $context['wallet'], $receivableDto, CarbonImmutable::parse('2026-08-01'),
        15000, CarbonImmutable::parse('2026-09-01'),
    ))->toThrow(ValidationException::class)
        ->and(RecurringFinancialExpectation::count())->toBe(0)
        ->and(AccountReceivable::count())->toBe(0)
        ->and(JournalEntry::count())->toBe(0);
});
