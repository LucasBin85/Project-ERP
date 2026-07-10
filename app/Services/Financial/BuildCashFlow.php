<?php

namespace App\Services\Financial;

use App\DTOs\Financial\CashFlowFiltersDTO;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\BankAccount;
use App\Models\CreditCardInvoice;
use App\Models\JournalLine;
use App\Models\Wallet;
use Illuminate\Support\Collection;

class BuildCashFlow
{
    public function handle(Wallet $wallet, CashFlowFiltersDTO $filters): array
    {
        $bankAccountIds = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('is_active', true)
            ->pluck('chart_of_account_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $openingBalance = $this->openingBalance($wallet, $bankAccountIds, $filters);

        $items = collect()
            ->merge($this->realizedItems($wallet, $bankAccountIds, $filters))
            ->merge($this->receivableItems($wallet, $filters))
            ->merge($this->payableItems($wallet, $filters))
            ->merge($this->creditCardInvoiceItems($wallet, $filters))
            ->when($filters->mode !== 'all', fn (Collection $items) => $items->where('bucket', $filters->mode))
            ->when($filters->search !== '', fn (Collection $items) => $items->filter(function (array $item) use ($filters) {
                $needle = str($filters->search)->lower()->toString();

                return str($item['description'])->lower()->contains($needle)
                    || str($item['counterparty'] ?? '')->lower()->contains($needle)
                    || str($item['source_label'])->lower()->contains($needle);
            }))
            ->sortBy([
                ['date', 'asc'],
                ['sort_order', 'asc'],
                ['description', 'asc'],
            ])
            ->values();

        $runningRealized = $openingBalance;
        $runningProjected = $openingBalance;

        $items = $items->map(function (array $item) use (&$runningRealized, &$runningProjected) {
            if ($item['bucket'] === 'realized') {
                $runningRealized += $item['amount_cents'];
                $runningProjected += $item['amount_cents'];
            } else {
                $runningProjected += $item['amount_cents'];
            }

            $item['running_realized_balance_cents'] = $runningRealized;
            $item['running_projected_balance_cents'] = $runningProjected;

            return $item;
        });

        $realizedInflows = $items->where('bucket', 'realized')->where('direction', 'inflow')->sum('amount_cents');
        $realizedOutflows = abs($items->where('bucket', 'realized')->where('direction', 'outflow')->sum('amount_cents'));
        $projectedInflows = $items->where('bucket', 'projected')->where('direction', 'inflow')->sum('amount_cents');
        $projectedOutflows = abs($items->where('bucket', 'projected')->where('direction', 'outflow')->sum('amount_cents'));

        return [
            'filters' => $filters->toArray(),
            'summary' => [
                'opening_balance_cents' => $openingBalance,
                'realized_inflows_cents' => (int) $realizedInflows,
                'realized_outflows_cents' => (int) $realizedOutflows,
                'projected_inflows_cents' => (int) $projectedInflows,
                'projected_outflows_cents' => (int) $projectedOutflows,
                'realized_net_cents' => (int) $realizedInflows - (int) $realizedOutflows,
                'projected_net_cents' => (int) $projectedInflows - (int) $projectedOutflows,
                'realized_closing_balance_cents' => $runningRealized,
                'projected_closing_balance_cents' => $runningProjected,
            ],
            'items' => $items->values()->all(),
        ];
    }

    private function openingBalance(Wallet $wallet, array $bankChartAccountIds, CashFlowFiltersDTO $filters): int
    {
        if ($bankChartAccountIds === []) {
            return 0;
        }

        return (int) JournalLine::query()
            ->whereIn('chart_of_account_id', $bankChartAccountIds)
            ->whereHas('journalEntry', function ($query) use ($wallet, $filters) {
                $query->where('wallet_id', $wallet->id)
                    ->where('status', 'posted')
                    ->whereDate('entry_date', '<', $filters->startDate);
            })
            ->get(['type', 'amount_cents'])
            ->reduce(function (int $balance, JournalLine $line) {
                return $line->type === 'debit'
                    ? $balance + (int) $line->amount_cents
                    : $balance - (int) $line->amount_cents;
            }, 0);
    }

    private function realizedItems(Wallet $wallet, array $bankChartAccountIds, CashFlowFiltersDTO $filters): Collection
    {
        if ($bankChartAccountIds === []) {
            return collect();
        }

        return JournalLine::query()
            ->with(['journalEntry:id,wallet_id,entry_date,description,status'])
            ->whereIn('chart_of_account_id', $bankChartAccountIds)
            ->whereHas('journalEntry', function ($query) use ($wallet, $filters) {
                $query->where('wallet_id', $wallet->id)
                    ->where('status', 'posted')
                    ->whereDate('entry_date', '>=', $filters->startDate)
                    ->whereDate('entry_date', '<=', $filters->endDate);
            })
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_lines.id')
            ->select('journal_lines.*')
            ->get()
            ->map(function (JournalLine $line) {
                $entry = $line->journalEntry;
                $amount = (int) $line->amount_cents;
                $signed = $line->type === 'debit' ? $amount : -$amount;

                return [
                    'id' => 'realized-' . $line->id,
                    'date' => $entry?->entry_date,
                    'bucket' => 'realized',
                    'source' => 'bank_movement',
                    'source_label' => 'Realizado',
                    'direction' => $signed >= 0 ? 'inflow' : 'outflow',
                    'description' => $line->memo ?: $entry?->description,
                    'counterparty' => null,
                    'status' => 'posted',
                    'amount_cents' => $signed,
                    'journal_entry_id' => $entry?->id,
                    'url' => $entry ? route('journal-entries.show', $entry) : null,
                    'sort_order' => 10,
                ];
            });
    }

    private function receivableItems(Wallet $wallet, CashFlowFiltersDTO $filters): Collection
    {
        return AccountReceivable::query()
            ->where('wallet_id', $wallet->id)
            ->where('status', 'pending')
            ->whereDate('due_date', '>=', $filters->startDate)
            ->whereDate('due_date', '<=', $filters->endDate)
            ->orderBy('due_date')
            ->get()
            ->map(fn (AccountReceivable $item) => [
                'id' => 'receivable-' . $item->id,
                'date' => $item->due_date,
                'bucket' => 'projected',
                'source' => 'accounts_receivable',
                'source_label' => 'A receber',
                'direction' => 'inflow',
                'description' => $item->description,
                'counterparty' => $item->customer_name,
                'status' => $item->status,
                'amount_cents' => (int) $item->amount_cents,
                'journal_entry_id' => null,
                'url' => route('accounts-receivable.show', $item),
                'sort_order' => 20,
            ]);
    }

    private function payableItems(Wallet $wallet, CashFlowFiltersDTO $filters): Collection
    {
        return AccountPayable::query()
            ->where('wallet_id', $wallet->id)
            ->where('status', 'pending')
            ->whereDate('due_date', '>=', $filters->startDate)
            ->whereDate('due_date', '<=', $filters->endDate)
            ->orderBy('due_date')
            ->get()
            ->map(fn (AccountPayable $item) => [
                'id' => 'payable-' . $item->id,
                'date' => $item->due_date,
                'bucket' => 'projected',
                'source' => 'accounts_payable',
                'source_label' => 'A pagar',
                'direction' => 'outflow',
                'description' => $item->description,
                'counterparty' => $item->payee_name,
                'status' => $item->status,
                'amount_cents' => -((int) $item->amount_cents),
                'journal_entry_id' => null,
                'url' => route('accounts-payable.show', $item),
                'sort_order' => 30,
            ]);
    }

    private function creditCardInvoiceItems(Wallet $wallet, CashFlowFiltersDTO $filters): Collection
    {
        return CreditCardInvoice::query()
            ->where('wallet_id', $wallet->id)
            ->whereIn('status', ['open', 'closed', 'partial', 'overdue'])
            ->where('balance_cents', '>', 0)
            ->whereDate('due_at', '>=', $filters->startDate)
            ->whereDate('due_at', '<=', $filters->endDate)
            ->with('creditCard:id,name')
            ->orderBy('due_at')
            ->get()
            ->map(fn (CreditCardInvoice $invoice) => [
                'id' => 'credit-card-invoice-' . $invoice->id,
                'date' => $invoice->due_at,
                'bucket' => 'projected',
                'source' => 'credit_card_invoice',
                'source_label' => 'Fatura cartão',
                'direction' => 'outflow',
                'description' => sprintf(
                    'Fatura %02d/%d - %s',
                    $invoice->reference_month,
                    $invoice->reference_year,
                    $invoice->creditCard?->name ?? 'Cartão'
                ),
                'counterparty' => $invoice->creditCard?->name,
                'status' => $invoice->status,
                'amount_cents' => -((int) $invoice->balance_cents),
                'journal_entry_id' => null,
                'url' => route('credit-cards.show', $invoice->credit_card_id),
                'sort_order' => 40,
            ]);
    }
}
