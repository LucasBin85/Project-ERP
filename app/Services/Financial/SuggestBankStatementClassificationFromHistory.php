<?php

namespace App\Services\Financial;

use App\Models\BankAccount;
use App\Models\BankStatementImportTransaction;
use App\Models\ChartOfAccount;
use App\Models\JournalLine;
use App\Models\Wallet;

class SuggestBankStatementClassificationFromHistory
{
    public function __construct(
        private readonly NormalizeBankStatementDescription $normalize,
        private readonly OfxOperationTypePolicy $policy,
    ) {}

    public function execute(Wallet $wallet, BankAccount $currentAccount, JournalLine $bankLine): ?array
    {
        $description = $this->normalize->execute($bankLine->journalEntry?->description ?: $bankLine->memo);
        if (mb_strlen($description) < 4) return null;
        $direction = $bankLine->type === 'debit' ? 'in' : 'out';

        $history = BankStatementImportTransaction::query()
            ->where('wallet_id', $wallet->id)->where('direction', $direction)->where('status', 'imported')
            ->whereNotNull('operation_type')->where('journal_entry_id', '!=', $bankLine->journal_entry_id)
            ->with(['journalEntry.settledAccountPayable.supplier', 'journalEntry.settledAccountReceivable.customer'])
            ->latest('posted_at')->limit(500)->get()
            ->filter(fn ($audit) => $this->normalize->execute($audit->description ?: $audit->journalEntry?->description) === $description)
            ->map(fn ($audit) => $this->candidate($wallet, $currentAccount, $audit))
            ->filter()->values();

        if ($history->isEmpty()) return null;
        $groups = $history->groupBy('key');
        if ($groups->count() > 1) {
            return ['status' => 'ambiguous', 'source' => 'history', 'confidence' => 'low', 'can_apply' => false];
        }

        $candidate = $history->first();
        $count = $history->count();
        return [...$candidate, 'status' => 'suggested', 'source' => 'history',
            'confidence' => $count >= 2 ? 'high' : 'medium', 'history_count' => $count,
            'can_apply' => $candidate['can_apply'], 'can_bulk_apply' => $count >= 2 && $candidate['can_apply'],
            'suggestion_key' => 'history:'.hash('sha256', $candidate['key'].'|'.$description)];
    }

    private function candidate(Wallet $wallet, BankAccount $currentAccount, BankStatementImportTransaction $audit): ?array
    {
        $operation = (string) $audit->operation_type;
        if (! in_array($operation, $this->policy->codes(), true) || ! $this->policy->isOperationTypeAllowedForDirection($operation, $audit->direction)) return null;
        $entry = $audit->journalEntry;
        $supplier = $entry?->settledAccountPayable?->supplier;
        $customer = $entry?->settledAccountReceivable?->customer;
        if ($supplier) return $supplier->active ? $this->result($operation, 'supplier:'.$supplier->id, $supplier->name, null, false) : null;
        if ($customer) return $customer->active ? $this->result($operation, 'customer:'.$customer->id, $customer->name, null, false) : null;

        $accountId = $audit->classification_account_id;
        if (! $accountId) return null;
        $account = ChartOfAccount::query()->where('wallet_id', $wallet->id)->find($accountId);
        if (! $account || ! $this->policy->isAccountAllowed($wallet, $currentAccount, $operation, $account)) return null;
        $label = $account->name;
        if ($operation === OfxOperationTypePolicy::TRANSFER) {
            $counterpart = BankAccount::query()->where('wallet_id', $wallet->id)->where('is_active', true)->where('chart_of_account_id', $account->id)->first();
            if (! $counterpart || (int) $counterpart->id === (int) $currentAccount->id) return null;
            $label = $counterpart->name;
        }
        return $this->result($operation, 'account:'.$account->id, $label, $account->id, $operation !== OfxOperationTypePolicy::PAYMENT);
    }

    private function result(string $operation, string $targetKey, string $label, ?int $accountId, bool $canApply): array
    {
        return ['key' => $operation.'|'.$targetKey, 'operation_type' => $operation, 'chart_of_account_id' => $accountId,
            'target_label' => $label, 'can_apply' => $canApply, 'rule_id' => null, 'rule_name' => null];
    }
}
