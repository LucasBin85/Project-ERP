<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GeneralJournalController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request): Response
    {
        $wallet = $this->resolveActiveWallet($request);
        $startDate = $request->query('start_date') ?: now()->startOfYear()->toDateString();
        $endDate = $request->query('end_date') ?: now()->toDateString();

        $filters = [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'source' => $request->query('source'),
            'status' => $request->query('status'),
        ];

        $entries = JournalEntry::query()
            ->where('wallet_id', $wallet->id)
            ->with([
                'lines.chartOfAccount:id,wallet_id,code,name',
            ])
            ->when($filters['start_date'], fn ($q, $v) => $q->whereDate('entry_date', '>=', $v))
            ->when($filters['end_date'], fn ($q, $v) => $q->whereDate('entry_date', '<=', $v))
            ->when($filters['source'], fn ($q, $v) => $q->where('source', $v))
            ->when($filters['status'], fn ($q, $v) => $q->where('status', $v))
            
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(function (JournalEntry $entry) {
                $lines = $entry->lines
                    ->sortBy('id')
                    ->map(fn ($line) => [
                        'id' => $line->id,
                        'account_code' => $line->chartOfAccount?->code,
                        'account_name' => $line->chartOfAccount?->name,
                        'description' => $line->memo,
                        'debit_cents' => $line->type === 'debit' ? $line->amount_cents : null,
                        'credit_cents' => $line->type === 'credit' ? $line->amount_cents : null,
                    ])
                    ->values();

                return [
                    'id' => $entry->id,
                    'entry_date' => $entry->entry_date,
                    'date' => $entry->entry_date,
                    'entry_label' => 'JE-' . str_pad((string) $entry->id, 6, '0', STR_PAD_LEFT),
                    'entry_show_url' => route('journal-entries.show', $entry),
                    'description' => $entry->description,
                    'status' => $entry->status,
                    'source' => $entry->source,

                    'debit_total_cents' => $lines->sum('debit_cents'),
                    'credit_total_cents' => $lines->sum('credit_cents'),

                    'lines' => $lines,
                ];
            });

        return Inertia::render('GeneralJournal/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'entries' => $entries,
            'filters' => $filters,
            'sources' => [
                ['value' => 'manual', 'label' => 'Manual'],
                ['value' => 'ofx', 'label' => 'OFX'],
                ['value' => 'open_finance', 'label' => 'Open Finance'],
            ],
            'statuses' => [
                ['value' => 'draft', 'label' => 'Rascunho'],
                ['value' => 'posted', 'label' => 'Postado'],
            ],
        ]);
    }
}