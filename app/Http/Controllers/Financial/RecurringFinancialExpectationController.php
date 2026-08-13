<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\RecurringFinancialExpectation;
use App\Models\RecurringFinancialOccurrence;
use App\Models\Supplier;
use App\Services\Financial\ConfirmRecurringFinancialExpectation;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RecurringFinancialExpectationController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request): Response
    {
        $wallet = $this->resolveActiveWallet($request);
        $data = validator([
            'year' => $request->query('year', now()->year),
            'month' => $request->query('month', now()->month),
        ], [
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'between:1,12'],
        ])->validate();

        $period = CarbonImmutable::create((int) $data['year'], (int) $data['month'], 1)->startOfMonth();
        $occurrences = RecurringFinancialOccurrence::query()
            ->where('wallet_id', $wallet->id)
            ->whereDate('period_date', $period->toDateString())
            ->with([
                'accountPayable:id,status,due_date,amount_cents',
                'accountReceivable:id,status,due_date,amount_cents',
            ])
            ->get()
            ->keyBy('recurring_financial_expectation_id');

        $expectations = RecurringFinancialExpectation::query()
            ->where('wallet_id', $wallet->id)
            ->with([
                'supplier:id,name',
                'customer:id,name',
                'defaultAccount:id,code,name,type',
            ])
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->orderBy('due_day')
            ->orderBy('description')
            ->get()
            ->map(function (RecurringFinancialExpectation $expectation) use ($period, $occurrences) {
                $applicable = $expectation->isApplicableTo($period);
                $dueDate = $expectation->dueDateForPeriod($period);
                $occurrence = $occurrences->get($expectation->id);
                $title = $occurrence?->accountPayable ?? $occurrence?->accountReceivable;

                $state = match (true) {
                    $expectation->status !== 'active' => 'inactive',
                    ! $applicable => 'not_due',
                    $occurrence?->status === 'skipped' => 'skipped',
                    (bool) $occurrence => 'confirmed',
                    $dueDate->isBefore(today()) => 'missing_overdue',
                    default => 'missing',
                };

                return [
                    'id' => $expectation->id,
                    'type' => $expectation->type,
                    'description' => $expectation->description,
                    'frequency' => $expectation->frequency,
                    'interval_months' => $expectation->interval_months,
                    'due_day' => $expectation->due_day,
                    'amount_mode' => $expectation->amount_mode,
                    'expected_amount_cents' => $expectation->expected_amount_cents,
                    'starts_on' => $expectation->starts_on?->toDateString(),
                    'ends_on' => $expectation->ends_on?->toDateString(),
                    'status' => $expectation->status,
                    'notes' => $expectation->notes,
                    'counterparty' => $expectation->counterpartyName(),
                    'default_account' => $expectation->defaultAccount ? [
                        'id' => $expectation->defaultAccount->id,
                        'code' => $expectation->defaultAccount->code,
                        'name' => $expectation->defaultAccount->name,
                    ] : null,
                    'period' => [
                        'applicable' => $applicable,
                        'state' => $state,
                        'due_date' => $dueDate->toDateString(),
                        'occurrence_id' => $occurrence?->id,
                        'actual_amount_cents' => $occurrence?->actual_amount_cents,
                        'title_id' => $title?->id,
                        'title_status' => $title?->status,
                        'title_url' => $title
                            ? route(
                                $expectation->type === 'payable' ? 'accounts-payable.show' : 'accounts-receivable.show',
                                $title->id
                            )
                            : null,
                    ],
                ];
            })
            ->values();

        $applicable = $expectations->filter(fn (array $item) => $item['period']['applicable']);

        return Inertia::render('Financial/RecurringExpectations/Index', [
            'wallet' => ['id' => $wallet->id, 'name' => $wallet->name],
            'period' => [
                'year' => $period->year,
                'month' => $period->month,
                'key' => $period->format('Y-m'),
                'start_date' => $period->toDateString(),
            ],
            'summary' => [
                'expected_count' => $applicable->count(),
                'missing_count' => $applicable->whereIn('period.state', ['missing', 'missing_overdue'])->count(),
                'overdue_missing_count' => $applicable->where('period.state', 'missing_overdue')->count(),
                'confirmed_count' => $applicable->where('period.state', 'confirmed')->count(),
                'skipped_count' => $applicable->where('period.state', 'skipped')->count(),
            ],
            'expectations' => $expectations,
            'suppliers' => Supplier::query()->validForPayables($wallet->id)
                ->orderBy('name')->get(['id', 'name', 'default_expense_account_id']),
            'customers' => Customer::query()->validForReceivables($wallet->id)
                ->orderBy('name')->get(['id', 'name', 'default_revenue_account_id']),
            'expenseAccounts' => $this->postingAccounts($wallet->id, 'despesa'),
            'revenueAccounts' => $this->postingAccounts($wallet->id, 'receita'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        $data = $request->validate([
            'type' => ['required', Rule::in(['payable', 'receivable'])],
            'supplier_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'description' => ['required', 'string', 'max:255'],
            'frequency' => ['required', Rule::in(['monthly', 'quarterly', 'semiannual', 'annual'])],
            'due_day' => ['required', 'integer', 'between:1,31'],
            'amount_mode' => ['required', Rule::in(['fixed', 'variable'])],
            'expected_amount_cents' => ['nullable', 'integer', 'min:1'],
            'default_account_id' => ['required', 'integer'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($data['amount_mode'] === 'fixed' && empty($data['expected_amount_cents'])) {
            throw ValidationException::withMessages([
                'expected_amount_cents' => 'Informe o valor previsto para uma recorrência de valor fixo.',
            ]);
        }

        if ($data['type'] === 'payable') {
            $supplier = Supplier::query()->validForPayables($wallet->id)->find($data['supplier_id']);
            if (! $supplier) {
                throw ValidationException::withMessages(['supplier_id' => 'Selecione um fornecedor ativo válido.']);
            }
            $data['supplier_id'] = $supplier->id;
            $data['customer_id'] = null;
        } else {
            $customer = Customer::query()->validForReceivables($wallet->id)->find($data['customer_id']);
            if (! $customer) {
                throw ValidationException::withMessages(['customer_id' => 'Selecione um cliente ativo válido.']);
            }
            $data['customer_id'] = $customer->id;
            $data['supplier_id'] = null;
        }

        $accountType = $data['type'] === 'payable' ? 'despesa' : 'receita';
        $account = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', $accountType)
            ->where('allows_posting', true)
            ->whereDoesntHave('children')
            ->find($data['default_account_id']);

        if (! $account) {
            throw ValidationException::withMessages([
                'default_account_id' => 'Selecione uma conta contábil analítica válida.',
            ]);
        }

        $data['wallet_id'] = $wallet->id;
        $data['interval_months'] = match ($data['frequency']) {
            'quarterly' => 3,
            'semiannual' => 6,
            'annual' => 12,
            default => 1,
        };
        $data['status'] = 'active';

        RecurringFinancialExpectation::query()->create($data);

        return back()->with('success', 'Conta recorrente esperada cadastrada com sucesso.');
    }

    public function confirm(
        Request $request,
        RecurringFinancialExpectation $recurringExpectation,
        ConfirmRecurringFinancialExpectation $service,
    ): RedirectResponse {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless((int) $recurringExpectation->wallet_id === (int) $wallet->id, 404);

        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $service->execute(
            $wallet,
            $recurringExpectation,
            $data['period'],
            (int) $data['amount_cents'],
            $data['due_date'] ?? null,
            $data['notes'] ?? null,
        );

        return back()->with('success', 'Título do período criado a partir da recorrência.');
    }

    public function skip(Request $request, RecurringFinancialExpectation $recurringExpectation): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless((int) $recurringExpectation->wallet_id === (int) $wallet->id, 404);

        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $period = CarbonImmutable::createFromFormat('Y-m', $data['period'])->startOfMonth();

        if (! $recurringExpectation->isApplicableTo($period)) {
            throw ValidationException::withMessages([
                'period' => 'Esta recorrência não está ativa para o período informado.',
            ]);
        }

        DB::transaction(function () use ($wallet, $recurringExpectation, $period, $data) {
            $exists = RecurringFinancialOccurrence::query()
                ->where('recurring_financial_expectation_id', $recurringExpectation->id)
                ->whereDate('period_date', $period->toDateString())
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'period' => 'Este período já possui uma ocorrência registrada.',
                ]);
            }

            RecurringFinancialOccurrence::query()->create([
                'wallet_id' => $wallet->id,
                'recurring_financial_expectation_id' => $recurringExpectation->id,
                'period_date' => $period->toDateString(),
                'due_date' => $recurringExpectation->dueDateForPeriod($period)->toDateString(),
                'expected_amount_cents' => $recurringExpectation->expected_amount_cents,
                'status' => 'skipped',
                'skipped_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);
        });

        return back()->with('success', 'Ocorrência do período marcada como não aplicável.');
    }

    public function toggleStatus(Request $request, RecurringFinancialExpectation $recurringExpectation): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless((int) $recurringExpectation->wallet_id === (int) $wallet->id, 404);

        $recurringExpectation->update([
            'status' => $recurringExpectation->status === 'active' ? 'inactive' : 'active',
        ]);

        return back()->with('success', 'Status da recorrência atualizado.');
    }

    private function postingAccounts(int $walletId, string $type): array
    {
        return ChartOfAccount::query()
            ->where('wallet_id', $walletId)
            ->where('type', $type)
            ->where('allows_posting', true)
            ->whereDoesntHave('children')
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (ChartOfAccount $account) => [
                'id' => $account->id,
                'label' => "{$account->code} - {$account->name}",
            ])
            ->values()
            ->all();
    }
}
