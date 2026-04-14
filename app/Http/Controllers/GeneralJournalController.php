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

        $filters = [
            'start_date' => $request->string('start_date')->toString(),
            'end_date' => $request->string('end_date')->toString(),
            'source' => $request->string('source')->toString(),
            'status' => $request->string('status')->toString(),
            'search' => $request->string('search')->toString(),
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
            ->when($filters['search'], function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('description', 'like', "%{$search}%")
                        ->orWhereHas('lines', function ($lq) use ($search) {
                            $lq->where('memo', 'like', "%{$search}%")
                                ->orWhereHas('chartOfAccount', function ($aq) use ($search) {
                                    $aq->where('code', 'like', "%{$search}%")
                                        ->orWhere('name', 'like', "%{$search}%");
                                });
                        });
                });
            })
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(function (JournalEntry $entry) {
                return [
                    'id' => $entry->id,
                    'date' => $entry->entry_date,
                    'entry_label' => 'JE-' . str_pad((string) $entry->id, 6, '0', STR_PAD_LEFT),
                    'entry_show_url' => route('journal-entries.show', $entry),
                    'description' => $entry->description,
                    'status' => $entry->status,
                    'source' => $entry->source,
                    'lines' => $entry->lines
                        ->sortBy('id')
                        ->map(fn ($line) => [
                            'id' => $line->id,
                            'account_code' => $line->chartOfAccount?->code,
                            'account_name' => $line->chartOfAccount?->name,
                            'description' => $line->memo,
                            'debit_cents' => $line->type === 'debit' ? $line->amount_cents : null,
                            'credit_cents' => $line->type === 'credit' ? $line->amount_cents : null,
                        ])
                        ->values(),
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