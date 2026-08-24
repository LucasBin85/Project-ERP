<?php

namespace App\Http\Controllers\Financial;

use App\DTOs\Financial\BankReconciliationDTO;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Services\Financial\BankReconciliationPreviewService;
use App\Services\Financial\CreateBankReconciliation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BankReconciliationController extends Controller
{
    use ResolvesActiveWallet;

    public function preview(Request $request, BankReconciliationPreviewService $service): JsonResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        $data = $this->validated($request);
        $bankAccount = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('is_active', true)
            ->findOrFail($data['bank_account_id']);
        $dto = BankReconciliationDTO::fromArray($data);

        return response()->json($service->buildForStatement(
            wallet: $wallet,
            bankAccount: $bankAccount,
            periodStart: $dto->periodStart,
            periodEnd: $dto->periodEnd,
            statementBalanceCents: $dto->statementBalanceCents,
            statementItems: $dto->statementItems,
        ));
    }

    public function store(Request $request, CreateBankReconciliation $service): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        $data = $this->validated($request);
        $bankAccountExists = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('is_active', true)
            ->whereKey($data['bank_account_id'])
            ->exists();

        abort_unless($bankAccountExists, 404);

        $reconciliation = $service->execute($wallet, BankReconciliationDTO::fromArray($data));

        return redirect()
            ->route('bank-reconciliations.show', $reconciliation)
            ->with('success', 'Conciliação bancária criada com sucesso.');
    }

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

    private function validated(Request $request): array
    {
        return $request->validate([
            'bank_account_id' => ['required', 'integer'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'statement_balance_cents' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'statement_items' => ['present', 'array', 'max:500'],
            'statement_items.*.bank_statement_import_transaction_id' => ['nullable', 'integer'],
            'statement_items.*.transaction_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start', 'before_or_equal:period_end'],
            'statement_items.*.description' => ['required', 'string', 'max:255'],
            'statement_items.*.amount_cents' => ['required', 'integer'],
            'statement_items.*.journal_line_id' => ['nullable', 'integer'],
        ]);
    }
}
