<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Services\Accounting\BulkPostPendingJournalEntries;
use App\Services\Financial\BuildMonthlyWalletClosingSummary;
use App\Services\Financial\ManageMonthlyWalletClosing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MonthlyWalletClosingController extends Controller
{
    use ResolvesActiveWallet;

    public function show(Request $request, BuildMonthlyWalletClosingSummary $service): Response
    {
        [$wallet, $year, $month] = $this->context($request);

        return Inertia::render('Financial/MonthlyClosings/Show', [
            'wallet' => ['id' => $wallet->id, 'name' => $wallet->name],
            'closing' => $service->execute($wallet, $year, $month),
        ]);
    }

    public function postReady(Request $request, BuildMonthlyWalletClosingSummary $service, BulkPostPendingJournalEntries $posting): RedirectResponse
    {
        [$wallet, $year, $month] = $this->context($request);
        $ids = $service->execute($wallet, $year, $month)['ready_entry_ids'];
        if ($ids === []) {
            return back()->with('success', 'Não há lançamentos prontos para contabilizar neste mês.');
        }

        $result = $posting->selected($wallet, $ids)->toArray();

        return back()->with('pending_entries_posting_result', $result)->with('success', $result['message']);
    }

    public function close(Request $request, ManageMonthlyWalletClosing $service): RedirectResponse
    {
        [$wallet, $year, $month] = $this->context($request);
        $data = $request->validate(['close_note' => ['nullable', 'string', 'max:2000']]);
        $service->close($wallet, $request->user(), $year, $month, $data['close_note'] ?? null);

        return back()->with('success', 'Mês fechado com sucesso. Alterações neste período estão bloqueadas.');
    }

    public function reopen(Request $request, ManageMonthlyWalletClosing $service): RedirectResponse
    {
        [$wallet, $year, $month] = $this->context($request);
        $data = $request->validate(['reopen_reason' => ['required', 'string', 'min:3', 'max:2000']]);
        $service->reopen($wallet, $request->user(), $year, $month, $data['reopen_reason']);

        return back()->with('success', 'Mês reaberto. Alterações no período estão novamente permitidas.');
    }

    private function context(Request $request): array
    {
        $wallet = $this->resolveActiveWallet($request);
        $data = validator(['year' => $request->input('year', now()->year), 'month' => $request->input('month', now()->month)], [
            'year' => ['required', 'integer', 'min:2000', 'max:2100'], 'month' => ['required', 'integer', 'between:1,12'],
        ])->validate();

        return [$wallet, (int) $data['year'], (int) $data['month']];
    }
}
