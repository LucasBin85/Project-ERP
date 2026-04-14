<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Wallet;
use App\Services\Accounting\PostJournalEntry;
use App\Services\Accounting\ReclassifyDraftEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;

class JournalEntryController extends Controller
{
    use ResolvesActiveWallet;
    public function index(Request $request): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        $entries = JournalEntry::query()
            ->where('wallet_id', $wallet->id)
            ->with(['lines.chartOfAccount'])
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('JournalEntries/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'entries' => $entries,
        ]);
    }

    public function show(Request $request, JournalEntry $journalEntry): Response
    {
        $wallet = $this->resolveActiveWallet($request);
        $this->ensureEntryBelongsToWallet($wallet, $journalEntry);

        $journalEntry->load([
            'lines.chartOfAccount',
            'wallet',
        ]);

        $classificationAccounts = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('id', '!=', $wallet->suspense_account_id)
            ->where('allows_posting', true)
            ->orderBy('code')
            ->get([
                'id',
                'code',
                'name',
                'type',
                'normal_balance',
            ]);

        return Inertia::render('JournalEntries/Show', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
                'suspense_account_id' => $wallet->suspense_account_id,
            ],
            'entry' => $journalEntry,
            'classificationAccounts' => $classificationAccounts,
        ]);
    }

    public function post(
        Request $request,
        JournalEntry $journalEntry,
        PostJournalEntry $postJournalEntry
    ): RedirectResponse {
        $wallet = $this->resolveActiveWallet($request);
        $this->ensureEntryBelongsToWallet($wallet, $journalEntry);

        try {
            $postJournalEntry->handle($journalEntry, true);

            return redirect()
                ->route('journal-entries.show', $journalEntry)
                ->with('success', 'Lançamento postado com sucesso.');
        } catch (RuntimeException $e) {
            return redirect()
                ->route('journal-entries.show', $journalEntry)
                ->with('error', $e->getMessage());
        }
    }

    public function reclassify(
        Request $request,
        JournalEntry $journalEntry,
        ReclassifyDraftEntry $reclassifyDraftEntry
    ): RedirectResponse {
        $wallet = $this->resolveActiveWallet($request);
        $this->ensureEntryBelongsToWallet($wallet, $journalEntry);

        $validated = $request->validate([
            'splits' => ['required', 'array', 'min:1'],
            'splits.*.chart_of_account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'splits.*.amount_cents' => ['required', 'integer', 'min:1'],
            'splits.*.memo' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $reclassifyDraftEntry->handle($journalEntry, $validated['splits']);

            return redirect()
                ->route('journal-entries.show', $journalEntry)
                ->with('success', 'Lançamento reclassificado com sucesso.');
        } catch (RuntimeException $e) {
            return redirect()
                ->route('journal-entries.show', $journalEntry)
                ->with('error', $e->getMessage());
        }
    }
/*
    protected function resolveActiveWallet(Request $request): Wallet
    {
        $user = $request->user();

        return $user
            ->wallets()
            ->findOrFail(session('active_wallet', $user->wallets()->first()->id));
    }
*/
    protected function ensureEntryBelongsToWallet(Wallet $wallet, JournalEntry $journalEntry): void
    {
        abort_unless((int) $journalEntry->wallet_id === (int) $wallet->id, 404);
    }
}