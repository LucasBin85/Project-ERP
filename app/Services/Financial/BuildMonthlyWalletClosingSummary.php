<?php

namespace App\Services\Financial;

use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\JournalEntry;
use App\Models\Wallet;
use App\Services\Accounting\AssessJournalEntryPostingReadiness;
use Carbon\CarbonImmutable;

class BuildMonthlyWalletClosingSummary
{
    public function __construct(
        private readonly BuildBankStatementClosingSummary $bankClosing,
        private readonly AssessJournalEntryPostingReadiness $readiness,
    ) {}

    public function execute(Wallet $wallet, int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1)->startOfMonth();
        $end = $start->endOfMonth();
        $banks = BankAccount::query()->where('wallet_id', $wallet->id)->where('is_active', true)
            ->with('bank:id,name')->orderBy('name')->get()
            ->map(function (BankAccount $account) use ($wallet, $start, $end) {
                $summary = $this->bankClosing->execute($wallet, $account, $start->toDateString(), $end->toDateString());
                $pending = $summary['counts']['pending_classification'] + $summary['counts']['pending_links']
                    + $summary['counts']['pending_transfers'] + $summary['counts']['inconsistencies'];

                return [
                    'id' => $account->id,
                    'name' => $account->name,
                    'bank_name' => $account->bank?->name ?? $account->bank_name ?? 'Não informado',
                    'balances' => $summary['balances'],
                    'status' => $summary['status'],
                    'status_label' => $summary['status_label'],
                    'pending_count' => $pending,
                    'closing_url' => route('bank-accounts.closing.show', ['bankAccount' => $account->id, 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString()]),
                    'statement_url' => route('bank-accounts.statement', ['bankAccount' => $account->id, 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString()]),
                ];
            })->values();

        $accounting = $this->accounting($wallet, $start, $end);
        $bankHasIncomplete = $banks->contains(fn (array $bank) => in_array($bank['status'], ['incomplete', 'partially_posted'], true));
        $hasIncomplete = $bankHasIncomplete || $accounting['draft_incomplete'] > 0;
        $hasReady = $accounting['draft_ready'] > 0 || $banks->contains(fn (array $bank) => $bank['status'] === 'ready_for_accounting');
        $hasPosted = $accounting['posted'] > 0 || $banks->contains(fn (array $bank) => in_array($bank['status'], ['partially_posted', 'closed'], true));
        $hasActivity = $hasIncomplete || $hasReady || $hasPosted;
        $status = match (true) {
            $hasPosted && ($hasIncomplete || $hasReady) => 'partially_posted',
            $hasIncomplete || ! $hasActivity => 'incomplete',
            $hasReady => 'ready_for_accounting',
            default => 'closed',
        };

        $opening = (int) $banks->sum('balances.opening_operational_cents');
        $inflows = (int) $banks->sum('balances.inflows_cents');
        $outflows = (int) $banks->sum('balances.outflows_cents');
        $closing = (int) $banks->sum('balances.closing_operational_cents');
        $postedBalance = (int) $banks->sum('balances.posted_accounting_cents');

