<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankStatementImport;
use App\Services\Financial\ImportOfxBankStatement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class OfxImportController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        $bankAccounts = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'bank_name', 'bank_code', 'agency', 'account_number'])
            ->map(fn (BankAccount $account) => [
                'id' => $account->id,
                'label' => $this->formatBankAccountLabel($account),
            ])
            ->values();

        $imports = BankStatementImport::query()
            ->where('wallet_id', $wallet->id)
            ->where('source', 'ofx')
            ->with([
                'bankAccount:id,name,bank_name',
                'transactions' => fn ($query) => $query
                    ->with('journalEntry:id,entry_date,description,status')
                    ->orderBy('posted_at')
                    ->orderBy('id'),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return Inertia::render('Financial/OfxImports/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'bankAccounts' => $bankAccounts,
            'imports' => $imports,
        ]);
    }

    public function store(Request $request, ImportOfxBankStatement $service): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);

        $data = $request->validate([
            'bank_account_id' => [
                'required',
                'integer',
                Rule::exists('bank_accounts', 'id')
                    ->where('wallet_id', $wallet->id)
                    ->where('is_active', true),
            ],
            'ofx_file' => ['required', 'file', 'max:2048'],
        ]);

        $bankAccount = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('is_active', true)
            ->findOrFail($data['bank_account_id']);

        $file = $request->file('ofx_file');

        try {
            $import = $service->execute(
                wallet: $wallet,
                bankAccount: $bankAccount,
                contents: $file->get(),
                originalFilename: $file->getClientOriginalName(),
            );
        } catch (Throwable $exception) {
            return back()
                ->withErrors(['ofx_file' => $exception->getMessage()])
                ->withInput();
        }

        return redirect()
            ->route('ofx-imports.index')
            ->with('success', sprintf(
                'OFX importado: %d lançamentos criados e %d duplicados ignorados.',
                $import->imported_transactions,
                $import->skipped_duplicates,
            ));
    }

    private function formatBankAccountLabel(BankAccount $account): string
    {
        $details = collect([
            $account->bank_code,
            $account->agency,
            $account->account_number,
        ])->filter()->join(' / ');

        return $details !== ''
            ? "{$account->name} ({$details})"
            : $account->name;
    }
}
