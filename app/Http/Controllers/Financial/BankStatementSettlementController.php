<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\BankAccount;
use App\Models\JournalEntry;
use App\Services\Financial\FindBankStatementPayableCandidates;
use App\Services\Financial\FindBankStatementReceivableCandidates;
use App\Services\Financial\LinkAccountPayableFromBankStatement;
use App\Services\Financial\LinkAccountReceivableFromBankStatement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BankStatementSettlementController extends Controller
{
    use ResolvesActiveWallet;

    public function receivableCandidates(Request $request, BankAccount $bankAccount, JournalEntry $journalEntry, FindBankStatementReceivableCandidates $service): JsonResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless((int) $bankAccount->wallet_id === (int) $wallet->id && (int) $journalEntry->wallet_id === (int) $wallet->id, 404);
        $candidates = $service->execute($wallet, $bankAccount, $journalEntry);

        return response()->json([
            'journal_entry_id' => $journalEntry->id,
            'statement_date' => $journalEntry->entry_date->toDateString(),
            'candidates' => $candidates->map(fn (AccountReceivable $receivable) => [
                'id' => $receivable->id,
                'customer_name' => $receivable->customer_name,
                'description' => $receivable->description,
                'due_date' => $receivable->due_date->toDateString(),
                'amount_cents' => $receivable->amount_cents,
                'proximity_days' => (int) abs($receivable->due_date->startOfDay()->diffInDays($journalEntry->entry_date->startOfDay(), false)),
                'revenue_account' => [
                    'id' => $receivable->revenueAccount->id,
                    'code' => $receivable->revenueAccount->code,
                    'name' => $receivable->revenueAccount->name,
                ],
                'show_url' => route('accounts-receivable.show', $receivable),
            ])->values(),
        ]);
    }

    public function linkReceivable(Request $request, BankAccount $bankAccount, JournalEntry $journalEntry, LinkAccountReceivableFromBankStatement $service): JsonResponse|RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless((int) $bankAccount->wallet_id === (int) $wallet->id && (int) $journalEntry->wallet_id === (int) $wallet->id, 404);
        $data = $request->validate([
            'account_receivable_id' => ['required', 'integer', Rule::exists('accounts_receivable', 'id')],
        ]);
        $receivable = $service->execute($wallet, $bankAccount, $journalEntry, AccountReceivable::query()->findOrFail($data['account_receivable_id']));
        $payload = [
            'message' => 'Conta a receber vinculada. O lançamento está pronto para a contabilidade.',
            'account_receivable' => [
                'id' => $receivable->id,
                'status' => $receivable->status,
                'received_at' => $receivable->received_at->toDateString(),
                'receipt_journal_entry_id' => $receivable->receipt_journal_entry_id,
                'show_url' => route('accounts-receivable.show', $receivable),
            ],
            'journal_entry' => [
                'id' => $receivable->receiptJournalEntry->id,
                'status' => $receivable->receiptJournalEntry->status,
                'is_balanced' => $receivable->receiptJournalEntry->is_balanced,
                'ready_for_accounting' => $receivable->receiptJournalEntry->status === 'draft' && $receivable->receiptJournalEntry->is_balanced,
            ],
        ];

        return $request->expectsJson() ? response()->json($payload) : back()->with('success', $payload['message']);
    }

    public function payableCandidates(
        Request $request,
        BankAccount $bankAccount,
        JournalEntry $journalEntry,
        FindBankStatementPayableCandidates $service,
    ): JsonResponse {
        $wallet = $this->resolveActiveWallet($request);

        abort_unless(
            (int) $bankAccount->wallet_id === (int) $wallet->id
                && (int) $journalEntry->wallet_id === (int) $wallet->id,
            404,
        );

        $candidates = $service->execute($wallet, $bankAccount, $journalEntry);

        return response()->json([
            'journal_entry_id' => $journalEntry->id,
            'statement_date' => $journalEntry->entry_date->toDateString(),
            'candidates' => $candidates->map(fn (AccountPayable $payable) => [
                'id' => $payable->id,
                'payee_name' => $payable->payee_name,
                'description' => $payable->description,
                'due_date' => $payable->due_date->toDateString(),
                'amount_cents' => $payable->amount_cents,
                'proximity_days' => (int) abs(
                    $payable->due_date->startOfDay()
                        ->diffInDays($journalEntry->entry_date->startOfDay(), false),
                ),
                'expense_account' => [
                    'id' => $payable->expenseAccount->id,
                    'code' => $payable->expenseAccount->code,
                    'name' => $payable->expenseAccount->name,
                ],
                'show_url' => route('accounts-payable.show', $payable),
            ])->values(),
        ]);
    }

    public function linkPayable(
        Request $request,
        BankAccount $bankAccount,
        JournalEntry $journalEntry,
        LinkAccountPayableFromBankStatement $service,
    ): JsonResponse|RedirectResponse {
        $wallet = $this->resolveActiveWallet($request);

        abort_unless(
            (int) $bankAccount->wallet_id === (int) $wallet->id
                && (int) $journalEntry->wallet_id === (int) $wallet->id,
            404,
        );

        $data = $request->validate([
            'account_payable_id' => [
                'required',
                'integer',
                Rule::exists('accounts_payable', 'id')->where('wallet_id', $wallet->id),
            ],
        ]);

        $accountPayable = AccountPayable::query()->findOrFail($data['account_payable_id']);
        $accountPayable = $service->execute(
            $wallet,
            $bankAccount,
            $journalEntry,
            $accountPayable,
        );

        $payload = [
            'message' => 'Conta a pagar vinculada. O lançamento está pronto para a contabilidade.',
            'account_payable' => [
                'id' => $accountPayable->id,
                'status' => $accountPayable->status,
                'paid_at' => $accountPayable->paid_at->toDateString(),
                'payment_journal_entry_id' => $accountPayable->payment_journal_entry_id,
                'show_url' => route('accounts-payable.show', $accountPayable),
            ],
            'journal_entry' => [
                'id' => $accountPayable->paymentJournalEntry->id,
                'status' => $accountPayable->paymentJournalEntry->status,
                'is_balanced' => $accountPayable->paymentJournalEntry->is_balanced,
                'ready_for_accounting' => $accountPayable->paymentJournalEntry->status === 'draft'
                    && $accountPayable->paymentJournalEntry->is_balanced,
            ],
        ];

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return back()->with('success', $payload['message']);
    }
}
