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
use App\Models\Wallet;
use App\Services\Financial\BankStatementService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BankStatementController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request, BankStatementService $service): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        $rawFilters = [
            'bank_account_id' => $request->string('bank_account_id')->toString(),
            'start_date' => $request->query('start_date') ?: now()->startOfMonth()->toDateString(),
            'end_date' => $request->query('end_date') ?: now()->toDateString(),
            'search' => $request->string('search')->toString(),
        ];

        $validated = validator($rawFilters, [
            'bank_account_id' => ['nullable', 'integer'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'search' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $filters = BankStatementFiltersDTO::fromArray($validated);
        $statement = $service->build($wallet, $filters)->toArray();

        $bankAccounts = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'bank_name',
                'bank_code',
                'agency',
                'account_number',
                'account_type',
            ])
            ->map(fn (BankAccount $account) => [
                'id' => $account->id,
                'label' => $this->formatBankAccountLabel($account),
                'name' => $account->name,
                'bank_name' => $account->bank_name,
                'bank_code' => $account->bank_code,
                'agency' => $account->agency,
                'account_number' => $account->account_number,
                'account_type' => $account->account_type,
            ])
            ->values();

        return Inertia::render('Financial/BankStatements/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'bankAccounts' => $bankAccounts,
            'filters' => $statement['filters'],
            'statementReady' => $statement['ready'],
            'selectedBankAccount' => $statement['bank_account'],
            'summary' => $statement['summary'],
            'transactions' => $statement['transactions'],
            'operations' => $this->operations($wallet, $statement),
        ]);
    }

    private function operations(Wallet $wallet, array $statement): array
    {
        if (! $statement['ready'] || ! $statement['bank_account']) {
            return [
                'actions' => [],
                'pending_ofx' => [
                    'count' => 0,
                    'total_in_cents' => 0,
                    'total_out_cents' => 0,
                    'transactions' => [],
                ],
                'recent_imports' => [],
                'recent_reconciliations' => [],
            ];
        }

        $bankAccount = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->findOrFail($statement['bank_account']['id']);

        $startDate = $statement['filters']['start_date'];
        $endDate = $statement['filters']['end_date'];

        $alreadyReconciledIds = BankReconciliationStatementItem::query()
            ->whereNotNull('bank_statement_import_transaction_id')
            ->whereHas('bankReconciliation', function ($query) use ($wallet, $bankAccount) {
                $query->where('wallet_id', $wallet->id)
                    ->where('bank_account_id', $bankAccount->id);
            })
            ->pluck('bank_statement_import_transaction_id')
            ->all();

        $pendingTransactions = BankStatementImportTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('bank_account_id', $bankAccount->id)
            ->where('status', 'imported')
            ->whereDate('posted_at', '>=', $startDate)
            ->whereDate('posted_at', '<=', $endDate)
            ->when($alreadyReconciledIds !== [], fn ($query) => $query->whereNotIn('id', $alreadyReconciledIds))
            ->with(['import:id,original_filename', 'journalEntry:id,status,description'])
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        return [
            'actions' => [
                'account_url' => route('bank-accounts.show', $bankAccount),
                'ofx_import_url' => route('ofx-imports.index', ['bank_account_id' => $bankAccount->id]),
                'reconciliation_url' => route('bank-reconciliations.create', [
                    'bank_account_id' => $bankAccount->id,
                    'period_start' => $startDate,
                    'period_end' => $endDate,
                ]),
            ],
            'pending_ofx' => [
                'count' => $pendingTransactions->count(),
                'total_in_cents' => (int) $pendingTransactions->where('direction', 'in')->sum('amount_cents'),
                'total_out_cents' => (int) $pendingTransactions->where('direction', 'out')->sum('amount_cents'),
                'transactions' => $pendingTransactions
                    ->map(fn (BankStatementImportTransaction $transaction) => [
                        'id' => $transaction->id,
                        'posted_at' => $transaction->posted_at,
                        'description' => $transaction->description,
                        'direction' => $transaction->direction,
                        'amount_cents' => $transaction->direction === 'in'
                            ? (int) $transaction->amount_cents
                            : -1 * (int) $transaction->amount_cents,
                        'fit_id' => $transaction->fit_id,
                        'import_filename' => $transaction->import?->original_filename,
                        'journal_entry_id' => $transaction->journal_entry_id,
                        'journal_entry_status' => $transaction->journalEntry?->status,
                    ])
                    ->values()
                    ->all(),
            ],
            'recent_imports' => $this->recentImports($wallet, $bankAccount),
            'recent_reconciliations' => $this->recentReconciliations($wallet, $bankAccount),
        ];
    }

    private function recentImports(Wallet $wallet, BankAccount $bankAccount): array
    {
        return BankStatementImport::query()
            ->where('wallet_id', $wallet->id)
            ->where('bank_account_id', $bankAccount->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (BankStatementImport $import) => [
                'id' => $import->id,
                'source' => $import->source,
                'original_filename' => $import->original_filename,
                'statement_started_at' => $import->statement_started_at,
                'statement_ended_at' => $import->statement_ended_at,
                'total_transactions' => $import->total_transactions,
                'imported_transactions' => $import->imported_transactions,
                'skipped_duplicates' => $import->skipped_duplicates,
                'status' => $import->status,
                'created_at' => $import->created_at,
            ])
            ->values()
            ->all();
    }

    private function recentReconciliations(Wallet $wallet, BankAccount $bankAccount): array
    {
        return BankReconciliation::query()
            ->where('wallet_id', $wallet->id)
            ->where('bank_account_id', $bankAccount->id)
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (BankReconciliation $reconciliation) => [
                'id' => $reconciliation->id,
                'period_start' => $reconciliation->period_start,
                'period_end' => $reconciliation->period_end,
                'statement_balance_cents' => $reconciliation->statement_balance_cents,
                'difference_cents' => $reconciliation->difference_cents,
                'status' => $reconciliation->status,
            ])
            ->values()
            ->all();
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
