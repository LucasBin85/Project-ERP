<?php

namespace App\Services\Financial;

use App\Models\BankAccount;
use App\Models\BankStatementClassificationRule;
use App\Models\JournalLine;
use App\Models\Wallet;
use Illuminate\Support\Str;

class SuggestBankStatementClassification
{
    public function __construct(private readonly ValidateBankStatementClassificationRule $validator) {}

    public function execute(Wallet $wallet, BankAccount $currentAccount, JournalLine $bankLine): ?array
    {
        $description = $bankLine->journalEntry?->description ?: $bankLine->memo ?: '';
        $direction = $bankLine->type === 'debit' ? 'in' : 'out';
        $matches = BankStatementClassificationRule::query()->where('wallet_id', $wallet->id)->where('active', true)
            ->with(['chartOfAccount', 'bankAccount', 'supplier', 'customer', 'investmentAccount'])
            ->orderByDesc('priority')->get()->filter(function ($rule) use ($description, $direction, $wallet, $currentAccount) {
                if (! in_array($rule->direction, ['any', $direction], true)) return false;
                if ($rule->bank_account_id && (int) $rule->bank_account_id === (int) $currentAccount->id) return false;
                $matchesText = match ($rule->match_mode) {
                    'exact' => Str::lower(trim($description)) === Str::lower(trim($rule->match_text)),
                    'starts_with' => Str::startsWith(Str::lower($description), Str::lower($rule->match_text)),
                    default => Str::contains(Str::lower($description), Str::lower($rule->match_text)),
                };
                if (! $matchesText) return false;
                try { $this->validator->validate($wallet, $rule->toArray()); } catch (\Throwable) { return false; }
                return true;
            })->values();
        if ($matches->isEmpty()) return null;
        $top = $matches->first();
        if ($matches->where('priority', $top->priority)->count() > 1) return ['status' => 'ambiguous'];
        $target = $top->investmentAccount ?? $top->bankAccount ?? $top->chartOfAccount ?? $top->supplier ?? $top->customer;
        return ['status' => 'suggested', 'rule_id' => $top->id, 'rule_name' => $top->name, 'operation_type' => $top->operation_type,
            'chart_of_account_id' => $top->investment_account_id ?: ($top->bankAccount?->chart_of_account_id ?: $top->chart_of_account_id),
            'target_label' => $target?->name, 'can_apply' => ! in_array($top->operation_type, [OfxOperationTypePolicy::PAYMENT], true) && ! $top->customer_id];
    }
}
