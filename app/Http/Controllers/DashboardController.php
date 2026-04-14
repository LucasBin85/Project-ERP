<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Carbon\Carbon;

class DashboardController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        // ----------------------------
        // Filtros
        // ----------------------------
        $startDate = $request->input('start_date') ?: now()->startOfMonth()->toDateString();
        $endDate   = $request->input('end_date') ?: now()->toDateString();

        // garante ordem correta
        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        // ----------------------------
        // Base query (linhas contábeis)
        // ----------------------------
        $baseLines = JournalLine::query()
            ->with(['chartOfAccount', 'journalEntry'])
            ->whereHas('journalEntry', function ($q) use ($wallet, $startDate, $endDate) {
                $q->where('wallet_id', $wallet->id)
                  ->where('status', 'posted')
                  ->whereDate('entry_date', '>=', $startDate)
                  ->whereDate('entry_date', '<=', $endDate);
            });

        // ----------------------------
        // KPIs
        // ----------------------------

        // Receitas (credit + tipo receita)
        $revenueCents = (clone $baseLines)
            ->where('type', 'credit')
            ->whereHas('chartOfAccount', fn ($q) => $q->where('type', 'receita'))
            ->sum('amount_cents');

        // Despesas (debit + tipo despesa)
        $expenseCents = (clone $baseLines)
            ->where('type', 'debit')
            ->whereHas('chartOfAccount', fn ($q) => $q->where('type', 'despesa'))
            ->sum('amount_cents');

        // Saldo (ativo)
        $assetDebit = (clone $baseLines)
            ->where('type', 'debit')
            ->whereHas('chartOfAccount', fn ($q) => $q->where('type', 'ativo'))
            ->sum('amount_cents');

        $assetCredit = (clone $baseLines)
            ->where('type', 'credit')
            ->whereHas('chartOfAccount', fn ($q) => $q->where('type', 'ativo'))
            ->sum('amount_cents');

        $balanceCents = $assetDebit - $assetCredit;

        // Resultado
        $resultCents = $revenueCents - $expenseCents;

        // ----------------------------
        // GRÁFICO (por dia)
        // ----------------------------

        $chartRevenue = (clone $baseLines)
            ->selectRaw('DATE(journal_entries.entry_date) as date, SUM(journal_lines.amount_cents) as total')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.chart_of_account_id')
            ->where('journal_lines.type', 'credit')
            ->where('chart_of_accounts.type', 'receita')
            ->groupByRaw('DATE(journal_entries.entry_date)')
            ->get()
            ->keyBy('date');

        $chartExpense = (clone $baseLines)
            ->selectRaw('DATE(journal_entries.entry_date) as date, SUM(journal_lines.amount_cents) as total')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.chart_of_account_id')
            ->where('journal_lines.type', 'debit')
            ->where('chart_of_accounts.type', 'despesa')
            ->groupByRaw('DATE(journal_entries.entry_date)')
            ->get()
            ->keyBy('date');

        // gera todos os dias do período
        $dates = collect();
        $cursor = Carbon::parse($startDate);
        $end    = Carbon::parse($endDate);

        while ($cursor->lte($end)) {
            $dates->push($cursor->toDateString());
            $cursor->addDay();
        }

        $chart = $dates->map(function ($date) use ($chartRevenue, $chartExpense) {
            return [
                'date' => $date,
                'revenue_cents' => (int) ($chartRevenue[$date]->total ?? 0),
                'expense_cents' => (int) ($chartExpense[$date]->total ?? 0),
            ];
        });

        // ----------------------------
        // Últimos lançamentos
        // ----------------------------
        $latestEntries = JournalEntry::query()
            ->where('wallet_id', $wallet->id)
            ->where('status', 'posted')
            ->whereDate('entry_date', '>=', $startDate)
            ->whereDate('entry_date', '<=', $endDate)
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->limit(8)
            ->get(['id', 'entry_date', 'description', 'source', 'status'])
            ->map(function ($entry) {
                return [
                    'id' => $entry->id,
                    'date' => $entry->entry_date,
                    'entry_label' => 'JE-' . str_pad($entry->id, 6, '0', STR_PAD_LEFT),
                    'entry_show_url' => route('journal-entries.show', $entry),
                    'description' => $entry->description,
                    'source' => $entry->source,
                    'status' => $entry->status,
                ];
            });

        // ----------------------------
        // RETORNO
        // ----------------------------
        return Inertia::render('Dashboard/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'kpis' => [
                'balance_cents' => $balanceCents,
                'revenue_cents' => $revenueCents,
                'expense_cents' => $expenseCents,
                'result_cents' => $resultCents,
            ],
            'chart' => $chart,
            'latestEntries' => $latestEntries,
        ]);
    }
}