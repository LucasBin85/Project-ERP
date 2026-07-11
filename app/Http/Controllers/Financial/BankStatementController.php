<?php

namespace App\Http\Controllers\Financial;

use App\DTOs\Financial\BankStatementFiltersDTO;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationStatementItem;
use App\Models\BankStatementImport;
use App\Models\BankStatementImportTransaction;
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
            'statementReady' => $statement['ready'],
            'selectedBankAccount' => $statement['bank_account'],
            'transactions' => $statement['transactions'],
            'operational' => $this->operationalContext(
                walletId: $wallet->id,
                bankAccount: $bankAccount,
                startDate: $filters->startDate,
                endDate: $filters->endDate,
            ),
        ]);
    }

    private function operationalContext(int $walletId, BankAccount $bankAccount, string $startDate, string $endDate): array
    {
        $alreadyReconciledIds = BankReconciliationStatementItem::query()
            ->whereNotNull('bank_statement_import_transaction_id')
            ->whereHas('bankReconciliation', function ($query) use ($walletId, $bankAccount) {
                $query->where('wallet_id', $walletId)
                    ->where('bank_account_id', $bankAccount->id);
            })
            ->pluck('bank_statement_import_transaction_id')
            ->all();

        $pendingOfxTransactions = BankStatementImportTransaction::query()
            ->where('wallet_id', $walletId)
            ->where('bank_account_id', $bankAccount->id)
            ->where('status', 'imported')
            ->whereDate('posted_at', '>=', $startDate)
            ->whereDate('posted_at', '<=', $endDate)
            ->when($alreadyReconciledIds !== [], fn ($query) => $query->whereNotIn('id', $alreadyReconciledIds))
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get([
                'id',
                'posted_at',
                'description',
                'amount_cents',
                'direction',
                'fit_id',
                'journal_entry_id',
            ]);

        $recentImports = BankStatementImport::query()
            ->where('wallet_id', $walletId)
            ->where('bank_account_id', $bankAccount->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get([
                'id',
                'original_filename',
                'statement_started_at',
                'statement_ended_at',
                'total_transactions',
                'imported_transactions',
                'skipped_duplicates',
                'status',
                'created_at',
            ]);

        $recentReconciliations = BankReconciliation::query()
            ->where('wallet_id', $walletId)
            ->where('bank_account_id', $bankAccount->id)
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->limit(5)
            ->get([
                'id',
                'period_start',
                'period_end',
                'statement_balance_cents',
                'reconciled_balance_cents',
                'difference_cents',
                'status',
            ]);

        return [
            'pending_ofx_transactions' => $pendingOfxTransactions,
            'pending_ofx_count' => $pendingOfxTransactions->count(),
            'recent_imports' => $recentImports,
            'recent_reconciliations' => $recentReconciliations,
            'has_older_transactions' => $this->hasOlderTransactions($walletId, $bankAccount, $startDate),
            'actions' => [
                'account_url' => route('bank-accounts.show', $bankAccount),
                'ofx_import_url' => route('ofx-imports.index', ['bank_account_id' => $bankAccount->id]),
                'reconciliation_url' => route('bank-reconciliations.create', [
                    'bank_account_id' => $bankAccount->id,
                    'period_start' => $startDate,
                    'period_end' => $endDate,
                ]),
            ],
        ];
    }

    private function hasOlderTransactions(int $walletId, BankAccount $bankAccount, string $startDate): bool
    {
        return JournalLine::query()
            ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
            ->whereHas('journalEntry', function ($query) use ($walletId, $startDate) {
                $query->where('wallet_id', $walletId)
                    ->where('status', 'posted')
                    ->whereDate('entry_date', '<', $startDate);
            })
            ->exists();
    }
}
