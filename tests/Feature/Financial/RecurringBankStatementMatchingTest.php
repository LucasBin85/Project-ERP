<?php

use App\DTOs\Financial\RecurringFinancialExpectationDTO;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\BankStatementImport;
use App\Models\BankStatementImportTransaction;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\RecurringFinancialOccurrence;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\CreateBankImportEntry;
use App\Services\Financial\ConfirmAndLinkRecurringPayableFromBankStatement;
use App\Services\Financial\ConfirmAndLinkRecurringReceivableFromBankStatement;
use App\Services\Financial\CreateRecurringFinancialExpectation;
use App\Services\Financial\FindBankStatementRecurringPayableCandidates;
use App\Services\Financial\FindBankStatementRecurringReceivableCandidates;
use App\Services\Financial\OfxOperationTypePolicy;
use App\Services\Financial\ReviseRecurringFinancialExpectation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

function recurringMatchContext(): array
{
    static $n = 0;
    $n++;
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $bank = FinancialTestHelper::bankAccount($wallet, '1.1.2.9'.$n, 'Banco '.$n);
    $expense = AccountingTestHelper::account($wallet, '5.9.'.$n, 'Internet', 'despesa', 'debit');
    $revenue = AccountingTestHelper::account($wallet, '4.9.'.$n, 'Receita', 'receita', 'credit');
    $supplier = Supplier::query()->create(['wallet_id' => $wallet->id, 'name' => 'Vivo', 'active' => true, 'payable_account_id' => $wallet->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->value('id'), 'default_expense_account_id' => $expense->id]);
    $customer = Customer::query()->create(['wallet_id' => $wallet->id, 'name' => 'Cliente', 'active' => true, 'receivable_account_id' => $wallet->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->value('id'), 'default_revenue_account_id' => $revenue->id]);
    $import = BankStatementImport::query()->create(['wallet_id' => $wallet->id, 'bank_account_id' => $bank->id, 'source' => 'ofx', 'original_filename' => 'match.ofx', 'file_hash' => hash('sha256', (string) $n), 'status' => 'completed']);

    return compact('user', 'wallet', 'bank', 'expense', 'revenue', 'supplier', 'customer', 'import');
}

function recurringMatchMovement(array $c, int $amount, string $date = '2026-08-12', string $direction = 'out'): array
{
    static $n = 0;
    $n++;
    $operation = $direction === 'out' ? OfxOperationTypePolicy::PAYMENT : OfxOperationTypePolicy::INCOME;
    $entry = app(CreateBankImportEntry::class)->handle(wallet: $c['wallet'], bankAccountId: $c['bank']->chart_of_account_id, amountCents: $amount, direction: $direction, entryDate: $date, description: $direction === 'out' ? 'VIVO INTERNET' : 'CLIENTE MENSAL', source: 'ofx', externalId: 'rec-match-'.$n, autoPostIfBalanced: false);
    $bankLine = $entry->lines->firstWhere('chart_of_account_id', $c['bank']->chart_of_account_id);
    $audit = BankStatementImportTransaction::query()->create(['bank_statement_import_id' => $c['import']->id, 'wallet_id' => $c['wallet']->id, 'bank_account_id' => $c['bank']->id, 'journal_entry_id' => $entry->id, 'journal_line_id' => $bankLine->id, 'external_id' => 'audit-'.$n, 'transaction_hash' => hash('sha256', 'audit-'.$n), 'fit_id' => 'FIT-'.$n, 'posted_at' => $date, 'description' => $entry->description, 'amount_cents' => $amount, 'direction' => $direction, 'operation_type' => $operation, 'status' => 'imported', 'resolution' => 'created']);

    return compact('entry', 'bankLine', 'audit');
}

