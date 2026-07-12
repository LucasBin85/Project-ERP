<?php

namespace App\Http\Controllers\Financial;

use App\DTOs\Financial\BankStatementFiltersDTO;
use App\DTOs\Financial\OfxClassificationDTO;
use App\Exceptions\OfxClassificationException;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Financial\BankStatementService;
use App\Services\Financial\ClassifyOfxDraftEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            'classificationAccounts' => $this->classificationAccounts($wallet->id, $wallet->suspense_account_id),
            'operational' => $this->operationalContext(
                bankAccount: $bankAccount,
                startDate: $filters->startDate,
            ),
        ]);
    }

    public function classify(
        Request $request,
        BankAccount $bankAccount,
        JournalEntry $journalEntry,
        ClassifyOfxDraftEntry $service,
    ): RedirectResponse {
        $wallet = $this->resolveActiveWallet($request);

        abort_unless((int) $bankAccount->wallet_id === (int) $wallet->id, 404);
        abort_unless((int) $journalEntry->wallet_id === (int) $wallet->id, 404);

        $data = $request->validate([
            'chart_of_account_id' => [
                'required',
                'integer',
                Rule::exists('chart_of_accounts', 'id')
                    ->where('wallet_id', $wallet->id)
                    ->where('allows_posting', true),
            ],
            'should_post' => ['required', 'boolean'],
        ]);

        try {
            $service->execute(
                wallet: $wallet,
                bankAccount: $bankAccount,
                entry: $journalEntry,
                dto: OfxClassificationDTO::fromArray($data),
            );
        } catch (OfxClassificationException $exception) {
            return back()->withErrors([
                'chart_of_account_id' => $exception->getMessage(),
            ]);
        }

        return back()->with(
            'success',
            $data['should_post']
                ? 'Lançamento OFX classificado e postado com sucesso.'
                : 'Lançamento OFX classificado com sucesso.',
        );
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

    private function classificationAccounts(int $walletId, ?int $suspenseAccountId): array
    {
        return ChartOfAccount::query()
            ->where('wallet_id', $walletId)
            ->where('allows_posting', true)
            ->when($suspenseAccountId, fn ($query) => $query->where('id', '!=', $suspenseAccountId))
            ->whereDoesntHave('children')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type'])
            ->map(fn (ChartOfAccount $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
            ])
            ->values()
            ->all();
    }
}
