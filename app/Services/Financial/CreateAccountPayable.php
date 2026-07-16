<?php

namespace App\Services\Financial;

use App\DTOs\Financial\AccountPayableDTO;
use App\Models\AccountPayable;
use App\Models\ChartOfAccount;
use App\Models\Supplier;
use App\Models\Wallet;
use App\Services\Accounting\CreateJournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateAccountPayable
{
    public function __construct(private readonly CreateJournalEntry $createJournalEntry) {}

    public function execute(Wallet $wallet, AccountPayableDTO $dto): AccountPayable
    {
        $supplier = $dto->supplierId ? Supplier::query()->validForPayables($wallet->id)->find($dto->supplierId) : null;
        if ($dto->supplierId && ! $supplier) {
            throw ValidationException::withMessages(['supplier_id' => 'Fornecedor ativo inválido.']);
        }
        $expenseId = $supplier?->default_expense_account_id ?? $dto->expenseAccountId;
        $payableId = $supplier?->payable_account_id ?? $dto->payableAccountId;
        $expenseAccount = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', 'despesa')
            ->where('allows_posting', true)
            ->whereDoesntHave('children')
            ->find($expenseId);

        if (! $expenseAccount) {
            throw ValidationException::withMessages([
                'expense_account_id' => 'Conta de despesa inválida para contas a pagar.',
            ]);
        }

        $payableAccount = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)->where('type', 'passivo')
            ->where('financial_group', 'accounts_payable')->where('allows_posting', true)
            ->whereDoesntHave('children')
            ->when($payableId, fn ($query) => $query->whereKey($payableId))
            ->orderBy('code')->first();
        if (! $payableAccount) {
            throw ValidationException::withMessages(['payable_account_id' => 'Conta de controle do fornecedor inválida.']);
        }

        return DB::transaction(function () use ($wallet, $dto, $expenseAccount, $payableAccount, $supplier) {
            $title = AccountPayable::query()->create([
                'wallet_id' => $wallet->id, 'payable_account_id' => $payableAccount->id,
                'supplier_id' => $supplier?->id,
                'expense_account_id' => $expenseAccount->id, 'payee_name' => $supplier?->name ?? $dto->payeeName,
                'description' => $dto->description, 'due_date' => $dto->dueDate,
                'amount_cents' => $dto->amountCents, 'status' => 'pending', 'notes' => $dto->notes,
            ]);
            $provision = $this->createJournalEntry->execute([
                'wallet_id' => $wallet->id, 'entry_date' => $dto->dueDate,
                'description' => 'Provisão: '.$dto->description,
                'lines' => [
                    ['chart_of_account_id' => $expenseAccount->id, 'type' => 'debit', 'amount_cents' => $dto->amountCents],
                    ['chart_of_account_id' => $payableAccount->id, 'type' => 'credit', 'amount_cents' => $dto->amountCents],
                ],
            ]);
            $title->update(['provision_journal_entry_id' => $provision->id]);

            return $title->fresh(['expenseAccount', 'payableAccount', 'provisionJournalEntry.lines.chartOfAccount']);
        });
    }
}
