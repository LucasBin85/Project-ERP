<?php

use App\DTOs\Financial\RecurringFinancialExpectationDTO;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\RecurringFinancialExpectation;
use App\Models\RecurringFinancialOccurrence;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Financial\BuildRecurringFinancialRulesOverview;
use App\Services\Financial\ConfirmRecurringFinancialExpectation;
use App\Services\Financial\CreateRecurringFinancialExpectation;
use App\Services\Financial\DeactivateRecurringFinancialExpectation;
use App\Services\Financial\EstimateRecurringFinancialExpectationAmount;
use App\Services\Financial\ListRecurringFinancialExpectationsForRange;
use App\Services\Financial\ReviseRecurringFinancialExpectation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function revisionContext(): array
{
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $expense = $wallet->chartOfAccounts()->where('type', 'despesa')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $control = $wallet->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $revenue = $wallet->chartOfAccounts()->where('type', 'receita')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $receivableControl = $wallet->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $supplier = Supplier::query()->create(['wallet_id' => $wallet->id, 'name' => 'Vivo', 'payable_account_id' => $control->id, 'default_expense_account_id' => $expense->id, 'active' => true]);
    $customer = Customer::query()->create(['wallet_id' => $wallet->id, 'name' => 'Cliente', 'receivable_account_id' => $receivableControl->id, 'default_revenue_account_id' => $revenue->id, 'active' => true]);

    return compact('user', 'wallet', 'expense', 'revenue', 'supplier', 'customer');
}

function revisionRule(array $context, array $overrides = []): RecurringFinancialExpectation
{
    return app(CreateRecurringFinancialExpectation::class)->execute($context['wallet'], new RecurringFinancialExpectationDTO(...array_merge([
        'type' => 'payable', 'description' => 'Internet', 'frequency' => 'monthly', 'dueDay' => 10,
        'amountMode' => 'variable', 'expectedAmountCents' => null, 'defaultAccountId' => $context['expense']->id,
        'startsOn' => '2026-06-01', 'supplierId' => $context['supplier']->id,
    ], $overrides)));
}

function revisedDto(array $context, array $overrides = []): RecurringFinancialExpectationDTO
{
    return new RecurringFinancialExpectationDTO(...array_merge([
        'type' => 'payable', 'description' => 'Telecom', 'frequency' => 'monthly', 'dueDay' => 15,
        'amountMode' => 'variable', 'expectedAmountCents' => null, 'defaultAccountId' => $context['expense']->id,
        'startsOn' => '2026-09-01', 'supplierId' => $context['supplier']->id,
    ], $overrides));
}

