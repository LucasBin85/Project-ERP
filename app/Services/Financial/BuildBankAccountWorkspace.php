<?php

namespace App\Services\Financial;

use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationStatementItem;
use App\Models\BankStatementImport;
use App\Models\BankStatementImportTransaction;
use App\Models\BankTransfer;
use App\Models\CreditCard;
use App\Models\JournalLine;
use App\Models\Wallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BuildBankAccountWorkspace
{
    public function index(Wallet $wallet): array
    {
        $accounts = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->with('chartOfAccount:id,code,name')
            ->orderBy('name')
            ->get()
            ->map(fn (BankAccount $account) => $this->accountOverview($wallet, $account));

        return [
            'accounts' => $accounts->values()->all(),
            'summary' => [
                'total_current_balance_cents' => $accounts->sum('current_balance_cents'),
                'total_opening_balance_cents' => $accounts->sum('opening_balance_cents'),
                'active_accounts' => $accounts->where('is_active', true)->count(),
                'inactive_accounts' => $accounts->where('is_active', false)->count(),
                'accounts_count' => $accounts->count(),
            ],
        ];
    }

    public function show(Wallet $wallet, BankAccount $bankAccount): array
    {
        abort_unless($bankAccount->wallet_id === $wallet->id, 404);

        $bankAccount->load('chartOfAccount:id,code,name');

        $currentMonthStart = now()->startOfMonth()->toDateString();
        $today = now()->toDateString();
        $currentBalance = $this->currentBalanceCents($wallet, $bankAccount);
        $monthMovements = $this->periodMovementTotals($wallet, $bankAccount, $currentMonthStart, $today);

        return [
            'account' => $this->accountOverview($wallet, $bankAccount),
            'summary' => [
                'current_balance_cents' => $currentBalance,
                'month_inflows_cents' => $monthMovements['inflows_cents'],
                'month_outflows_cents' => $monthMovements['outflows_cents'],
                'month_result_cents' => $monthMovements['inflows_cents'] - $monthMovements['outflows_cents'],
                'pending_ofx_transactions' => $this->pendingOfxTransactionsCount($wallet, $bankAccount),
                'open_reconciliations' => $this->openReconciliationsCount($wallet, $bankAccount),
                'linked_credit_cards' => CreditCard::query()
                    ->where('wallet_id', $wallet->id)
                    ->where('bank_account_id', $bankAccount->id)
                    ->whereNull('parent_card_id')
                    ->count(),
            ],
            'recent_transactions' => $this->recentTransactions($wallet, $bankAccount),
            'recent_imports' => $this->recentImports($wallet, $bankAccount),
            'recent_reconciliations' => $this->recentReconciliations($wallet, $bankAccount),
            'recent_transfers' => $this->recentTransfers($wallet, $bankAccount),
            'credit_cards' => $this->linkedCreditCards($wallet, $bankAccount),
            'actions' => $this->actions($bankAccount),
        ];
    }

    private function accountOverview(Wallet $wallet, BankAccount $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'bank_name' => $account->bank_name,
            'bank_code' => $account->bank_code,
            'agency' => $account->agency,
            'account_number' => $account->account_number,
            'account_type' => $account->account_type,
            'opening_balance_cents' => (int) $account->opening_balance_cents,
            'current_balance_cents' => $this->currentBalanceCents($wallet, $account),
            'is_active' => (bool) $account->is_active,
            'chart_of_account' => $account->chartOfAccount,
            'last_transaction_at' => $this->lastTransactionDate($wallet, $account),
        ];
    }

    private function currentBalanceCents(Wallet $wallet, BankAccount $bankAccount): int
    {
        $lines = JournalLine::query()
            ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
            ->whereHas('journalEntry', function ($query) use ($wallet) {
                $query->where('wallet_id', $wallet->id)
                    ->where('status', 'posted');
            })
            ->get(['type', 'amount_cents']);

        return $this->debitBalance($lines);
    }

    private function periodMovementTotals(Wallet $wallet, BankAccount $bankAccount, string $startDate, string $endDate): array
    {
        $lines = JournalLine::query()
            ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
            ->whereHas('journalEntry', function ($query) use ($wallet, $startDate, $endDate) {
                $query->where('wallet_id', $wallet->id)
                    ->where('status', 'posted')
                    ->whereDate('entry_date', '>=', $startDate)
                    ->whereDate('entry_date', '<=', $endDate);
            })
            ->get(['type', 'amount_cents']);

        return [
            'inflows_cents' => (int) $lines->where('type', 'debit')->sum('amount_cents'),
            'outflows_cents' => (int) $lines->where('type', 'credit')->sum('amount_cents'),
        ];
    }

    private function recentTransactions(Wallet $wallet, BankAccount $bankAccount): array
    {
        $runningBalance = $this->currentBalanceCents($wallet, $bankAccount);

        return JournalLine::query()
            ->with('journalEntry:id,wallet_id,entry_date,description,status,source')
            ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
            ->whereHas('journalEntry', function ($query) use ($wallet) {
                $query->where('wallet_id', $wallet->id)
                    ->where('status', 'posted');
            })
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->orderByDesc('journal_entries.entry_date')
            ->orderByDesc('journal_entries.id')
            ->orderByDesc('journal_lines.id')
            ->select('journal_lines.*')
            ->limit(15)
            ->get()
            ->map(function (JournalLine $line) use (&$runningBalance) {
                $signedAmount = $line->type === 'debit'
                    ? (int) $line->amount_cents
                    : -1 * (int) $line->amount_cents;

                $item = [
                    'id' => $line->id,
                    'date' => $line->journalEntry?->entry_date,
                    'journal_entry_id' => $line->journalEntry?->id,
                    'description' => $line->memo ?: $line->journalEntry?->description,
                    'source' => $line->journalEntry?->source,
                    'type' => $signedAmount >= 0 ? 'inflow' : 'outflow',
                    'amount_cents' => $signedAmount,
                    'running_balance_cents' => $runningBalance,
                ];

                $runningBalance -= $signedAmount;

                return $item;
            })
            ->values()
            ->all();
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
                'total_in_cents' => $import->total_in_cents,
                'total_out_cents' => $import->total_out_cents,
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
                'book_balance_cents' => $reconciliation->book_balance_cents,
                'difference_cents' => $reconciliation->difference_cents,
                'status' => $reconciliation->status,
            ])
            ->values()
            ->all();
    }

    private function recentTransfers(Wallet $wallet, BankAccount $bankAccount): array
    {
        return BankTransfer::query()
            ->where('wallet_id', $wallet->id)
            ->where(function ($query) use ($bankAccount) {
                $query->where('from_bank_account_id', $bankAccount->id)
                    ->orWhere('to_bank_account_id', $bankAccount->id);
            })
            ->with(['fromBankAccount:id,name', 'toBankAccount:id,name'])
            ->orderByDesc('transfer_date')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (BankTransfer $transfer) => [
                'id' => $transfer->id,
                'transfer_date' => $transfer->transfer_date,
                'description' => $transfer->description,
                'amount_cents' => $transfer->amount_cents,
                'direction' => (int) $transfer->to_bank_account_id === (int) $bankAccount->id ? 'in' : 'out',
                'from_bank_account' => $transfer->fromBankAccount,
                'to_bank_account' => $transfer->toBankAccount,
                'journal_entry_id' => $transfer->journal_entry_id,
                'status' => $transfer->status,
            ])
            ->values()
            ->all();
    }

    private function linkedCreditCards(Wallet $wallet, BankAccount $bankAccount): array
    {
        return CreditCard::query()
            ->where('wallet_id', $wallet->id)
            ->where('bank_account_id', $bankAccount->id)
            ->whereNull('parent_card_id')
            ->with('childCards:id,parent_card_id,name,card_type,last_four,is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (CreditCard $card) => [
                'id' => $card->id,
                'name' => $card->name,
                'issuer_name' => $card->issuer_name,
                'network' => $card->network,
                'last_four' => $card->last_four,
                'closing_day' => $card->closing_day,
                'due_day' => $card->due_day,
                'credit_limit_cents' => $card->credit_limit_cents,
                'child_cards' => $card->childCards,
            ])
            ->values()
            ->all();
    }

    private function pendingOfxTransactionsCount(Wallet $wallet, BankAccount $bankAccount): int
    {
        $alreadyReconciledIds = BankReconciliationStatementItem::query()
            ->whereNotNull('bank_statement_import_transaction_id')
            ->whereHas('bankReconciliation', function ($query) use ($wallet, $bankAccount) {
                $query->where('wallet_id', $wallet->id)
                    ->where('bank_account_id', $bankAccount->id);
            })
            ->pluck('bank_statement_import_transaction_id')
            ->all();

        return BankStatementImportTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('bank_account_id', $bankAccount->id)
            ->where('status', 'imported')
            ->when($alreadyReconciledIds !== [], fn ($query) => $query->whereNotIn('id', $alreadyReconciledIds))
            ->count();
    }

    private function openReconciliationsCount(Wallet $wallet, BankAccount $bankAccount): int
    {
        return BankReconciliation::query()
            ->where('wallet_id', $wallet->id)
            ->where('bank_account_id', $bankAccount->id)
            ->where('status', '!=', 'completed')
            ->count();
    }

    private function lastTransactionDate(Wallet $wallet, BankAccount $bankAccount): ?string
    {
        $date = JournalLine::query()
            ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
            ->whereHas('journalEntry', function ($query) use ($wallet) {
                $query->where('wallet_id', $wallet->id)
                    ->where('status', 'posted');
            })
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->orderByDesc('journal_entries.entry_date')
            ->value('journal_entries.entry_date');

        return $date ? Carbon::parse($date)->toDateString() : null;
    }

    private function debitBalance(Collection $lines): int
    {
        return $lines->reduce(function (int $balance, JournalLine $line) {
            $amount = (int) $line->amount_cents;

            return $line->type === 'debit'
                ? $balance + $amount
                : $balance - $amount;
        }, 0);
    }

    private function actions(BankAccount $bankAccount): array
    {
        return [
            'statement_url' => route('bank-statements.index', ['bank_account_id' => $bankAccount->id]),
            'ofx_import_url' => route('ofx-imports.index', ['bank_account_id' => $bankAccount->id]),
            'reconciliation_url' => route('bank-reconciliations.create', ['bank_account_id' => $bankAccount->id]),
            'transfer_url' => route('bank-transfers.create', ['from_bank_account_id' => $bankAccount->id]),
            'credit_card_create_url' => route('credit-cards.create', ['bank_account_id' => $bankAccount->id]),
        ];
    }
}
