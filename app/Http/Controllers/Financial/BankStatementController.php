<?php

namespace App\Http\Controllers\Financial;

use App\DTOs\Financial\BankStatementFiltersDTO;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\JournalLine;
use App\Services\Financial\BankStatementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BankStatementController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        $bankAccountId = $request->integer('bank_account_id');

        if ($bankAccountId) {
            $bankAccount = BankAccount::query()
                ->where('wallet_id', $wallet->id)
                ->findOrFail($bankAccountId);

            return redirect()->route('bank-accounts.statement', array_filter([
                'bankAccount' => $bankAccount,
                'start_date' => $request->query('start_date'),
                'end_date' => $request->query('end_date'),
                'search' => $request->query('search'),
            ]));
        }

        return redirect()->route('bank-accounts.index');
    }

    public function show(Request $request, BankAccount $bankAccount, BankStatementService $service): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        abort_unless($bankAccount->wallet_id === $wallet->id, 404);

        $bankAccount->load('chartOfAccount');

        $rawFilters = [
            'bank_account_id' => (string) $bankAccount->id,
            'start_date' => $request->query('start_date') ?: now()->subDays(90)->toDateString(),
            'end_date' => $request->query('end_date') ?: now()->toDateString(),
            'search' => $request->string('search')->toString(),
        ];

        $validated = validator($rawFilters, [
            'bank_account_id' => ['required', 'integer'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'search' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $filters = BankStatementFiltersDTO::fromArray($validated);
        $statement = $service->build($wallet, $filters)->toArray();

        return Inertia::render('Financial/BankStatements/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'filters' => $statement['filters'],
            'selectedBankAccount' => $statement['bank_account'],
            'transactions' => $statement['transactions'],
            'operational' => $this->operationalContext(
                bankAccount: $bankAccount,
                startDate: $filters->startDate,
            ),
        ]);
    }

    private function operationalContext(BankAccount $bankAccount, string $startDate): array
    {
        return [
            'has_older_transactions' => $this->hasOlderTransactions($bankAccount, $startDate),
        ];
    }

    private function hasOlderTransactions(BankAccount $bankAccount, string $startDate): bool
    {
        return JournalLine::query()
            ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
            ->whereHas('journalEntry', function ($query) use ($bankAccount, $startDate) {
                $query->where('wallet_id', $bankAccount->wallet_id)
                    ->whereDate('entry_date', '<', $startDate);
            })
            ->exists();
    }
}