beforeEach(fn () => CarbonImmutable::setTestNow('2026-08-20'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('creates a successor and preserves the previous version without materializing records', function () {
    $c = revisionContext();
    $v1 = revisionRule($c);
    $v2 = app(ReviseRecurringFinancialExpectation::class)->execute($c['wallet'], $v1, CarbonImmutable::parse('2026-09-18'), revisedDto($c));
    expect($v1->refresh()->ends_on->toDateString())->toBe('2026-08-31')->and($v2->starts_on->toDateString())->toBe('2026-09-01')
        ->and($v2->predecessor->is($v1))->toBeTrue()->and($v1->successor->is($v2))->toBeTrue()
        ->and(RecurringFinancialOccurrence::count())->toBe(0)->and(AccountPayable::count())->toBe(0)->and(AccountReceivable::count())->toBe(0)->and(JournalEntry::count())->toBe(0);
});

it('enforces one direct successor per predecessor in SQLite', function () {
    $c = revisionContext();
    $v1 = revisionRule($c);
    revisionRule($c, ['description' => 'V2', 'startsOn' => '2026-09-01', 'replacesExpectationId' => $v1->id]);
    expect(fn () => revisionRule($c, ['description' => 'V2b', 'startsOn' => '2026-10-01', 'replacesExpectationId' => $v1->id]))->toThrow(QueryException::class);
});

it('keeps a quarterly anchor or resets it when frequency changes', function () {
    $c = revisionContext();
    $v1 = revisionRule($c, ['frequency' => 'quarterly', 'startsOn' => '2026-01-01']);
    $v2 = app(ReviseRecurringFinancialExpectation::class)->execute($c['wallet'], $v1, CarbonImmutable::parse('2026-08-01'), revisedDto($c, ['frequency' => 'quarterly']));
    expect($v2->scheduleAnchorDate()->toDateString())->toBe('2026-01-01')->and($v2->isApplicableTo(CarbonImmutable::parse('2026-08-01')))->toBeFalse()->and($v2->isApplicableTo(CarbonImmutable::parse('2026-10-01')))->toBeTrue();
    $v3 = app(ReviseRecurringFinancialExpectation::class)->execute($c['wallet'], $v2, CarbonImmutable::parse('2026-09-01'), revisedDto($c, ['frequency' => 'monthly']));
    expect($v3->scheduleAnchorDate()->toDateString())->toBe('2026-09-01')->and($v3->isApplicableTo(CarbonImmutable::parse('2026-09-01')))->toBeTrue();
});

it('estimates variable values across the complete predecessor chain and ignores skipped', function () {
    $c = revisionContext();
    $v1 = revisionRule($c);
    foreach ([['2026-06-01', 18400, 'confirmed'], ['2026-07-01', 21300, 'confirmed'], ['2026-08-01', 99999, 'skipped']] as [$period,$amount,$status]) {
        RecurringFinancialOccurrence::query()->create(['wallet_id' => $c['wallet']->id, 'recurring_financial_expectation_id' => $v1->id, 'period_date' => $period, 'due_date' => $period, 'actual_amount_cents' => $amount, 'status' => $status]);
    }
    $v2 = app(ReviseRecurringFinancialExpectation::class)->execute($c['wallet'], $v1, CarbonImmutable::parse('2026-09-01'), revisedDto($c));
    RecurringFinancialOccurrence::query()->create(['wallet_id' => $c['wallet']->id, 'recurring_financial_expectation_id' => $v2->id, 'period_date' => '2026-09-01', 'due_date' => '2026-09-10', 'actual_amount_cents' => 19600, 'status' => 'confirmed']);
    $v3 = app(ReviseRecurringFinancialExpectation::class)->execute($c['wallet'], $v2, CarbonImmutable::parse('2026-10-01'), revisedDto($c));
    expect(app(EstimateRecurringFinancialExpectationAmount::class)->execute($v3, CarbonImmutable::parse('2026-10-01')))->toBe(19767);
});

it('blocks retroactive revisions and revisions before a resolved future competence', function () {
    $c = revisionContext();
    $v1 = revisionRule($c);
    expect(fn () => app(ReviseRecurringFinancialExpectation::class)->execute($c['wallet'], $v1, CarbonImmutable::parse('2026-07-01'), revisedDto($c)))->toThrow(ValidationException::class);
    RecurringFinancialOccurrence::query()->create(['wallet_id' => $c['wallet']->id, 'recurring_financial_expectation_id' => $v1->id, 'period_date' => '2026-10-01', 'due_date' => '2026-10-10', 'status' => 'skipped']);
    expect(fn () => app(ReviseRecurringFinancialExpectation::class)->execute($c['wallet'], $v1, CarbonImmutable::parse('2026-09-01'), revisedDto($c)))->toThrow(ValidationException::class);
    expect($v1->refresh()->ends_on)->toBeNull()->and(RecurringFinancialExpectation::count())->toBe(1);
});

it('deactivates only future applicability and preserves history', function () {
    $c = revisionContext();
    $v1 = revisionRule($c);
    $occurrence = RecurringFinancialOccurrence::query()->create(['wallet_id' => $c['wallet']->id, 'recurring_financial_expectation_id' => $v1->id, 'period_date' => '2026-08-01', 'due_date' => '2026-08-10', 'actual_amount_cents' => 20000, 'status' => 'confirmed']);
    app(DeactivateRecurringFinancialExpectation::class)->execute($c['wallet'], $v1, CarbonImmutable::parse('2026-09-01'));
    expect($v1->refresh()->ends_on->toDateString())->toBe('2026-08-31')->and($occurrence->refresh()->actual_amount_cents)->toBe(20000)->and($v1->isApplicableTo(CarbonImmutable::parse('2026-09-01')))->toBeFalse();
});

it('lists only the current rule and skips resolved periods when finding the next competence', function () {
    $c = revisionContext();
    $v1 = revisionRule($c);
    $v2 = app(ReviseRecurringFinancialExpectation::class)->execute($c['wallet'], $v1, CarbonImmutable::parse('2026-09-01'), revisedDto($c));
    RecurringFinancialOccurrence::query()->create(['wallet_id' => $c['wallet']->id, 'recurring_financial_expectation_id' => $v2->id, 'period_date' => '2026-09-01', 'due_date' => '2026-09-15', 'status' => 'skipped']);
    $rules = app(BuildRecurringFinancialRulesOverview::class)->execute($c['wallet'], 'payable', CarbonImmutable::parse('2026-08-01'));
    expect($rules)->toHaveCount(1)->and($rules[0]['id'])->toBe($v2->id)->and($rules[0]['next_period_date'])->toBe('2026-10-01')->and($rules[0]['minimum_revision_period'])->toBe('2026-10-01');
});

it('separates V1 and V2 ranges without overlap', function () {
    $c = revisionContext();
    $v1 = revisionRule($c, ['startsOn' => '2026-08-01']);
    RecurringFinancialOccurrence::query()->create(['wallet_id' => $c['wallet']->id, 'recurring_financial_expectation_id' => $v1->id, 'period_date' => '2026-08-01', 'due_date' => '2026-08-10', 'actual_amount_cents' => 10000, 'status' => 'confirmed']);
    $v2 = app(ReviseRecurringFinancialExpectation::class)->execute($c['wallet'], $v1, CarbonImmutable::parse('2026-09-01'), revisedDto($c));
    $range = app(ListRecurringFinancialExpectationsForRange::class);
    expect($range->execute($c['wallet'], 'payable', CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31')))->toBeEmpty();
    $september = $range->execute($c['wallet'], 'payable', CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
    expect($september)->toHaveCount(1)->and($september[0]['expectation_id'])->toBe($v2->id)->and($v1->refresh()->ends_on->toDateString())->toBe('2026-08-31');
});

it('respects a preserved quarterly anchor in applicability and range', function () {
    $c = revisionContext();
    $v1 = revisionRule($c, ['frequency' => 'quarterly', 'startsOn' => '2026-01-01']);
    $v2 = app(ReviseRecurringFinancialExpectation::class)->execute($c['wallet'], $v1, CarbonImmutable::parse('2026-08-01'), revisedDto($c, ['frequency' => 'quarterly']));
    $range = app(ListRecurringFinancialExpectationsForRange::class);
    expect($v2->isApplicableTo(CarbonImmutable::parse('2026-08-01')))->toBeFalse()
        ->and($v2->isApplicableTo(CarbonImmutable::parse('2026-09-01')))->toBeFalse()
        ->and($v2->isApplicableTo(CarbonImmutable::parse('2026-10-01')))->toBeTrue()
        ->and($range->execute($c['wallet'], 'payable', CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-09-30')))->toBeEmpty()
        ->and($range->execute($c['wallet'], 'payable', CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'))[0]['expectation_id'])->toBe($v2->id);
});

it('uses only the latest three financial periods throughout V1 V2 V3', function () {
    CarbonImmutable::setTestNow('2026-06-20');
    $c = revisionContext();
    $v1 = revisionRule($c, ['startsOn' => '2026-04-01']);
    foreach (['2026-04-01' => 50000, '2026-05-01' => 10000, '2026-06-01' => 12000] as $period => $amount) {
        RecurringFinancialOccurrence::query()->create(['wallet_id' => $c['wallet']->id, 'recurring_financial_expectation_id' => $v1->id, 'period_date' => $period, 'due_date' => $period, 'actual_amount_cents' => $amount, 'status' => 'confirmed']);
    }
    $v2 = app(ReviseRecurringFinancialExpectation::class)->execute($c['wallet'], $v1, CarbonImmutable::parse('2026-07-01'), revisedDto($c));
    RecurringFinancialOccurrence::query()->create(['wallet_id' => $c['wallet']->id, 'recurring_financial_expectation_id' => $v2->id, 'period_date' => '2026-07-01', 'due_date' => '2026-07-10', 'actual_amount_cents' => 14000, 'status' => 'confirmed']);
    $v3 = app(ReviseRecurringFinancialExpectation::class)->execute($c['wallet'], $v2, CarbonImmutable::parse('2026-08-01'), revisedDto($c));
    expect($v2->replaces_expectation_id)->toBe($v1->id)->and($v3->replaces_expectation_id)->toBe($v2->id)
        ->and($v1->successor->is($v2))->toBeTrue()->and($v2->successor->is($v3))->toBeTrue()->and($v3->successor)->toBeNull()
        ->and(app(EstimateRecurringFinancialExpectationAmount::class)->execute($v3, CarbonImmutable::parse('2026-08-01')))->toBe(12000);
});

it('uses current-version fallback and fixed value without inheriting predecessor configuration', function () {
    $c = revisionContext();
    $v1 = revisionRule($c, ['expectedAmountCents' => 18000]);
    $v2 = app(ReviseRecurringFinancialExpectation::class)->execute($c['wallet'], $v1, CarbonImmutable::parse('2026-09-01'), revisedDto($c, ['expectedAmountCents' => null]));
    expect(app(EstimateRecurringFinancialExpectationAmount::class)->execute($v2, CarbonImmutable::parse('2026-09-01')))->toBeNull();
    $fixed1 = revisionRule($c, ['description' => 'Aluguel', 'amountMode' => 'fixed', 'expectedAmountCents' => 150000]);
    $fixed2 = app(ReviseRecurringFinancialExpectation::class)->execute($c['wallet'], $fixed1, CarbonImmutable::parse('2026-09-01'), revisedDto($c, ['amountMode' => 'fixed', 'expectedAmountCents' => 160000]));
    expect(app(EstimateRecurringFinancialExpectationAmount::class)->execute($fixed2, CarbonImmutable::parse('2026-09-01')))->toBe(160000)->and($fixed1->refresh()->expected_amount_cents)->toBe(150000);
});

it('blocks confirmed and skipped future occurrences atomically', function (string $status) {
    $c = revisionContext();
    $v1 = revisionRule($c);
    RecurringFinancialOccurrence::query()->create(['wallet_id' => $c['wallet']->id, 'recurring_financial_expectation_id' => $v1->id, 'period_date' => '2026-10-01', 'due_date' => '2026-10-10', 'actual_amount_cents' => $status === 'confirmed' ? 10000 : null, 'status' => $status]);
    expect(fn () => app(ReviseRecurringFinancialExpectation::class)->execute($c['wallet'], $v1, CarbonImmutable::parse('2026-09-01'), revisedDto($c)))->toThrow(ValidationException::class)
        ->and($v1->refresh()->ends_on)->toBeNull()->and(RecurringFinancialExpectation::count())->toBe(1);
})->with(['confirmed', 'skipped']);

it('blocks revision and deactivation of a non-terminal version', function () {
    $c = revisionContext();
    $v1 = revisionRule($c);
    app(ReviseRecurringFinancialExpectation::class)->execute($c['wallet'], $v1, CarbonImmutable::parse('2026-09-01'), revisedDto($c));
    expect(fn () => app(ReviseRecurringFinancialExpectation::class)->execute($c['wallet'], $v1, CarbonImmutable::parse('2026-10-01'), revisedDto($c)))->toThrow(ValidationException::class)
        ->and(fn () => app(DeactivateRecurringFinancialExpectation::class)->execute($c['wallet'], $v1, CarbonImmutable::parse('2026-10-01')))->toThrow(ValidationException::class)
        ->and(RecurringFinancialExpectation::count())->toBe(2);
});

it('blocks deactivation with a resolved future period', function (string $status) {
    $c = revisionContext();
    $v1 = revisionRule($c);
    RecurringFinancialOccurrence::query()->create(['wallet_id' => $c['wallet']->id, 'recurring_financial_expectation_id' => $v1->id, 'period_date' => '2026-10-01', 'due_date' => '2026-10-10', 'actual_amount_cents' => $status === 'confirmed' ? 10000 : null, 'status' => $status]);
    expect(fn () => app(DeactivateRecurringFinancialExpectation::class)->execute($c['wallet'], $v1, CarbonImmutable::parse('2026-09-01')))->toThrow(ValidationException::class)->and($v1->refresh()->ends_on)->toBeNull();
})->with(['confirmed', 'skipped']);

it('never creates an invalid validity interval', function () {
    $c = revisionContext();
    $v1 = revisionRule($c, ['startsOn' => '2026-08-01']);
    expect(fn () => app(ReviseRecurringFinancialExpectation::class)->execute($c['wallet'], $v1, CarbonImmutable::parse('2026-08-20'), revisedDto($c)))->toThrow(ValidationException::class)
        ->and(fn () => app(DeactivateRecurringFinancialExpectation::class)->execute($c['wallet'], $v1, CarbonImmutable::parse('2026-08-20')))->toThrow(ValidationException::class)
        ->and($v1->refresh()->ends_on)->toBeNull();
});

it('calculates minimum revision from today starts-on and the last resolved period', function (string $starts, ?string $last, string $expected) {
    $c = revisionContext();
    $rule = revisionRule($c, ['startsOn' => $starts]);
    if ($last) {
        RecurringFinancialOccurrence::query()->create(['wallet_id' => $c['wallet']->id, 'recurring_financial_expectation_id' => $rule->id, 'period_date' => $last, 'due_date' => $last, 'status' => 'skipped']);
    }
    $overview = app(BuildRecurringFinancialRulesOverview::class)->execute($c['wallet'], 'payable', CarbonImmutable::parse('2026-08-01'));
    expect($overview[0]['minimum_revision_period'])->toBe($expected);
})->with([
    ['2026-01-01', '2026-06-01', '2026-08-01'],
    ['2026-01-01', '2026-08-01', '2026-09-01'],
    ['2026-01-01', '2026-10-01', '2026-11-01'],
    ['2026-09-01', null, '2026-09-01'],
]);

it('finds monthly quarterly and annual next periods and respects ends-on', function (string $frequency, string $starts, string $resolved, string $expected) {
    $c = revisionContext();
    $rule = revisionRule($c, ['frequency' => $frequency, 'startsOn' => $starts]);
    RecurringFinancialOccurrence::query()->create(['wallet_id' => $c['wallet']->id, 'recurring_financial_expectation_id' => $rule->id, 'period_date' => $resolved, 'due_date' => $resolved, 'status' => 'confirmed', 'actual_amount_cents' => 10000]);
    if ($frequency === 'monthly') {
        RecurringFinancialOccurrence::query()->create(['wallet_id' => $c['wallet']->id, 'recurring_financial_expectation_id' => $rule->id, 'period_date' => '2026-09-01', 'due_date' => '2026-09-01', 'status' => 'skipped']);
    }
    $overview = app(BuildRecurringFinancialRulesOverview::class)->execute($c['wallet'], 'payable', CarbonImmutable::parse('2026-08-01'));
    expect($overview[0]['next_period_date'])->toBe($expected);
})->with([
    ['monthly', '2026-01-01', '2026-08-01', '2026-10-01'],
    ['quarterly', '2026-01-01', '2026-07-01', '2026-10-01'],
    ['annual', '2026-01-01', '2026-01-01', '2027-01-01'],
]);

it('preserves all historical fields occurrences titles and journal entries', function () {
    $c = revisionContext();
    $v1 = revisionRule($c, ['startsOn' => '2026-08-01', 'description' => 'Internet A', 'notes' => 'Nota A']);
    $occurrence = app(ConfirmRecurringFinancialExpectation::class)->execute($c['wallet'], $v1, CarbonImmutable::parse('2026-08-01'), 12345, CarbonImmutable::parse('2026-08-10'));
    $original = $v1->only(['description', 'supplier_id', 'default_account_id', 'frequency', 'amount_mode', 'expected_amount_cents', 'due_day', 'notes', 'schedule_anchor_date']);
    $original['schedule_anchor_date'] = $v1->schedule_anchor_date->toDateString();
    $payable = $occurrence->accountPayable->only(['id', 'amount_cents', 'due_date', 'provision_journal_entry_id']);
    $journal = $occurrence->accountPayable->provisionJournalEntry->only(['id', 'status', 'entry_date']);
    $payable['due_date'] = $occurrence->accountPayable->due_date->toDateString();
    $journal['entry_date'] = $occurrence->accountPayable->provisionJournalEntry->entry_date->toDateString();
    app(ReviseRecurringFinancialExpectation::class)->execute($c['wallet'], $v1, CarbonImmutable::parse('2026-09-01'), revisedDto($c, ['description' => 'Internet B']));
    $preserved = $v1->refresh()->only(array_keys($original));
    $preserved['schedule_anchor_date'] = $v1->schedule_anchor_date->toDateString();
    $preservedPayable = $occurrence->accountPayable->only(['id', 'amount_cents', 'due_date', 'provision_journal_entry_id']);
    $preservedPayable['due_date'] = $occurrence->accountPayable->due_date->toDateString();
    $preservedJournal = $occurrence->accountPayable->provisionJournalEntry->only(['id', 'status', 'entry_date']);
    $preservedJournal['entry_date'] = $occurrence->accountPayable->provisionJournalEntry->entry_date->toDateString();
    expect($preserved)->toBe($original)
        ->and($occurrence->refresh()->recurring_financial_expectation_id)->toBe($v1->id)
        ->and($preservedPayable)->toBe($payable)
        ->and($preservedJournal)->toBe($journal);
});

it('keeps AP and AR management GET requests read-only', function () {
    $c = revisionContext();
    revisionRule($c);
    $before = [RecurringFinancialExpectation::count(), RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()];
    $session = ['active_wallet' => $c['wallet']->id];
    $this->actingAs($c['user'])->withSession($session)->get(route('accounts-payable.index'))->assertOk()->assertInertia(fn ($page) => $page->has('recurringRules', 1));
    $this->actingAs($c['user'])->withSession($session)->get(route('accounts-receivable.index'))->assertOk()->assertInertia(fn ($page) => $page->has('recurringRules', 0));
    expect([RecurringFinancialExpectation::count(), RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()])->toBe($before);
});

it('revises and deactivates through AP HTTP while protecting wallet type and terminal version', function () {
    $c = revisionContext();
    $v1 = revisionRule($c);
    $payload = ['effective_from' => '2026-09-01', 'description' => 'Telecom', 'supplier_id' => $c['supplier']->id, 'frequency' => 'monthly', 'amount_mode' => 'variable', 'expected_amount_cents' => null, 'due_day' => 15, 'default_account_id' => $c['expense']->id, 'ends_on' => null, 'notes' => null];
    $session = ['active_wallet' => $c['wallet']->id];
    $this->actingAs($c['user'])->withSession($session)->put(route('accounts-payable.recurring.revise', $v1), $payload)->assertRedirect();
    $v2 = $v1->successor()->firstOrFail();
    $this->actingAs($c['user'])->withSession($session)->put(route('accounts-payable.recurring.revise', $v1), $payload)->assertNotFound();
    $foreign = revisionContext();
    $this->actingAs($c['user'])->withSession($session)->patch(route('accounts-payable.recurring.deactivate', revisionRule($foreign)), ['effective_from' => '2026-10-01'])->assertNotFound();
    $this->actingAs($c['user'])->withSession($session)->patch(route('accounts-receivable.recurring.deactivate', $v2), ['effective_from' => '2026-10-01'])->assertNotFound();
    $this->actingAs($c['user'])->withSession($session)->patch(route('accounts-payable.recurring.deactivate', $v2), ['effective_from' => '2026-10-01'])->assertRedirect();
    expect($v2->refresh()->ends_on->toDateString())->toBe('2026-09-30');
});

it('revises and deactivates a receivable through its symmetric HTTP endpoints', function () {
    $c = revisionContext();
    $v1 = app(CreateRecurringFinancialExpectation::class)->execute($c['wallet'], new RecurringFinancialExpectationDTO(
        type: 'receivable', description: 'Contrato', frequency: 'monthly', dueDay: 5, amountMode: 'fixed', expectedAmountCents: 30000,
        defaultAccountId: $c['revenue']->id, startsOn: '2026-06-01', customerId: $c['customer']->id,
    ));
    $payload = ['effective_from' => '2026-09-01', 'description' => 'Contrato novo', 'customer_id' => $c['customer']->id, 'frequency' => 'monthly', 'amount_mode' => 'fixed', 'expected_amount_cents' => 32000, 'due_day' => 5, 'default_account_id' => $c['revenue']->id, 'ends_on' => null, 'notes' => null];
    $session = ['active_wallet' => $c['wallet']->id];
    $this->actingAs($c['user'])->withSession($session)->put(route('accounts-receivable.recurring.revise', $v1), $payload)->assertRedirect();
    $v2 = $v1->successor()->firstOrFail();
    $this->actingAs($c['user'])->withSession($session)->patch(route('accounts-receivable.recurring.deactivate', $v2), ['effective_from' => '2026-10-01'])->assertRedirect();
    expect($v2->refresh()->ends_on->toDateString())->toBe('2026-09-30')->and($v2->expected_amount_cents)->toBe(32000);
});

it('omits an ended terminal rule from overview and later ranges while retaining history', function () {
    $c = revisionContext();
    $rule = revisionRule($c, ['startsOn' => '2026-01-01', 'endsOn' => '2026-07-31']);
    RecurringFinancialOccurrence::query()->create(['wallet_id' => $c['wallet']->id, 'recurring_financial_expectation_id' => $rule->id, 'period_date' => '2026-07-01', 'due_date' => '2026-07-10', 'status' => 'skipped']);
    expect(app(BuildRecurringFinancialRulesOverview::class)->execute($c['wallet'], 'payable', CarbonImmutable::parse('2026-08-01')))->toBeEmpty()
        ->and(app(ListRecurringFinancialExpectationsForRange::class)->execute($c['wallet'], 'payable', CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-12-31')))->toBeEmpty()
        ->and($rule->occurrences()->count())->toBe(1);
});
