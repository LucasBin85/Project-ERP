<?php

namespace App\Http\Controllers\Financial;

use App\DTOs\Financial\AccountReceivableDTO;
use App\DTOs\Financial\ReceiveAccountReceivableDTO;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Models\AccountReceivable;
use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Services\Financial\CreateAccountReceivable;
use App\Services\Financial\ReceiveAccountReceivable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AccountReceivableController extends Controller
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
        ]);
    }

    public function create(Request $request): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        return Inertia::render('Financial/AccountsReceivable/Create', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'customers' => Customer::where('wallet_id', $wallet->id)->where('active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, CreateAccountReceivable $service): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);

        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('wallet_id', $wallet->id)->where('active', true)],
            'description' => ['required', 'string', 'max:255'],
            'due_date' => ['required', 'date'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $service->execute($wallet, AccountReceivableDTO::fromArray($data));

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

    private function revenueAccounts(int $walletId): array
    {
        return ChartOfAccount::query()
            ->where('wallet_id', $walletId)
            ->where('type', 'receita')
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