function recurringMatchRule(array $c, string $type = 'payable', string $mode = 'variable', ?int $expected = 12000, array $extra = [])
{
    return app(CreateRecurringFinancialExpectation::class)->execute($c['wallet'], new RecurringFinancialExpectationDTO(...array_merge(['type' => $type, 'description' => $type === 'payable' ? 'Internet Vivo' : 'Mensalidade', 'frequency' => 'monthly', 'dueDay' => 10, 'amountMode' => $mode, 'expectedAmountCents' => $expected, 'defaultAccountId' => $type === 'payable' ? $c['expense']->id : $c['revenue']->id, 'startsOn' => '2026-08-01', 'supplierId' => $type === 'payable' ? $c['supplier']->id : null, 'customerId' => $type === 'receivable' ? $c['customer']->id : null], $extra)));
}

it('returns variable payable metadata without writing and preserves existing candidates payload', function () {
    $c = recurringMatchContext();
    $m = recurringMatchMovement($c, 12790);
    $rule = recurringMatchRule($c);
    $before = [RecurringFinancialOccurrence::count(), AccountPayable::count(), JournalEntry::count()];
    $response = $this->actingAs($c['user'])->withSession(['active_wallet' => $c['wallet']->id])->getJson(route('bank-accounts.statement.payable-candidates', [$c['bank'], $m['entry']]))->assertOk()->assertJsonStructure(['candidates', 'recurring_candidates']);
    $response->assertJsonPath('recurring_candidates.0.expectation_id', $rule->id)->assertJsonPath('recurring_candidates.0.expected_amount_cents', 12000)->assertJsonPath('recurring_candidates.0.statement_amount_cents', 12790)->assertJsonPath('recurring_candidates.0.amount_difference_cents', 790);
    expect([RecurringFinancialOccurrence::count(), AccountPayable::count(), JournalEntry::count()])->toBe($before);
});

it('includes exact fixed and excludes mismatched fixed candidates', function () {
    $c = recurringMatchContext();
    $m = recurringMatchMovement($c, 150000);
    $exact = recurringMatchRule($c, 'payable', 'fixed', 150000);
    recurringMatchRule($c, 'payable', 'fixed', 160000, ['description' => 'Outro fixo']);
    $items = app(FindBankStatementRecurringPayableCandidates::class)->execute($c['wallet'], $c['bank'], $m['entry']);
    expect(array_unique(array_column($items, 'expectation_id')))->toBe([$exact->id]);
});

it('returns receivable variable only in the symmetric finder', function () {
    $c = recurringMatchContext();
    $m = recurringMatchMovement($c, 13000, direction: 'in');
    $rule = recurringMatchRule($c, 'receivable');
    expect(app(FindBankStatementRecurringReceivableCandidates::class)->execute($c['wallet'], $c['bank'], $m['entry'])[0]['expectation_id'])->toBe($rule->id);
});

it('orders candidates by due-date proximity then amount difference', function () {
    $c = recurringMatchContext();
    $m = recurringMatchMovement($c, 13000);
    $far = recurringMatchRule($c, extra: ['description' => 'Far', 'dueDay' => 20, 'expectedAmountCents' => 13000]);
    $near = recurringMatchRule($c, extra: ['description' => 'Near', 'dueDay' => 11, 'expectedAmountCents' => 10000]);
    $items = app(FindBankStatementRecurringPayableCandidates::class)->execute($c['wallet'], $c['bank'], $m['entry']);
    expect(array_slice(array_column($items, 'expectation_id'), 0, 2))->toBe([$near->id, $far->id]);
});

