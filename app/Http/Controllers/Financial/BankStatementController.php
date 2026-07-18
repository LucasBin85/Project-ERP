<?php

namespace App\Http\Controllers\Financial;

use App\DTOs\Financial\BankStatementFiltersDTO;
use App\DTOs\Financial\OfxClassificationDTO;
use App\Exceptions\OfxClassificationException;
use App\Exceptions\OfxOperationTypeDirectionException;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Wallet;
use App\Models\Supplier;
use App\Models\Customer;
use App\Services\Financial\BankStatementService;
use App\Services\Financial\BulkPostOfxDraftEntries;
use App\Services\Financial\ClassifyOfxDraftEntry;
use App\Services\Financial\MergeBankTransferOfxEntries;
use App\Services\Financial\OfxOperationTypePolicy;
use App\Services\Financial\ResolveOfxDraftMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BankStatementController extends Controller
{
    use ResolvesActiveWallet;

    public function show(
        Request $request,
        BankAccount $bankAccount,
        BankStatementService $service,
        OfxOperationTypePolicy $operationTypes,
    ): Response {
        $wallet = $this->resolveActiveWallet($request);

        abort_unless($bankAccount->wallet_id === $wallet->id, 404);

        $bankAccount->load('chartOfAccount');

        $rawFilters = [
            'bank_account_id' => (string) $bankAccount->id,
            'start_date' => $request->query('start_date') ?: now()->subDays(90)->toDateString(),
            'end_date' => $request->query('end_date') ?: now()->toDateString(),
            'search' => $request->string('search')->toString(),
        ];

        $validated = validator($rawFilters, [
            'bank_account_id' => ['required', 'integer'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'search' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $filters = BankStatementFiltersDTO::fromArray($validated);
        $statement = $service->build($wallet, $filters)->toArray();

        return Inertia::render('Financial/BankStatements/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'filters' => $statement['filters'],
            'selectedBankAccount' => $statement['bank_account'],
            'transactions' => $statement['transactions'],
            'summary' => $statement['summary'],
            'classificationAccounts' => $this->classificationAccounts(
                wallet: $wallet,
                bankAccount: $bankAccount,
                operationTypes: $operationTypes,
            ),
            'operationTypes' => $operationTypes->metadata(),
            'settlementParties' => [
                'suppliers' => Supplier::query()->validForPayables($wallet->id)->orderBy('name')->get(['id', 'name']),
                'customers' => Customer::query()->validForReceivables($wallet->id)->orderBy('name')->get(['id', 'name']),
            ],
            'ofxPreview' => $request->session()->get('ofx_preview'),
            'bulkPostResult' => $request->session()->get('ofx_bulk_post_result'),
            'operational' => $this->operationalContext(
                bankAccount: $bankAccount,
                startDate: $filters->startDate,
            ),
        ]);
    }

    public function bulkPost(
        Request $request,
        BankAccount $bankAccount,
        BulkPostOfxDraftEntries $service,
    ): RedirectResponse|JsonResponse {
        $wallet = $this->resolveActiveWallet($request);

        abort_unless((int) $bankAccount->wallet_id === (int) $wallet->id, 404);

        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $result = $service->execute(
            wallet: $wallet,
            bankAccount: $bankAccount,
            startDate: $data['start_date'],
            endDate: $data['end_date'],
        );
        $payload = $result->toArray();

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        $response = back()
            ->with('ofx_bulk_post_result', $payload)
            ->with('success', $result->message());

        if ($result->errors > 0) {
            $response->withErrors(['bulk_post' => $result->message()]);
        }

        return $response;
    }

    public function classify(
        Request $request,
        BankAccount $bankAccount,
        JournalEntry $journalEntry,
        ClassifyOfxDraftEntry $service,
        OfxOperationTypePolicy $operationTypes,
    ): RedirectResponse {
        $wallet = $this->resolveActiveWallet($request);

        abort_unless((int) $bankAccount->wallet_id === (int) $wallet->id, 404);
        abort_unless((int) $journalEntry->wallet_id === (int) $wallet->id, 404);

        $data = $request->validate([
            'operation_type' => ['required', Rule::in($operationTypes->codes())],
            'chart_of_account_id' => [
                'nullable',
                'integer',
                Rule::exists('chart_of_accounts', 'id')
                    ->where('wallet_id', $wallet->id)
                    ->where('allows_posting', true),
            ],
            'should_post' => ['required', 'boolean'],
        ]);

        try {
            $service->execute(
                wallet: $wallet,
                bankAccount: $bankAccount,
                entry: $journalEntry,
                dto: OfxClassificationDTO::fromArray($data),
            );
        } catch (OfxOperationTypeDirectionException $exception) {
            return back()->withErrors([
                'operation_type' => $exception->getMessage(),
            ]);
        } catch (OfxClassificationException $exception) {
            return back()->withErrors([
                'chart_of_account_id' => $exception->getMessage(),
            ]);
        }

        return back()->with(
            'success',
            $data['should_post']
                ? 'Lançamento OFX classificado e postado com sucesso.'
                : ($data['chart_of_account_id'] ?? null
                    ? 'Lançamento OFX classificado com sucesso.'
                    : 'Tipo de operação atualizado com sucesso.'),
        );
    }

    public function resolveMatch(
        Request $request,
        BankAccount $bankAccount,
        JournalEntry $journalEntry,
        ResolveOfxDraftMatch $service,
    ): RedirectResponse {
        $wallet = $this->resolveActiveWallet($request);

        abort_unless((int) $bankAccount->wallet_id === (int) $wallet->id, 404);
        abort_unless((int) $journalEntry->wallet_id === (int) $wallet->id, 404);

        $data = $request->validate([
            'action' => ['required', Rule::in(['keep', 'link'])],
            'journal_line_id' => [
                Rule::requiredIf($request->input('action') === 'link'),
                'nullable',
                'integer',
            ],
        ]);

        try {
            $service->execute(
                wallet: $wallet,
                bankAccount: $bankAccount,
                entry: $journalEntry,
                action: $data['action'],
                candidateJournalLineId: isset($data['journal_line_id'])
                    ? (int) $data['journal_line_id']
                    : null,
            );
        } catch (OfxClassificationException $exception) {
            return back()->withErrors([
                'action' => $exception->getMessage(),
            ]);
        }

        return back()->with(
            'success',
            $data['action'] === 'link'
                ? 'OFX vinculado ao lançamento manual com sucesso.'
                : 'Lançamento OFX mantido para classificação.',
        );
    }

    public function mergeTransfer(
        Request $request,
        BankAccount $bankAccount,
        JournalEntry $journalEntry,
        MergeBankTransferOfxEntries $service,
    ): RedirectResponse {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless((int) $bankAccount->wallet_id === (int) $wallet->id, 404);
        abort_unless((int) $journalEntry->wallet_id === (int) $wallet->id, 404);
        $data = $request->validate(['audit_id' => ['required', 'integer']]);

        try {
            $service->execute($wallet, $bankAccount, $journalEntry, (int) $data['audit_id']);
        } catch (OfxClassificationException $exception) {
            return back()->withErrors(['transfer_match' => $exception->getMessage()]);
        }

        return back()->with('success', 'Transferências OFX vinculadas com sucesso.');
    }

    private function operationalContext(BankAccount $bankAccount, string $startDate): array
    {
        return [
            'has_older_transactions' => $this->hasOlderTransactions($bankAccount, $startDate),
        ];
    }

    private function hasOlderTransactions(BankAccount $bankAccount, string $startDate): bool
    {
        return JournalLine::query()
            ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
            ->whereHas('journalEntry', function ($query) use ($bankAccount, $startDate) {
                $query->where('wallet_id', $bankAccount->wallet_id)
                    ->whereDate('entry_date', '<', $startDate);
            })
            ->exists();
    }

    private function classificationAccounts(
        Wallet $wallet,
        BankAccount $bankAccount,
        OfxOperationTypePolicy $operationTypes,
    ): array {
        return ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('allows_posting', true)
            ->when(
                $wallet->suspense_account_id,
                fn ($query) => $query->where('id', '!=', $wallet->suspense_account_id),
            )
            ->where('id', '!=', $bankAccount->chart_of_account_id)
            ->whereDoesntHave('children')
            ->orderBy('code')
            ->get(['id', 'wallet_id', 'code', 'name', 'type', 'financial_group', 'allows_posting'])
            ->map(function (ChartOfAccount $account) use ($wallet, $bankAccount, $operationTypes) {
                $linkedBank = BankAccount::query()->with('bank:id,short_name')->where('wallet_id', $wallet->id)
                    ->where('chart_of_account_id', $account->id)->where('is_active', true)->first();
                return [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'financial_group' => $account->financial_group,
                'allowed_operation_types' => $operationTypes->allowedOperationTypesForAccount(
                    $wallet,
                    $bankAccount,
                    $account,
                ),
                'bank_account' => $linkedBank ? [
                    'id' => $linkedBank->id, 'name' => $linkedBank->name,
                    'bank_name' => $linkedBank->bank?->short_name ?? $linkedBank->bank_name,
                    'agency' => $linkedBank->agency, 'account_number' => $linkedBank->account_number,
                    'statement_url' => route('bank-accounts.statement', $linkedBank),
                ] : null,
                ];
            })
            ->values()
            ->all();
    }
}
