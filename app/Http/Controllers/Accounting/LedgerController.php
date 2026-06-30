<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Models\ChartOfAccount;
use App\Models\JournalLine;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LedgerController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        $rawFilters = [
            'chart_of_account_id' => $request->string('chart_of_account_id')->toString(),
            'start_date' => $request->string('start_date')->toString(),
            'end_date' => $request->string('end_date')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        $accounts = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type'])
            ->map(fn (ChartOfAccount $account) => [
                'id' => $account->id,
                'label' => "{$account->code} - {$account->name}",
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
            ])
            ->values();

        $selectedAccount = null;
        $openingBalanceCents = 0;
        $totalDebitsCents = 0;
        $totalCreditsCents = 0;
        $closingBalanceCents = 0;
        $entries = [];
        $filters = $rawFilters;
        $errors = [];

        $hasEnoughFilters =
            $rawFilters['chart_of_account_id'] !== ''
            && $rawFilters['start_date'] !== ''
            && $rawFilters['end_date'] !== '';

        if ($hasEnoughFilters) {
            $validated = validator($request->all(), [
                'chart_of_account_id' => ['required', 'integer'],
                'start_date' => ['required', 'date'],
                'end_date' => ['required', 'date', 'after_or_equal:start_date'],
                'status' => ['nullable', 'in:draft,posted'],
            ])->validate();

            $filters = [
                'chart_of_account_id' => (string) $validated['chart_of_account_id'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'status' => $validated['status'] ?? '',
            ];

            $selectedAccount = ChartOfAccount::query()
                ->where('wallet_id', $wallet->id)
                ->findOrFail($filters['chart_of_account_id']);

            $normalSide = $this->normalBalanceSide($selectedAccount->type);

            $openingLines = JournalLine::query()
                ->where('chart_of_account_id', $selectedAccount->id)
                ->whereHas('journalEntry', function ($query) use ($wallet, $filters) {
                    $query->where('wallet_id', $wallet->id)
                        ->whereDate('entry_date', '<', $filters['start_date']);

                    if ($filters['status']) {
                        $query->where('status', $filters['status']);
                    }
                })
                ->get(['type', 'amount_cents']);

            $openingBalanceCents = $this->calculateBalanceFromLines($openingLines, $normalSide);

            $periodLines = JournalLine::query()
                ->with([
                    'journalEntry:id,wallet_id,entry_date,description,status',
                ])
                ->where('chart_of_account_id', $selectedAccount->id)
                ->whereHas('journalEntry', function ($query) use ($wallet, $filters) {
                    $query->where('wallet_id', $wallet->id)
                        ->whereDate('entry_date', '>=', $filters['start_date'])
                        ->whereDate('entry_date', '<=', $filters['end_date']);

                    if ($filters['status']) {
                        $query->where('status', $filters['status']);
                    }
                })
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->orderBy('journal_entries.entry_date')
                ->orderBy('journal_entries.id')
                ->orderBy('journal_lines.id')
                ->select('journal_lines.*')
                ->get();

            $runningBalance = $openingBalanceCents;

            $entries = $periodLines
                ->map(function (JournalLine $line) use (&$runningBalance, $normalSide, &$totalDebitsCents, &$totalCreditsCents) {
                    $entry = $line->journalEntry;

                    $debitCents = $line->type === 'debit' ? (int) $line->amount_cents : 0;
                    $creditCents = $line->type === 'credit' ? (int) $line->amount_cents : 0;

                    $totalDebitsCents += $debitCents;
                    $totalCreditsCents += $creditCents;

                    if ($normalSide === 'debit') {
                        $runningBalance += $debitCents;
                        $runningBalance -= $creditCents;
                    } else {
                        $runningBalance += $creditCents;
                        $runningBalance -= $debitCents;
                    }

                    return [
                        'id' => $line->id,
                        'date' => $entry?->entry_date,
                        'entry_id' => $entry?->id,
                        'entry_label' => $entry ? 'JE-' . str_pad((string) $entry->id, 6, '0', STR_PAD_LEFT) : '—',
                        'entry_show_url' => $entry ? route('journal-entries.show', $entry) : null,
                        'description' => $line->memo ?: $entry?->description,
                        'debit_cents' => $debitCents ?: null,
                        'credit_cents' => $creditCents ?: null,
                        'running_balance_cents' => $runningBalance,
                        'status' => $entry?->status,
                    ];
                })
                ->values();

            $closingBalanceCents = $runningBalance;
        }

        return Inertia::render('Accounting/Ledger/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'filters' => $filters,
            'accounts' => $accounts,
            'statuses' => [
                ['value' => 'draft', 'label' => 'Rascunho'],
                ['value' => 'posted', 'label' => 'Postado'],
            ],
            'selectedAccount' => $selectedAccount ? [
                'id' => $selectedAccount->id,
                'code' => $selectedAccount->code,
                'name' => $selectedAccount->name,
                'type' => $selectedAccount->type,
                'normal_balance_side' => $this->normalBalanceSide($selectedAccount->type),
            ] : null,
            'summary' => [
                'opening_balance_cents' => $openingBalanceCents,
                'total_debits_cents' => $totalDebitsCents,
                'total_credits_cents' => $totalCreditsCents,
                'closing_balance_cents' => $closingBalanceCents,
            ],
            'entries' => $entries,
            'ledgerReady' => $hasEnoughFilters,
        ]);
    }

    protected function normalBalanceSide(string $accountType): string
    {
        return in_array($accountType, ['ativo', 'despesa'], true)
            ? 'debit'
            : 'credit';
    }

    protected function calculateBalanceFromLines($lines, string $normalSide): int
    {
        $balance = 0;

        foreach ($lines as $line) {
            $debitCents = $line->type === 'debit' ? (int) $line->amount_cents : 0;
            $creditCents = $line->type === 'credit' ? (int) $line->amount_cents : 0;

            if ($normalSide === 'debit') {
                $balance += $debitCents;
                $balance -= $creditCents;
            } else {
                $balance += $creditCents;
                $balance -= $debitCents;
            }
        }

        return $balance;
    }
}