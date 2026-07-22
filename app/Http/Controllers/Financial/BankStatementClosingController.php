<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Services\Accounting\BulkPostPendingJournalEntries;
use App\Services\Financial\BuildBankStatementClosingSummary;
use App\Services\Financial\BulkApplyBankStatementClassificationSuggestions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BankStatementClosingController extends Controller
{
    use ResolvesActiveWallet;

    public function show(Request $request, BankAccount $bankAccount, BuildBankStatementClosingSummary $service): Response
    {
        [$wallet, $start, $end] = $this->context($request, $bankAccount);

        return Inertia::render('Financial/BankStatementClosings/Show', [
            'wallet' => ['id' => $wallet->id, 'name' => $wallet->name],
            'summary' => $service->execute($wallet, $bankAccount, $start, $end),
            'postingResult' => $request->session()->get('pending_entries_posting_result'),
            'classificationResult' => $request->session()->get('classification_bulk_result'),
        ]);
    }

    public function applySuggestions(Request $request, BankAccount $bankAccount, BuildBankStatementClosingSummary $summary, BulkApplyBankStatementClassificationSuggestions $apply): RedirectResponse
    {
        [$wallet, $start, $end] = $this->context($request, $bankAccount);
        $data = $summary->execute($wallet, $bankAccount, $start, $end);
        if ($data['suggestion_items'] === []) {
            return back()->with('success', 'Não há sugestões de alta confiança disponíveis neste período.');
        }
        $result = $apply->execute($wallet, $bankAccount, $data['suggestion_items']);

        return back()->with('classification_bulk_result', $result)->with('success', "{$result['applied']} sugestões aplicadas; {$result['ignored']} ignoradas; {$result['failed']} falhas.");
    }

    public function postReady(Request $request, BankAccount $bankAccount, BuildBankStatementClosingSummary $summary, BulkPostPendingJournalEntries $posting): RedirectResponse
    {
        [$wallet, $start, $end] = $this->context($request, $bankAccount);
        $data = $summary->execute($wallet, $bankAccount, $start, $end);
        if ($data['ready_entry_ids'] === []) {
            return back()->with('success', 'Não há lançamentos prontos para contabilizar neste período.');
        }
        $result = $posting->selected($wallet, $data['ready_entry_ids'])->toArray();

        return back()->with('pending_entries_posting_result', $result)->with('success', $result['message']);
    }

    private function context(Request $request, BankAccount $bankAccount): array
    {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless((int) $bankAccount->wallet_id === (int) $wallet->id, 404);
        $data = validator(['start_date' => $request->input('start_date', now()->startOfMonth()->toDateString()), 'end_date' => $request->input('end_date', now()->toDateString())], [
            'start_date' => ['required', 'date'], 'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ])->validate();

        return [$wallet, $data['start_date'], $data['end_date']];
    }
}
