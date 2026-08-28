<?php

namespace App\Http\Controllers\Financial;

use App\DTOs\Financial\AccountReceivableDTO;
use App\DTOs\Financial\ReceiveAccountReceivableDTO;
use App\DTOs\Financial\RecurringFinancialExpectationDTO;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Models\AccountReceivable;
use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\RecurringFinancialExpectation;
use App\Services\Financial\BuildRecurringFinancialRulesOverview;
use App\Services\Financial\CancelAccountReceivable;
use App\Services\Financial\ConfirmRecurringFinancialExpectation;
use App\Services\Financial\CreateAccountReceivable;
use App\Services\Financial\CreateRecurringAccountReceivable;
use App\Services\Financial\DeactivateRecurringFinancialExpectation;
use App\Services\Financial\ListRecurringFinancialExpectationsForRange;
use App\Services\Financial\ReceiveAccountReceivable;
use App\Services\Financial\ReverseAccountReceivableSettlement;
use App\Services\Financial\ReviseRecurringFinancialExpectation;
use App\Services\Financial\SkipRecurringFinancialExpectation;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AccountReceivableController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request, ListRecurringFinancialExpectationsForRange $recurringExpectations, BuildRecurringFinancialRulesOverview $rules): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        $filters = [
            'status' => $request->query('status', ''),
            'start_date' => $request->query('start_date') ?: now()->startOfMonth()->toDateString(),
            'end_date' => $request->query('end_date') ?: now()->endOfMonth()->toDateString(),
            'search' => $request->query('search', ''),
        ];

        $validated = validator($filters, [
            'status' => ['nullable', Rule::in(['', 'pending', 'received', 'cancelled'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'search' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $accountsReceivable = AccountReceivable::query()
            ->where('wallet_id', $wallet->id)
            ->with([
                'revenueAccount:id,code,name',
                'receivableAccount:id,code,name',
                'provisionJournalEntry:id,status',
                'bankAccount:id,name,bank_name,bank_code,agency,account_number',
                'receiptJournalEntry:id,status',
                'series:id,description,total_amount_cents,installment_count,status',
            ])
            ->when($validated['status'] !== '', fn ($query) => $query->where('status', $validated['status']))
            ->whereDate('due_date', '>=', $validated['start_date'])
            ->whereDate('due_date', '<=', $validated['end_date'])
            ->when($validated['search'] !== '', function ($query) use ($validated) {
                $query->where(function ($query) use ($validated) {
                    $query->where('customer_name', 'like', '%'.$validated['search'].'%')
                        ->orWhere('description', 'like', '%'.$validated['search'].'%');
                });
            })
            ->orderBy('due_date')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Financial/AccountsReceivable/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'filters' => $validated,
            'accountsReceivable' => $accountsReceivable,
            'recurringExpectedReceivables' => $recurringExpectations->execute(
                $wallet, 'receivable', CarbonImmutable::parse($validated['start_date']), CarbonImmutable::parse($validated['end_date'])
            ),
            'recurringRules' => $rules->execute($wallet, 'receivable', now()),
            'recurringCustomers' => Customer::query()->validForReceivables($wallet->id)->orderBy('name')->get(['id', 'name']),
            'recurringAccounts' => $this->revenueAccounts($wallet->id),
        ]);
    }

    public function reviseRecurring(Request $request, RecurringFinancialExpectation $expectation, ReviseRecurringFinancialExpectation $service): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless($expectation->wallet_id === $wallet->id && $expectation->type === 'receivable' && ! $expectation->successor()->exists(), 404);
        $data = $request->validate([
            'effective_from' => ['required', 'date'], 'description' => ['required', 'string', 'max:255'], 'customer_id' => ['required', 'integer'],
            'frequency' => ['required', Rule::in(array_keys(RecurringFinancialExpectation::FREQUENCY_INTERVALS))], 'amount_mode' => ['required', Rule::in(['fixed', 'variable'])],
            'forecast_strategy' => ['nullable', Rule::prohibitedIf(fn () => $request->input('amount_mode') === 'fixed'), Rule::in(array_keys(RecurringFinancialExpectation::FORECAST_STRATEGIES))],
            'expected_amount_cents' => ['nullable', 'integer', 'min:1', Rule::requiredIf(fn () => $request->input('amount_mode') === 'fixed')],
            'due_day' => ['required', 'integer', 'between:1,31'], 'default_account_id' => ['required', 'integer'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:effective_from'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $service->execute($wallet, $expectation, CarbonImmutable::parse($data['effective_from']), new RecurringFinancialExpectationDTO(
            type: 'receivable', description: $data['description'], frequency: $data['frequency'], dueDay: (int) $data['due_day'],
            amountMode: $data['amount_mode'], expectedAmountCents: isset($data['expected_amount_cents']) ? (int) $data['expected_amount_cents'] : null,
            forecastStrategy: $data['forecast_strategy'] ?? null,
            defaultAccountId: (int) $data['default_account_id'], startsOn: $data['effective_from'], endsOn: $data['ends_on'] ?? null,
            customerId: (int) $data['customer_id'], notes: $data['notes'] ?? null,
        ));

        return back()->with('success', 'Recorrência revisada para as competências futuras.');
    }

    public function deactivateRecurring(Request $request, RecurringFinancialExpectation $expectation, DeactivateRecurringFinancialExpectation $service): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless($expectation->wallet_id === $wallet->id && $expectation->type === 'receivable' && ! $expectation->successor()->exists(), 404);
        $data = $request->validate(['effective_from' => ['required', 'date']]);
        $service->execute($wallet, $expectation, CarbonImmutable::parse($data['effective_from']));

        return back()->with('success', 'Recorrência encerrada para as competências futuras.');
    }

    public function confirmRecurring(Request $request, RecurringFinancialExpectation $expectation, ConfirmRecurringFinancialExpectation $service): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless($expectation->wallet_id === $wallet->id && $expectation->type === 'receivable', 404);
        $data = $request->validate([
            'period_date' => ['required', 'date'],
            'actual_amount_cents' => ['required', 'integer', 'min:1'],
            'due_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $service->execute($wallet, $expectation, CarbonImmutable::parse($data['period_date']), (int) $data['actual_amount_cents'], CarbonImmutable::parse($data['due_date']), $data['notes'] ?? null);

        return back()->with('success', 'Competência recorrente confirmada e conta a receber criada.');
    }

    public function skipRecurring(Request $request, RecurringFinancialExpectation $expectation, SkipRecurringFinancialExpectation $service): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless($expectation->wallet_id === $wallet->id && $expectation->type === 'receivable', 404);
        $data = $request->validate(['period_date' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $service->execute($wallet, $expectation, CarbonImmutable::parse($data['period_date']), $data['notes'] ?? null);

        return back()->with('success', 'Competência recorrente ignorada.');
    }

    public function create(Request $request): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        return Inertia::render('Financial/AccountsReceivable/Create', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'customers' => Customer::query()->validForReceivables($wallet->id)
                ->with(['receivableAccount:id,code,name', 'defaultRevenueAccount:id,code,name'])
                ->orderBy('name')->get(['id', 'name', 'receivable_account_id', 'default_revenue_account_id']),
            'receivableControlAccounts' => $this->controlAccounts($wallet->id),
            'revenueAccounts' => $this->revenueAccounts($wallet->id),
            'customerNames' => Customer::query()->where('wallet_id', $wallet->id)->pluck('name'),
        ]);
    }

    public function store(Request $request, CreateAccountReceivable $service, CreateRecurringAccountReceivable $recurringService): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);

        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where(
                fn ($query) => $query->whereIn('id', Customer::query()->validForReceivables($wallet->id)->select('id'))
            )],
            'description' => ['required', 'string', 'max:255'],
            'due_date' => ['required', 'date'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'mode' => ['nullable', Rule::in(['single', 'installment', 'recurring'])],
            'installment_count' => ['required_if:mode,installment', 'integer', 'min:2', 'max:360'],
            'interval_months' => ['required_if:mode,installment', 'integer', 'min:1', 'max:120'],
            'competence_date' => ['required_if:mode,installment,recurring', 'nullable', 'date'],
            'installments' => ['required_if:mode,installment', 'array'],
            'installments.*.due_date' => ['required', 'date'],
            'installments.*.amount_cents' => ['required', 'integer', 'min:1'],
            'recurring_frequency' => ['required_if:mode,recurring', 'nullable', Rule::in(['monthly', 'quarterly', 'semiannual', 'annual'])],
            'recurring_amount_mode' => ['required_if:mode,recurring', 'nullable', Rule::in(['fixed', 'variable'])],
            'recurring_forecast_strategy' => ['nullable', Rule::prohibitedIf(fn () => $request->input('recurring_amount_mode') === 'fixed'), Rule::in(array_keys(RecurringFinancialExpectation::FORECAST_STRATEGIES))],
            'recurring_due_day' => ['required_if:mode,recurring', 'nullable', 'integer', 'min:1', 'max:31'],
            'recurring_default_account_id' => ['required_if:mode,recurring', 'nullable', 'integer'],
            'recurring_expected_amount_cents' => ['nullable', 'integer', 'min:1'],
            'recurring_ends_on' => ['nullable', 'date', 'after_or_equal:competence_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (($data['mode'] ?? 'single') === 'installment') {
            if (count($data['installments'] ?? []) !== (int) $data['installment_count']) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'installments' => 'A quantidade de parcelas informada é inválida.',
                ]);
            }
            if (collect($data['installments'])->sum('amount_cents') !== (int) $data['amount_cents']) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'installments' => 'A soma das parcelas precisa ser igual ao valor total.',
                ]);
            }
        }

        if (($data['mode'] ?? 'single') === 'recurring') {
            $recurringService->execute(
                $wallet,
                new RecurringFinancialExpectationDTO(
                    type: 'receivable', description: $data['description'], frequency: $data['recurring_frequency'],
                    dueDay: (int) $data['recurring_due_day'], amountMode: $data['recurring_amount_mode'],
                    expectedAmountCents: $data['recurring_amount_mode'] === 'fixed' ? (int) $data['amount_cents'] : ($data['recurring_expected_amount_cents'] ?? null),
                    defaultAccountId: (int) $data['recurring_default_account_id'], startsOn: CarbonImmutable::parse($data['competence_date'])->startOfMonth()->toDateString(),
                    endsOn: $data['recurring_ends_on'] ?? null, customerId: (int) $data['customer_id'], notes: $data['notes'] ?? null,
                    forecastStrategy: $data['recurring_forecast_strategy'] ?? null,
                ),
                CarbonImmutable::parse($data['competence_date']), (int) $data['amount_cents'],
                CarbonImmutable::parse($data['due_date']), $data['notes'] ?? null,
            );
        } else {
            $service->execute($wallet, AccountReceivableDTO::fromArray($data));
        }

        return redirect()
            ->route('accounts-receivable.index')
            ->with('success', 'Título a receber cadastrado com sucesso.');
    }

    public function show(Request $request, AccountReceivable $accountReceivable): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        abort_unless($accountReceivable->wallet_id === $wallet->id, 404);

        $accountReceivable->load([
            'revenueAccount',
            'receivableAccount',
            'provisionJournalEntry.lines.chartOfAccount',
            'bankAccount',
            'receiptJournalEntry.lines.chartOfAccount',
            'receiptJournalEntry.bankStatementImportTransaction:id,journal_entry_id',
            'series.provisionJournalEntry.lines.chartOfAccount',
            'series.receivables',
            'cancelledBy:id,name',
            'cancellationJournalEntry:id,entry_date,status,reversal_of_journal_entry_id',
            'settlementReversals.reversalJournalEntry:id,entry_date,status,reversal_of_journal_entry_id',
            'settlementReversals.bankAccount:id,name',
            'settlementReversals.reversedBy:id,name',
        ]);

        $bankAccounts = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'bank_name', 'bank_code', 'agency', 'account_number'])
            ->map(fn (BankAccount $account) => [
                'id' => $account->id,
                'label' => $this->formatBankAccountLabel($account),
                'name' => $account->name,
            ])
            ->values();

        return Inertia::render('Financial/AccountsReceivable/Show', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'accountReceivable' => $accountReceivable,
            'bankAccounts' => $bankAccounts,
        ]);
    }

    public function receive(Request $request, AccountReceivable $accountReceivable, ReceiveAccountReceivable $service): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);

        abort_unless($accountReceivable->wallet_id === $wallet->id, 404);

        $data = $request->validate([
            'bank_account_id' => [
                'required',
                'integer',
                Rule::exists('bank_accounts', 'id')
                    ->where('wallet_id', $wallet->id)
                    ->where('is_active', true),
            ],
            'received_at' => ['required', 'date'],
        ]);

        $service->execute($wallet, $accountReceivable, ReceiveAccountReceivableDTO::fromArray($data));

        return redirect()
            ->route('accounts-receivable.show', $accountReceivable)
            ->with('success', 'Conta a receber baixada com sucesso.');
    }

    public function cancel(Request $request, AccountReceivable $accountReceivable, CancelAccountReceivable $service): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless($accountReceivable->wallet_id === $wallet->id, 404);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'reversal_date' => ['nullable', 'date'],
        ]);
        $service->execute($wallet, $accountReceivable, $request->user(), $data['reason'], $data['reversal_date'] ?? null);

        return redirect()->route('accounts-receivable.show', $accountReceivable)->with('success', 'Título a receber cancelado com sucesso.');
    }

    public function reverseSettlement(Request $request, AccountReceivable $accountReceivable, ReverseAccountReceivableSettlement $service): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless($accountReceivable->wallet_id === $wallet->id, 404);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'reversal_date' => ['nullable', 'date'],
        ]);
        $service->execute($wallet, $accountReceivable, $request->user(), $data['reason'], $data['reversal_date'] ?? null);

        return redirect()->route('accounts-receivable.show', $accountReceivable)->with('success', 'Recebimento revertido com sucesso.');
    }

    private function revenueAccounts(int $walletId): array
    {
        return ChartOfAccount::query()
            ->where('wallet_id', $walletId)
            ->where('type', 'receita')
            ->where('allows_posting', true)
            ->whereDoesntHave('children')
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (ChartOfAccount $account) => [
                'id' => $account->id,
                'label' => "{$account->code} - {$account->name}",
                'code' => $account->code,
                'name' => $account->name,
            ])
            ->values()
            ->all();
    }

    private function controlAccounts(int $walletId): array
    {
        return ChartOfAccount::query()->where('wallet_id', $walletId)->where('type', 'ativo')
            ->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->whereDoesntHave('children')
            ->orderBy('code')->get(['id', 'code', 'name'])->map(fn (ChartOfAccount $account) => [
                'id' => $account->id, 'label' => "{$account->code} - {$account->name}",
            ])->values()->all();
    }

    private function formatBankAccountLabel(BankAccount $account): string
    {
        $details = collect([
            $account->bank_code,
            $account->agency,
            $account->account_number,
        ])->filter()->join(' / ');

        return $details !== ''
            ? "{$account->name} ({$details})"
            : $account->name;
    }
}
