<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Services\Accounting\BuildPendingJournalEntries;
use App\Services\Accounting\BulkPostPendingJournalEntries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PendingJournalEntryController extends Controller
{
    use ResolvesActiveWallet;

    public function index(
        Request $request,
        BuildPendingJournalEntries $pendingEntries,
    ): Response {
        $wallet = $this->resolveActiveWallet($request);
        $entries = $pendingEntries->handle($wallet);

        return Inertia::render('Accounting/PendingEntries/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'entries' => $entries,
            'summary' => [
                'ready_count' => count($entries),
                'ready_amount_cents' => collect($entries)->sum('amount_cents'),
            ],
            'postingResult' => $request->session()->get('pending_entries_posting_result'),
        ]);
    }

    public function postSelected(
        Request $request,
        BulkPostPendingJournalEntries $bulkPosting,
    ): JsonResponse|RedirectResponse {
        $wallet = $this->resolveActiveWallet($request);
        $validated = $request->validate([
            'entry_ids' => ['required', 'array', 'min:1'],
            'entry_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $result = $bulkPosting->selected($wallet, $validated['entry_ids']);

        return $this->postingResponse($request, $result->toArray());
    }

    public function postAll(
        Request $request,
        BulkPostPendingJournalEntries $bulkPosting,
    ): JsonResponse|RedirectResponse {
        $wallet = $this->resolveActiveWallet($request);
        $result = $bulkPosting->allReady($wallet);

        return $this->postingResponse($request, $result->toArray());
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function postingResponse(
        Request $request,
        array $result,
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            return response()->json($result);
        }

        return redirect()
            ->route('accounting.pending-entries.index')
            ->with('success', $result['message'])
            ->with('pending_entries_posting_result', $result);
    }
}
