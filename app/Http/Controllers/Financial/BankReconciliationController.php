<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Models\BankReconciliation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BankReconciliationController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        $reconciliations = BankReconciliation::query()
            ->where('wallet_id', $wallet->id)
            ->with('bankAccount:id,name,bank_name,bank_code,agency,account_number')
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Financial/BankReconciliations/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'reconciliations' => $reconciliations,
        ]);
    }

    public function show(Request $request, BankReconciliation $bankReconciliation): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        abort_unless($bankReconciliation->wallet_id === $wallet->id, 404);

        $bankReconciliation->load([
            'bankAccount',
            'statementItems.bankStatementImportTransaction.import',
            'statementItems.journalLine.journalEntry',
            'items.journalLine.journalEntry',
        ]);

        return Inertia::render('Financial/BankReconciliations/Show', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'reconciliation' => $bankReconciliation,
        ]);
    }
}
