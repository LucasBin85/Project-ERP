<?php

namespace App\Services\Financial;

use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankTransfer;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\JournalLine;
use App\Models\Wallet;
use Illuminate\Support\Carbon;

class BuildBankAccountWorkspace
{
    public function __construct(
        private readonly BankAccountBalanceService $balances,
        private readonly BankAccountWorkflowSummaryService $workflowSummary,
    ) {}

    public function index(Wallet $wallet): array
    {
        $bankAccounts = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->with('chartOfAccount:id,code,name')
            ->orderBy('name')
            ->get();
        $balances = $this->balances->calculateMany($wallet, $bankAccounts);
        $accounts = $bankAccounts
            ->map(fn (BankAccount $account) => $this->accountOverview($wallet, $account, $balances[$account->id]));

        return [
            'accounts' => $accounts->values()->all(),
            'summary' => [
                'total_statement_balance_cents' => $accounts->sum('statement_balance_cents'),
                'total_accounting_balance_cents' => $accounts->sum('accounting_balance_cents'),
                'total_current_balance_cents' => $accounts->sum('statement_balance_cents'),
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

        $bankAccount->load(['chartOfAccount:id,code,name', 'bank:id,name,short_name']);

        $currentMonthStart = now()->startOfMonth()->toDateString();
        $today = now()->toDateString();
        $balances = $this->balances->calculate($wallet, $bankAccount);
        $workflowSummary = $this->workflowSummary->handle($wallet, $bankAccount);
        $monthMovements = $this->periodMovementTotals($wallet, $bankAccount, $currentMonthStart, $today);
        $creditCards = $this->linkedCreditCards($wallet, $bankAccount);

        return [
            'account' => $this->accountOverview($wallet, $bankAccount, $balances),
            'summary' => [
                'statement_balance_cents' => $balances['statement_balance_cents'],
                'accounting_balance_cents' => $balances['accounting_balance_cents'],
                'current_balance_cents' => $balances['statement_balance_cents'],
                'month_inflows_cents' => $monthMovements['inflows_cents'],
                'month_outflows_cents' => $monthMovements['outflows_cents'],
                'month_result_cents' => $monthMovements['inflows_cents'] - $monthMovements['outflows_cents'],
                'current_card_invoice_cents' => collect($creditCards)->sum(fn (array $card) => (int) ($card['current_invoice']['balance_cents'] ?? 0)),
                'open_reconciliations' => $this->openReconciliationsCount($wallet, $bankAccount),
                'linked_credit_cards' => count($creditCards),
                ...$workflowSummary,
            ],
            'recent_transactions' => $this->recentTransactions(
                $wallet,
                $bankAccount,
                $balances['accounting_balance_cents'],
            ),
            'recent_transfers' => $this->recentTransfers($wallet, $bankAccount),
            'credit_cards' => $creditCards,
            'actions' => $this->actions($bankAccount),
        ];
    }

    /**
     * @param  array{statement_balance_cents: int, accounting_balance_cents: int}  $balances
     */
    private function accountOverview(Wallet $wallet, BankAccount $account, array $balances): array
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
            'statement_balance_cents' => $balances['statement_balance_cents'],
            'accounting_balance_cents' => $balances['accounting_balance_cents'],
            'current_balance_cents' => $balances['statement_balance_cents'],
            'is_active' => (bool) $account->is_active,
            'chart_of_account' => $account->chartOfAccount,
            'last_transaction_at' => $this->lastTransactionDate($wallet, $account),
            'show_url' => route('bank-accounts.show', $account),
        ];
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

    private function recentTransactions(
        Wallet $wallet,
        BankAccount $bankAccount,
        int $accountingBalanceCents,
    ): array {
        $runningBalance = $accountingBalanceCents;

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
            ->limit(5)
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
            ->limit(3)
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
        if (! $bankAccount->bank_id) {
            return [];
        }

        return CreditCard::query()
            ->where('wallet_id', $wallet->id)
            ->where('issuer_bank_id', $bankAccount->bank_id)
            ->whereNull('parent_card_id')
            ->with('childCards:id,parent_card_id,name,card_type,last_four,is_active')
            ->orderBy('name')
            ->get()
            ->map(function (CreditCard $card) use ($wallet) {
                $invoice = $this->currentInvoice($wallet, $card);

                return [
                    'id' => $card->id,
                    'name' => $card->name,
                    'issuer_name' => $card->issuer_name,
                    'network' => $card->network,
                    'last_four' => $card->last_four,
                    'closing_day' => $card->closing_day,
                    'due_day' => $card->due_day,
                    'credit_limit_cents' => $card->credit_limit_cents,
                    'child_cards' => $card->childCards,
                    'current_invoice' => $invoice ? [
                        'id' => $invoice->id,
                        'reference_month' => $invoice->reference_month,
                        'reference_year' => $invoice->reference_year,
                        'due_at' => $invoice->due_at,
                        'total_cents' => $invoice->total_cents,
                        'paid_cents' => $invoice->paid_cents,
                        'balance_cents' => $invoice->balance_cents,
                        'status' => $invoice->status,
                    ] : null,
                ];
            })
            ->values()
            ->all();
    }

    private function currentInvoice(Wallet $wallet, CreditCard $card): ?CreditCardInvoice
    {
        $unpaid = CreditCardInvoice::query()
            ->where('wallet_id', $wallet->id)
            ->where('credit_card_id', $card->id)
            ->whereIn('status', ['open', 'closed', 'partial', 'overdue'])
            ->orderBy('due_at')
            ->first();

        if ($unpaid) {
            return $unpaid;
        }

        return CreditCardInvoice::query()
            ->where('wallet_id', $wallet->id)
            ->where('credit_card_id', $card->id)
            ->orderByDesc('reference_year')
            ->orderByDesc('reference_month')
            ->first();
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
                    ->whereIn('status', ['draft', 'posted']);
            })
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->orderByDesc('journal_entries.entry_date')
            ->value('journal_entries.entry_date');

        return $date ? Carbon::parse($date)->toDateString() : null;
    }

    private function actions(BankAccount $bankAccount): array
    {
        return [
            'statement_url' => route('bank-accounts.statement', $bankAccount),
            'transfer_url' => route('bank-transfers.create', ['from_bank_account_id' => $bankAccount->id]),
            'credit_card_create_url' => route('credit-cards.create', ['bank_account_id' => $bankAccount->id]),
        ];
    }
}
