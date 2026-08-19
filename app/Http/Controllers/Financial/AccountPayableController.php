<?php

namespace App\Http\Controllers\Financial;

use App\DTOs\Financial\AccountPayableDTO;
use App\DTOs\Financial\PayAccountPayableDTO;
use App\DTOs\Financial\RecurringFinancialExpectationDTO;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Models\AccountPayable;
use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\Supplier;
use App\Services\Financial\CreateAccountPayable;
use App\Services\Financial\CreateRecurringAccountPayable;
use App\Services\Financial\PayAccountPayable;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AccountPayableController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        $filters = [
            'status' => $request->query('status', ''),
            'start_date' => $request->query('start_date') ?: now()->startOfMonth()->toDateString(),
            'end_date' => $request->query('end_date') ?: now()->endOfMonth()->toDateString(),
            'search' => $request->query('search', ''),
        ];

        $validated = validator($filters, [
            'status' => ['nullable', Rule::in(['', 'pending', 'paid', 'cancelled'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'search' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $accountsPayable = AccountPayable::query()
            ->where('wallet_id', $wallet->id)
            ->with([
                'expenseAccount:id,code,name',
                'payableAccount:id,code,name',
                'provisionJournalEntry:id,status',
                'bankAccount:id,name,bank_name,bank_code,agency,account_number',
                'paymentJournalEntry:id,status',
                'series:id,description,total_amount_cents,installment_count,status',
            ])
            ->when($validated['status'] !== '', fn ($query) => $query->where('status', $validated['status']))
            ->whereDate('due_date', '>=', $validated['start_date'])
            ->whereDate('due_date', '<=', $validated['end_date'])
            ->when($validated['search'] !== '', function ($query) use ($validated) {
                $query->where(function ($query) use ($validated) {
                    $query->where('payee_name', 'like', '%'.$validated['search'].'%')
                        ->orWhere('description', 'like', '%'.$validated['search'].'%');
                });
            })
            ->orderBy('due_date')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Financial/AccountsPayable/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'filters' => $validated,
            'accountsPayable' => $accountsPayable,
        ]);
    }

    public function create(Request $request): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        return Inertia::render('Financial/AccountsPayable/Create', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'suppliers' => Supplier::query()->validForPayables($wallet->id)
                ->with(['payableAccount:id,code,name', 'defaultExpenseAccount:id,code,name'])
                ->orderBy('name')->get(['id', 'name', 'payable_account_id', 'default_expense_account_id']),
            'payableControlAccounts' => $this->controlAccounts($wallet->id, 'passivo', 'accounts_payable'),
            'expenseAccounts' => $this->expenseAccounts($wallet->id),
            'supplierNames' => Supplier::query()->where('wallet_id', $wallet->id)->pluck('name'),
        ]);
    }

    public function store(Request $request, CreateAccountPayable $service, CreateRecurringAccountPayable $recurringService): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);

        $data = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where(
                fn ($query) => $query->whereIn('id', Supplier::query()->validForPayables($wallet->id)->select('id'))
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
                    type: 'payable', description: $data['description'], frequency: $data['recurring_frequency'],
                    dueDay: (int) $data['recurring_due_day'], amountMode: $data['recurring_amount_mode'],
                    expectedAmountCents: $data['recurring_amount_mode'] === 'fixed' ? (int) $data['amount_cents'] : ($data['recurring_expected_amount_cents'] ?? null),
                    defaultAccountId: (int) $data['recurring_default_account_id'], startsOn: CarbonImmutable::parse($data['competence_date'])->startOfMonth()->toDateString(),
                    endsOn: $data['recurring_ends_on'] ?? null, supplierId: (int) $data['supplier_id'], notes: $data['notes'] ?? null,
                ),
                CarbonImmutable::parse($data['competence_date']), (int) $data['amount_cents'],
                CarbonImmutable::parse($data['due_date']), $data['notes'] ?? null,
            );
        } else {
            $service->execute($wallet, AccountPayableDTO::fromArray($data));
        }

        return redirect()
            ->route('accounts-payable.index')
            ->with('success', 'Título a pagar cadastrado com sucesso.');
    }

    public function show(Request $request, AccountPayable $accountPayable): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        abort_unless($accountPayable->wallet_id === $wallet->id, 404);

        $accountPayable->load([
            'expenseAccount',
            'payableAccount',
            'provisionJournalEntry.lines.chartOfAccount',
            'bankAccount',
            'paymentJournalEntry.lines.chartOfAccount',
            'series.provisionJournalEntry.lines.chartOfAccount',
            'series.payables',
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

        return Inertia::render('Financial/AccountsPayable/Show', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'accountPayable' => $accountPayable,
            'bankAccounts' => $bankAccounts,
        ]);
    }

    public function pay(Request $request, AccountPayable $accountPayable, PayAccountPayable $service): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);

        abort_unless($accountPayable->wallet_id === $wallet->id, 404);

        $data = $request->validate([
            'bank_account_id' => [
                'required',
                'integer',
                Rule::exists('bank_accounts', 'id')
                    ->where('wallet_id', $wallet->id)
                    ->where('is_active', true),
            ],
            'paid_at' => ['required', 'date'],
        ]);

        $service->execute($wallet, $accountPayable, PayAccountPayableDTO::fromArray($data));

        return redirect()
            ->route('accounts-payable.show', $accountPayable)
            ->with('success', 'Conta a pagar baixada com sucesso.');
    }

    private function expenseAccounts(int $walletId): array
    {
        return ChartOfAccount::query()
            ->where('wallet_id', $walletId)
            ->where('type', 'despesa')
            ->where('allows_posting', true)
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

    private function controlAccounts(int $walletId, string $type, string $group): array
    {
        return ChartOfAccount::query()->where('wallet_id', $walletId)->where('type', $type)
            ->where('financial_group', $group)->where('allows_posting', true)->whereDoesntHave('children')
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
