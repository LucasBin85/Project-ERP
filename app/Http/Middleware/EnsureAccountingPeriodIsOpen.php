<?php

namespace App\Http\Middleware;

use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\JournalEntry;
use App\Services\Accounting\EnsureAccountingPeriodIsOpen as PeriodGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountingPeriodIsOpen
{
    public function __construct(private readonly PeriodGuard $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || in_array($request->route()?->getName(), ['monthly-closing.close', 'monthly-closing.reopen'], true)) {
            return $next($request);
        }
        $user = $request->user();
        $wallet = $user?->wallets()->find(session('active_wallet', $user?->wallets()->value('wallets.id')));
        if (! $wallet) {
            return $next($request);
        }

        foreach ($this->dates($request) as $date) {
            $this->guard->handle($wallet, $date);
        }

        return $next($request);
    }

    private function dates(Request $request): array
    {
        $dates = collect(['entry_date', 'due_date', 'paid_at', 'received_at', 'transfer_date', 'payment_date', 'purchase_date'])
            ->map(fn ($key) => $request->input($key))->filter();
        foreach (['journalEntry', 'accountPayable', 'accountReceivable'] as $key) {
            $model = $request->route($key);
            if ($model instanceof JournalEntry) {
                $dates->push($model->entry_date);
            }
            if ($model instanceof AccountPayable) {
                $dates->push($request->input('paid_at', $model->due_date));
            }
            if ($model instanceof AccountReceivable) {
                $dates->push($request->input('received_at', $model->due_date));
            }
        }
        $ids = collect($request->input('journal_entry_ids', []))->merge($request->input('entry_ids', []));
        $ids = $ids->merge(collect($request->input('items', []))->pluck('journal_entry_id'))
            ->merge(collect($request->input('rows', []))->pluck('journal_entry_id'))->filter()->unique();
        if ($ids->isNotEmpty()) {
            $dates = $dates->merge(JournalEntry::query()->whereIn('id', $ids)->pluck('entry_date'));
        }

        if ($token = $request->input('preview_token')) {
            $preview = Cache::get('ofx-import-preview:'.$token)['preview']['rows'] ?? [];
            $dates = $dates->merge(collect($preview)->map(fn ($row) => $row['date'] ?? $row['posted_at'] ?? null)->filter());
        }

        return $dates->map(fn ($date) => (string) $date)->unique()->values()->all();
    }
}
