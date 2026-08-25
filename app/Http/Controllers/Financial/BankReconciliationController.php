<?php

namespace App\Http\Controllers\Financial;

use App\DTOs\Financial\BankReconciliationDraftDTO;
use App\DTOs\Financial\BankReconciliationDTO;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Services\Financial\BankReconciliationPreviewService;
use App\Services\Financial\BuildOfxReconciliationStatementItems;
use App\Services\Financial\CreateBankReconciliation;
use App\Services\Financial\DiscardBankReconciliationDraft;
use App\Services\Financial\UpdateBankReconciliationDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BankReconciliationController extends Controller
{
    use ResolvesActiveWallet;

    public function create(
        Request $request,
        BankReconciliationPreviewService $previewService,
        BuildOfxReconciliationStatementItems $statementItemsBuilder,
    ): Response {
        $wallet = $this->resolveActiveWallet($request);
        $query = validator($request->query(), [
            'bank_account_id' => ['nullable', 'integer'],
            'period_start' => ['nullable', 'date_format:Y-m-d'],
            'period_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:period_start'],
        ])->validate();

        $bankAccount = null;

        if (isset($query['bank_account_id'])) {
            $bankAccount = BankAccount::query()
                ->where('wallet_id', $wallet->id)
                ->where('is_active', true)
                ->findOrFail($query['bank_account_id']);
        }

        $periodStart = $query['period_start'] ?? null;
        $periodEnd = $query['period_end'] ?? null;
        $statementItems = [];

        if ($bankAccount && $periodStart && $periodEnd) {
            $preview = $previewService->build($wallet, $bankAccount, $periodStart, $periodEnd);
            $statementItems = $statementItemsBuilder->build(
                wallet: $wallet,
                bankAccount: $bankAccount,
                periodStart: $periodStart,
                periodEnd: $periodEnd,
                availableLineIds: collect($preview['lines'])->pluck('id')->all(),
            );
        }

        return Inertia::render('Financial/BankReconciliations/Create', [
            'wallet' => ['id' => $wallet->id, 'name' => $wallet->name],
            'bankAccounts' => BankAccount::query()
                ->where('wallet_id', $wallet->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'bank_name', 'agency', 'account_number']),
            'initial' => [
                'bank_account_id' => $bankAccount?->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'statement_balance_cents' => null,
                'statement_items' => $statementItems,
            ],
        ]);
    }

    public function preview(Request $request, BankReconciliationPreviewService $service): JsonResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        $data = $this->validated($request, true);
        $bankAccount = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('is_active', true)
            ->findOrFail($data['bank_account_id']);
        $dto = BankReconciliationDTO::fromArray($data);
        $ignoredReconciliationId = null;

        if (isset($data['bank_reconciliation_id'])) {
            $ignoredReconciliationId = BankReconciliation::query()
                ->where('wallet_id', $wallet->id)
                ->where('status', 'draft')
                ->where('bank_account_id', $bankAccount->id)
                ->whereDate('period_start', $dto->periodStart)
                ->whereDate('period_end', $dto->periodEnd)
                ->findOrFail($data['bank_reconciliation_id'])
                ->id;
        }

        return response()->json($service->buildForStatement(
            wallet: $wallet,
            bankAccount: $bankAccount,
            periodStart: $dto->periodStart,
            periodEnd: $dto->periodEnd,
            statementBalanceCents: $dto->statementBalanceCents,
            statementItems: $dto->statementItems,
            ignoredReconciliationId: $ignoredReconciliationId,
        ));
    }

    public function edit(Request $request, BankReconciliation $bankReconciliation, BankReconciliationPreviewService $previewService): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        abort_unless($bankReconciliation->wallet_id === $wallet->id && $bankReconciliation->status === 'draft', 404);

        $bankReconciliation->load([
            'bankAccount',
            'statementItems.bankStatementImportTransaction.import',
            'statementItems.journalLine.journalEntry',
        ]);
        $lines = $previewService->build(
            $wallet,
            $bankReconciliation->bankAccount,
            $bankReconciliation->period_start->toDateString(),
            $bankReconciliation->period_end->toDateString(),
        )['lines'];

        return Inertia::render('Financial/BankReconciliations/Edit', [
            'wallet' => ['id' => $wallet->id, 'name' => $wallet->name],
            'reconciliation' => $bankReconciliation,
            'availableLines' => $lines,
        ]);
    }

    public function update(
        Request $request,
        BankReconciliation $bankReconciliation,
        UpdateBankReconciliationDraft $service,
    ): RedirectResponse {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless($bankReconciliation->wallet_id === $wallet->id, 404);

        $reconciliation = $service->execute($wallet, $bankReconciliation, BankReconciliationDraftDTO::fromArray($this->validatedDraft($request)));

        return redirect()->route('bank-reconciliations.show', $reconciliation)->with('success', 'Conciliação bancária revisada com sucesso.');
    }

    public function destroy(
        Request $request,
        BankReconciliation $bankReconciliation,
        DiscardBankReconciliationDraft $service,
    ): RedirectResponse {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless($bankReconciliation->wallet_id === $wallet->id, 404);

        $service->execute($wallet, $bankReconciliation);

        return redirect()->route('bank-reconciliations.index')->with('success', 'Rascunho de conciliação descartado com sucesso.');
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

    private function validated(Request $request, bool $allowEditContext = false): array
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
            'bank_reconciliation_id' => $allowEditContext ? ['nullable', 'integer'] : ['prohibited'],
        ]);
    }

    private function validatedDraft(Request $request): array
    {
        return $request->validate([
            'bank_account_id' => ['prohibited'],
            'period_start' => ['prohibited'],
            'period_end' => ['prohibited'],
            'statement_balance_cents' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'statement_items' => ['present', 'array', 'max:500'],
            'statement_items.*.bank_statement_import_transaction_id' => ['nullable', 'integer'],
            'statement_items.*.transaction_date' => ['required', 'date_format:Y-m-d'],
            'statement_items.*.description' => ['required', 'string', 'max:255'],
            'statement_items.*.amount_cents' => ['required', 'integer'],
            'statement_items.*.journal_line_id' => ['nullable', 'integer'],
        ]);
    }
}
