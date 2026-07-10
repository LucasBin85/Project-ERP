<?php

namespace App\Http\Controllers\Financial;

use App\DTOs\Financial\BankReconciliationDTO;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Services\Financial\BankReconciliationPreviewService;
use App\Services\Financial\BuildOfxReconciliationStatementItems;
use App\Services\Financial\CreateBankReconciliation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

    public function create(
        Request $request,
        BankReconciliationPreviewService $previewService,
        BuildOfxReconciliationStatementItems $ofxStatementItems,
    ): Response {
        $wallet = $this->resolveActiveWallet($request);

        $bankAccounts = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'bank_name',
                'bank_code',
                'agency',
                'account_number',
                'account_type',
            ])
            ->map(fn (BankAccount $account) => [
                'id' => $account->id,
                'label' => $this->formatBankAccountLabel($account),
                'name' => $account->name,
            ])
            ->values();

        $filters = [
            'bank_account_id' => $request->query('bank_account_id') ?: null,
            'period_start' => $request->query('period_start') ?: now()->startOfMonth()->toDateString(),
            'period_end' => $request->query('period_end') ?: now()->toDateString(),
        ];

        $validated = validator($filters, [
            'bank_account_id' => ['nullable', 'integer'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ])->validate();

        $preview = [
            'opening_balance_cents' => 0,
            'book_balance_cents' => 0,
            'lines' => [],
        ];

        $ofxItems = [];

        if ($validated['bank_account_id']) {
            $bankAccount = BankAccount::query()
                ->where('wallet_id', $wallet->id)
                ->where('is_active', true)
                ->findOrFail($validated['bank_account_id']);

            $preview = $previewService->build(
                wallet: $wallet,
                bankAccount: $bankAccount,
                periodStart: $validated['period_start'],
                periodEnd: $validated['period_end'],
            );

            $ofxItems = $ofxStatementItems->build(
                wallet: $wallet,
                bankAccount: $bankAccount,
                periodStart: $validated['period_start'],
                periodEnd: $validated['period_end'],
                availableLineIds: collect($preview['lines'])->pluck('id')->all(),
            );
        }

        return Inertia::render('Financial/BankReconciliations/Create', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'bankAccounts' => $bankAccounts,
            'filters' => $validated,
            'preview' => $preview,
            'ofxStatementItems' => $ofxItems,
        ]);
    }

    public function store(Request $request, CreateBankReconciliation $service): RedirectResponse
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
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'statement_balance_cents' => ['required', 'integer'],
            'statement_items' => ['required', 'array', 'min:1'],
            'statement_items.*.bank_statement_import_transaction_id' => ['nullable', 'integer'],
            'statement_items.*.transaction_date' => ['required', 'date'],
            'statement_items.*.description' => ['required', 'string', 'max:255'],
            'statement_items.*.amount_cents' => ['required', 'integer', 'not_in:0'],
            'statement_items.*.journal_line_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $reconciliation = $service->execute($wallet, BankReconciliationDTO::fromArray($data));

        return redirect()
            ->route('bank-reconciliations.show', $reconciliation)
            ->with('success', 'Conciliação bancária registrada com sucesso.');
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