it('confirms and links a variable payable using statement amount and dates', function () {
    $c = recurringMatchContext();
    $m = recurringMatchMovement($c, 12790, '2026-09-02');
    $rule = recurringMatchRule($c);
    $occ = app(ConfirmAndLinkRecurringPayableFromBankStatement::class)->execute($c['wallet'], $c['bank'], $m['entry'], $rule, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-12'));
    $ap = $occ->accountPayable;
    expect($occ->expected_amount_cents)->toBe(12000)->and($occ->actual_amount_cents)->toBe(12790)->and($occ->due_date->toDateString())->toBe('2026-08-12')->and($ap->status)->toBe('paid')->and($ap->paid_at->toDateString())->toBe('2026-09-02')->and($ap->payment_journal_entry_id)->toBe($m['entry']->id)->and($ap->provision_journal_entry_id)->not->toBeNull()->and($m['bankLine']->fresh()->chart_of_account_id)->toBe($c['bank']->chart_of_account_id)->and($m['audit']->fresh()->classification_account_id)->toBe($ap->payable_account_id);
});

it('confirms and links a receivable symmetrically', function () {
    $c = recurringMatchContext();
    $m = recurringMatchMovement($c, 12790, direction: 'in');
    $rule = recurringMatchRule($c, 'receivable');
    $occ = app(ConfirmAndLinkRecurringReceivableFromBankStatement::class)->execute($c['wallet'], $c['bank'], $m['entry'], $rule, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-10'));
    expect($occ->accountReceivable->status)->toBe('received')->and($occ->accountReceivable->received_at->toDateString())->toBe('2026-08-12')->and($occ->accountReceivable->receipt_journal_entry_id)->toBe($m['entry']->id);
});

it('rolls back confirmation and provision when linking fails', function (string $type) {
    $c = recurringMatchContext();
    $direction = $type === 'payable' ? 'out' : 'in';
    $m = recurringMatchMovement($c, 12790, direction: $direction);
    $rule = recurringMatchRule($c, $type);
    $m['entry']->lines()->where('chart_of_account_id', $c['wallet']->suspense_account_id)->update(['chart_of_account_id' => $type === 'payable' ? $c['expense']->id : $c['revenue']->id]);
    $before = JournalEntry::count();
    $service = $type === 'payable' ? app(ConfirmAndLinkRecurringPayableFromBankStatement::class) : app(ConfirmAndLinkRecurringReceivableFromBankStatement::class);
    expect(fn () => $service->execute($c['wallet'], $c['bank'], $m['entry'], $rule, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-10')))->toThrow(ValidationException::class)->and(RecurringFinancialOccurrence::count())->toBe(0)->and(AccountPayable::count())->toBe(0)->and(AccountReceivable::count())->toBe(0)->and(JournalEntry::count())->toBe($before);
})->with(['payable', 'receivable']);

it('rejects fixed mismatch and due dates outside competence atomically', function (string $case) {
    $c = recurringMatchContext();
    $m = recurringMatchMovement($c, 160000);
    $rule = recurringMatchRule($c, 'payable', $case === 'fixed' ? 'fixed' : 'variable', $case === 'fixed' ? 150000 : 12000);
    $due = $case === 'due' ? '2026-09-01' : '2026-08-10';
    expect(fn () => app(ConfirmAndLinkRecurringPayableFromBankStatement::class)->execute($c['wallet'], $c['bank'], $m['entry'], $rule, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse($due)))->toThrow(ValidationException::class)->and(RecurringFinancialOccurrence::count())->toBe(0)->and(AccountPayable::count())->toBe(0);
})->with(['fixed', 'due']);

it('uses inclusive plus or minus 31 day due-date window', function (string $date, bool $found) {
    $c = recurringMatchContext();
    $m = recurringMatchMovement($c, 12000, $date);
    recurringMatchRule($c, extra: ['frequency' => 'annual']);
    expect(app(FindBankStatementRecurringPayableCandidates::class)->execute($c['wallet'], $c['bank'], $m['entry']) !== [])->toBe($found);
})->with([['2026-07-10', true], ['2026-07-09', false], ['2026-09-10', true], ['2026-09-11', false]]);

it('excludes confirmed skipped inactive and ended competences', function (string $state) {
    $c = recurringMatchContext();
    $m = recurringMatchMovement($c, 12000);
    $rule = recurringMatchRule($c);
    if (in_array($state, ['confirmed', 'skipped'], true)) {
        RecurringFinancialOccurrence::query()->create(['wallet_id' => $c['wallet']->id, 'recurring_financial_expectation_id' => $rule->id, 'period_date' => '2026-08-01', 'due_date' => '2026-08-10', 'status' => $state, 'actual_amount_cents' => $state === 'confirmed' ? 12000 : null]);
    }
    if ($state === 'inactive') {
        $rule->update(['status' => 'inactive']);
    } if ($state === 'ended') {
        $rule->update(['ends_on' => '2026-07-31']);
    }
    $items = app(FindBankStatementRecurringPayableCandidates::class)->execute($c['wallet'], $c['bank'], $m['entry']);
    expect(collect($items)->where('period_date', '2026-08-01'))->toBeEmpty();
})->with(['confirmed', 'skipped', 'inactive', 'ended']);

it('rejects duplicate submission and an already settled movement', function () {
    $c = recurringMatchContext();
    $m = recurringMatchMovement($c, 12000);
    $rule = recurringMatchRule($c);
    $service = app(ConfirmAndLinkRecurringPayableFromBankStatement::class);
    $service->execute($c['wallet'], $c['bank'], $m['entry'], $rule, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-10'));
    expect(fn () => $service->execute($c['wallet'], $c['bank'], $m['entry'], $rule, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-10')))->toThrow(ValidationException::class)
        ->and(fn () => app(FindBankStatementRecurringPayableCandidates::class)->execute($c['wallet'], $c['bank'], $m['entry']))->toThrow(ValidationException::class)
        ->and(RecurringFinancialOccurrence::count())->toBe(1)->and(AccountPayable::count())->toBe(1)->and(JournalEntry::count())->toBe(2);
});

it('protects recurring settlement HTTP endpoints by wallet and type', function () {
    $c = recurringMatchContext();
    $m = recurringMatchMovement($c, 12000);
    $payable = recurringMatchRule($c);
    $receivable = recurringMatchRule($c, 'receivable');
    $session = ['active_wallet' => $c['wallet']->id];
    $payload = ['recurring_financial_expectation_id' => $payable->id, 'period_date' => '2026-08-01', 'due_date' => '2026-08-10'];
    $this->actingAs($c['user'])->withSession($session)->post(route('bank-accounts.statement.confirm-link-recurring-payable', [$c['bank'], $m['entry']]), [...$payload, 'recurring_financial_expectation_id' => $receivable->id])->assertNotFound();
    $foreign = recurringMatchContext();
    $foreignRule = recurringMatchRule($foreign);
    $this->actingAs($c['user'])->withSession($session)->post(route('bank-accounts.statement.confirm-link-recurring-payable', [$c['bank'], $m['entry']]), [...$payload, 'recurring_financial_expectation_id' => $foreignRule->id])->assertNotFound();
});

it('respects V1 and V2 validity boundaries through the range service', function () {
    $this->travelTo('2026-08-20');
    $c = recurringMatchContext();
    $v1 = recurringMatchRule($c);
    $v2 = app(ReviseRecurringFinancialExpectation::class)->execute($c['wallet'], $v1, CarbonImmutable::parse('2026-09-01'), new RecurringFinancialExpectationDTO(type: 'payable', description: 'Internet nova', frequency: 'monthly', dueDay: 10, amountMode: 'variable', expectedAmountCents: 12000, defaultAccountId: $c['expense']->id, startsOn: '2026-09-01', supplierId: $c['supplier']->id));
    $aug = recurringMatchMovement($c, 12000, '2026-08-01');
    $sep = recurringMatchMovement($c, 12000, '2026-09-20');
    expect(array_unique(array_column(app(FindBankStatementRecurringPayableCandidates::class)->execute($c['wallet'], $c['bank'], $aug['entry']), 'expectation_id')))->toBe([$v1->id])
        ->and(array_unique(array_column(app(FindBankStatementRecurringPayableCandidates::class)->execute($c['wallet'], $c['bank'], $sep['entry']), 'expectation_id')))->toBe([$v2->id]);
});