        return [
            'period' => ['year' => $year, 'month' => $month, 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'label' => $start->locale('pt_BR')->translatedFormat('F/Y')],
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'summary' => [
                'inflows_cents' => $inflows, 'outflows_cents' => $outflows, 'net_cash_change_cents' => $inflows - $outflows,
                'opening_operational_cents' => $opening, 'closing_operational_cents' => $closing,
                'posted_accounting_cents' => $postedBalance, 'difference_cents' => $closing - $postedBalance,
                'accounting_pending_count' => $accounting['draft_ready'] + $accounting['draft_incomplete'],
            ],
            'banks' => $banks->all(),
            'cards' => $this->cards($wallet, $year, $month),
            'payables' => $this->payables($wallet, $start, $end),
            'receivables' => $this->receivables($wallet, $start, $end),
            'accounting' => $accounting,
            'ready_entry_ids' => $accounting['ready_entry_ids'],
            'links' => $this->links($start, $end),
        ];
    }

    private function accounting(Wallet $wallet, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $entries = JournalEntry::query()->where('wallet_id', $wallet->id)->whereBetween('entry_date', [$start, $end])
            ->with('lines.chartOfAccount.children')->get();
        $drafts = $entries->where('status', 'draft');
        $ready = $drafts->filter(fn (JournalEntry $entry) => $this->readiness->handle($wallet, $entry)->ready);
        $suspense = $wallet->suspense_account_id;

        return [
            'draft_ready' => $ready->count(),
            'draft_incomplete' => $drafts->count() - $ready->count(),
            'unclassified' => $suspense ? $drafts->filter(fn (JournalEntry $entry) => $entry->lines->contains('chart_of_account_id', $suspense))->count() : 0,
            'unbalanced' => $drafts->filter(fn (JournalEntry $entry) => ! $entry->is_balanced || (int) $entry->balance_diff_cents !== 0)->count(),
            'posted' => $entries->where('status', 'posted')->count(),
            'ready_entry_ids' => $ready->pluck('id')->values()->all(),
        ];
    }

    private function cards(Wallet $wallet, int $year, int $month): array
    {
        return CreditCard::query()->where('wallet_id', $wallet->id)->where('is_active', true)
            ->where('card_type', 'main')->whereNull('parent_card_id')->orderBy('name')->get()
            ->map(function (CreditCard $card) use ($wallet, $year, $month) {
                $invoice = CreditCardInvoice::query()->where('wallet_id', $wallet->id)->where('credit_card_id', $card->id)
                    ->where('reference_year', $year)->where('reference_month', $month)->first();
                $status = match ($invoice?->status) {
                    null => 'unavailable', 'paid' => 'paid', 'partial' => 'partial', 'open', 'closed', 'overdue' => 'open', default => 'divergent',
                };

                return ['id' => $card->id, 'name' => $card->name, 'issuer_name' => $card->issuer_name,
                    'invoice_id' => $invoice?->id, 'total_cents' => $invoice?->total_cents, 'paid_cents' => $invoice?->paid_cents,
                    'balance_cents' => $invoice?->balance_cents, 'status' => $status,
                    'status_label' => match ($status) {
                        'paid' => 'Paga', 'partial' => 'Parcialmente paga', 'open' => 'Em aberto', 'divergent' => 'Com divergência', default => 'Sem fatura'
                    },
                    'url' => route('credit-cards.show', $card->id)];
            })->values()->all();
    }

    private function payables(Wallet $wallet, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $due = AccountPayable::query()->where('wallet_id', $wallet->id)->whereBetween('due_date', [$start, $end])->get();
        $paid = AccountPayable::query()->where('wallet_id', $wallet->id)->whereBetween('paid_at', [$start, $end])->get();
        $open = $due->where('status', 'pending');

        return ['due' => $this->amountCount($due), 'paid' => $this->amountCount($paid), 'open' => $this->amountCount($open),
            'overdue' => $this->amountCount($open->filter(fn ($item) => $item->due_date->isPast())), 'url' => route('accounts-payable.index', ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()])];
    }

    private function receivables(Wallet $wallet, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $due = AccountReceivable::query()->where('wallet_id', $wallet->id)->whereBetween('due_date', [$start, $end])->get();
        $received = AccountReceivable::query()->where('wallet_id', $wallet->id)->whereBetween('received_at', [$start, $end])->get();
        $open = $due->where('status', 'pending');

        return ['expected' => $this->amountCount($due), 'received' => $this->amountCount($received), 'open' => $this->amountCount($open),
            'overdue' => $this->amountCount($open->filter(fn ($item) => $item->due_date->isPast())), 'url' => route('accounts-receivable.index', ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()])];
    }

    private function amountCount($items): array
    {
        return ['count' => $items->count(), 'amount_cents' => (int) $items->sum('amount_cents')];
    }

    private function links(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $dates = ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()];

        return ['pending' => route('accounting.pending-entries.index'), 'journal' => route('general-journal.index', $dates),
            'ledger' => route('ledger.index', $dates), 'trial_balance' => route('trial-balance.index', $dates),
            'income_statement' => route('income-statement.index', $dates), 'balance_sheet' => route('balance-sheet.index', $dates),
            'financial_position' => route('financial-position.index', $dates)];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'ready_for_accounting' => 'Pronto para contabilizar', 'partially_posted' => 'Parcialmente postado', 'closed' => 'Fechado', default => 'Incompleto'
        };
    }
}
