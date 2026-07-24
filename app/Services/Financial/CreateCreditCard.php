<?php

namespace App\Services\Financial;

use App\DTOs\Financial\CreditCardDTO;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\CreditCard;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCreditCard
{
    public function execute(Wallet $wallet, CreditCardDTO $dto): CreditCard
    {
        return DB::transaction(function () use ($wallet, $dto) {
            $parentCard = null;
            $issuerBank = null;

            if ($dto->cardType !== 'main') {
                $parentCard = CreditCard::query()
                    ->where('wallet_id', $wallet->id)
                    ->where('card_type', 'main')
                    ->with(['liabilityAccount', 'issuerBank'])
                    ->find($dto->parentCardId);

                if (! $parentCard) {
                    throw ValidationException::withMessages([
                        'parent_card_id' => 'Cartões adicionais ou virtuais precisam estar vinculados a um cartão principal.',
                    ]);
                }
            }

            if ($dto->cardType === 'main') {
                $issuerBankId = $dto->bankId;
                if (! $issuerBankId && $dto->bankAccountId) {
                    $issuerBankId = BankAccount::query()
                        ->where('wallet_id', $wallet->id)
                        ->where('is_active', true)
                        ->whereKey($dto->bankAccountId)
                        ->value('bank_id');
                }
                if (! $issuerBankId && $dto->issuerName !== '') {
                    $issuerBankId = Bank::query()
                        ->where('active', true)
                        ->where(fn ($query) => $query->where('short_name', $dto->issuerName)->orWhere('name', $dto->issuerName))
                        ->value('id');
                }
                $issuerBank = Bank::query()->where('active', true)->find($issuerBankId);
                if (! $issuerBank) {
                    throw ValidationException::withMessages([
                        'bank_id' => 'Selecione uma instituição emissora válida.',
                    ]);
                }
            }

            $liabilityAccount = $parentCard?->liabilityAccount ?? $this->createLiabilityAccount($wallet, $dto->name);

            return CreditCard::query()->create([
                'wallet_id' => $wallet->id,
                'issuer_bank_id' => $parentCard?->issuer_bank_id ?? $issuerBank?->id,
                'liability_account_id' => $liabilityAccount->id,
                'bank_account_id' => null,
                'parent_card_id' => $parentCard?->id,
                'name' => $dto->name,
                'issuer_name' => $parentCard?->issuer_name ?? $issuerBank?->short_name,
                'network' => $parentCard?->network ?? $dto->network,
                'card_type' => $dto->cardType,
                'holder_name' => $dto->holderName,
                'last_four' => $dto->lastFour,
                'closing_day' => $parentCard?->closing_day ?? $dto->closingDay,
                'due_day' => $parentCard?->due_day ?? $dto->dueDay,
                'best_purchase_day' => $parentCard?->best_purchase_day ?? $dto->bestPurchaseDay,
                'credit_limit_cents' => $parentCard?->credit_limit_cents ?? $dto->creditLimitCents,
                'is_active' => true,
                'notes' => $dto->notes,
            ])->fresh(['liabilityAccount', 'issuerBank', 'parentCard']);
        });
    }

    private function createLiabilityAccount(Wallet $wallet, string $cardName): ChartOfAccount
    {
        $parent = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('code', '2.2')
            ->where('type', 'passivo')
            ->firstOrFail();

        return ChartOfAccount::query()->create([
            'wallet_id' => $wallet->id,
            'parent_id' => $parent->id,
            'code' => $this->nextChildCode($wallet, $parent),
            'name' => $cardName,
            'type' => 'passivo',
            'normal_balance' => 'credit',
            'allows_posting' => true,
            'is_system' => false,
            'financial_group' => 'accounts_payable',
        ]);
    }

    private function nextChildCode(Wallet $wallet, ChartOfAccount $parent): string
    {
        $lastCode = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('parent_id', $parent->id)
            ->where('code', 'like', $parent->code.'.%')
            ->orderByRaw('LENGTH(code) DESC')
            ->orderByDesc('code')
            ->value('code');

        if (! $lastCode) {
            return $parent->code.'.001';
        }

        $lastSegment = (int) str($lastCode)->afterLast('.')->toString();

        return $parent->code.'.'.str_pad((string) ($lastSegment + 1), 3, '0', STR_PAD_LEFT);
    }
}
