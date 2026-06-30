<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Wallet;
use App\Services\Accounting\CreateJournalEntry;
use App\Services\Accounting\PostJournalEntry;
use App\Services\Accounting\ReclassifyDraftEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

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

        return Inertia::render('Accounting/JournalEntries/Index', [
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

    public function post(Request $request, JournalEntry $journalEntry)
    {
        $wallet = $this->resolveActiveWallet($request);

        if ((int) $journalEntry->wallet_id !== (int) $wallet->id) {
            abort(404);
        }

        if ($journalEntry->status === 'posted') {
            return back()->with('error', 'Este lançamento já foi postado.');
        }

        $journalEntry->load('lines.chartOfAccount');

        $debits = $journalEntry->lines
            ->where('type', 'debit')
            ->sum('amount_cents');

        $credits = $journalEntry->lines
            ->where('type', 'credit')
            ->sum('amount_cents');

        if ($debits <= 0 || $credits <= 0 || $debits !== $credits) {
            return back()->with('error', 'O lançamento precisa estar balanceado para ser postado.');
        }

        $hasSuspenseLine = $journalEntry->lines->contains(function ($line) use ($wallet) {
            return (int) $line->chart_of_account_id === (int) $wallet->suspense_account_id;
        });

        if ($hasSuspenseLine) {
            return back()->with('error', 'Reclassifique a conta transitória antes de postar.');
        }

        foreach ($journalEntry->lines as $line) {
            if (! $line->chartOfAccount?->isPostingAllowed()) {
                return back()->with('error', 'Todas as contas precisam permitir lançamento.');
            }
        }

        $journalEntry->update([
            'status' => 'posted',
            'posted_at' => now(),
            'is_balanced' => true,
            'debit_total' => $debits,
            'credit_total' => $credits,
            'diff_total' => 0,
        ]);

        return back()->with('success', 'Lançamento postado com sucesso.');
    }

        /**
        * Reclassifica um lançamento em draft movendo o valor da conta de suspense para outras contas.
        *
        * Regras:
        * - O lançamento deve estar em status 'draft'.
        * - O lançamento deve pertencer à wallet do usuário.
        * - A wallet deve ter uma conta de suspense configurada.
        * - O lançamento deve conter exatamente uma linha utilizando a conta de suspense.
        * - A soma dos splits deve ser igual ao valor da linha de suspense.
        * - As contas de destino dos splits devem permitir lançamentos.
        **/

    public function create(Request $request): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        $accounts = $wallet->chartOfAccounts()
            ->where('allows_posting', true)
            ->orderBy('code')
            ->get()
            ->map(fn ($account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'label' => "{$account->code} - {$account->name}",
            ]);

        return Inertia::render('JournalEntries/Create', [
            'accounts' => $accounts,
        ]);
    }

    public function store(Request $request, CreateJournalEntry $service)
    {
        $wallet = $this->resolveActiveWallet($request);

        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],

            'lines' => ['required', 'array', 'min:2'],

            'lines.*.chart_of_account_id' => [
                'required',
                'integer',
                Rule::exists('chart_of_accounts', 'id')
                    ->where(function ($query) use ($wallet) {
                        $query
                            ->where('wallet_id', $wallet->id)
                            ->where('allows_posting', true);
                    }),
            ],

            'lines.*.type' => [
                'required',
                Rule::in(['debit', 'credit']),
            ],

            'lines.*.amount_cents' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        $debits = collect($data['lines'])
            ->where('type', 'debit')
            ->sum('amount_cents');

        $credits = collect($data['lines'])
            ->where('type', 'credit')
            ->sum('amount_cents');

        if ($debits <= 0 || $credits <= 0) {
            return back()->withErrors([
                'lines' => 'É necessário informar ao menos um débito e um crédito.',
            ])->withInput();
        }

        if ($debits !== $credits) {
            return back()->withErrors([
                'lines' => 'O lançamento não está balanceado.',
            ])->withInput();
        }

        $data['wallet_id'] = $wallet->id;
        $data['source'] = 'manual';
        $data['status'] = 'draft';

        $journalEntry = $service->execute($data);

        return redirect()
            ->route('journal-entries.show', $journalEntry)
            ->with('success', 'Lançamento criado com sucesso.');
    }

    public function reclassify(
        Request $request,
        JournalEntry $journalEntry,
        ReclassifyDraftEntry $reclassifyDraftEntry
    ): RedirectResponse {
        $wallet = $this->resolveActiveWallet($request);
        $this->ensureEntryBelongsToWallet($wallet, $journalEntry);
        $this->ensureDraft($journalEntry);

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


    private function ensureDraft(JournalEntry $journalEntry): void
    {
        if ($journalEntry->status === 'posted') {
            abort(403, 'Lançamentos postados não podem ser alterados.');
        }
    }
}