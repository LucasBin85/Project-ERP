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

        $selectedBankAccountId = $request->query('bank_account_id');

        if ($selectedBankAccountId) {
            BankAccount::query()
                ->where('wallet_id', $wallet->id)
                ->where('is_active', true)
                ->findOrFail($selectedBankAccountId);
        }

        $imports = BankStatementImport::query()
            ->where('wallet_id', $wallet->id)
            ->where('source', 'ofx')
            ->when($selectedBankAccountId, fn ($query) => $query->where('bank_account_id', $selectedBankAccountId))
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
            'selectedBankAccountId' => $selectedBankAccountId ? (int) $selectedBankAccountId : null,
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
            ->route('bank-accounts.statement', $bankAccount)
            ->with('success', sprintf(
                'OFX importado: %d transações processadas e %d duplicadas ignoradas.',
                $import->imported_transactions,
                $import->skipped_duplicates,
            ));
    }
}
