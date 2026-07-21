<?php

namespace App\Services\Financial;

use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class OfxOperationTypePolicy
{
    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    public const TRANSFER = 'transfer';

    public const PAYMENT = 'payment';

    public const INCOME = 'income';

    public const INVESTMENT = 'investment';

    public const EXPENSE = 'expense';

    public const FEE = 'fee';

    public const OTHER = 'other';

    /**
     * @return list<array{code: string, label: string, classification_enabled: bool}>
     */
    public function metadata(): array
    {
        return [
            [
                'code' => self::TRANSFER,
                'label' => 'Transferência',
                'classification_enabled' => true,
            ],
            [
                'code' => self::PAYMENT,
                'label' => 'Pagamento',
                'classification_enabled' => false,
            ],
            [
                'code' => self::INCOME,
                'label' => 'Receita',
                'classification_enabled' => true,
            ],
            [
                'code' => self::INVESTMENT,
                'label' => 'Investimento',
                'classification_enabled' => true,
            ],
            [
                'code' => self::EXPENSE,
                'label' => 'Despesa',
                'classification_enabled' => true,
            ],
            [
                'code' => self::FEE,
                'label' => 'Tarifa',
                'classification_enabled' => true,
            ],
            [
                'code' => self::OTHER,
                'label' => 'Outro',
                'classification_enabled' => true,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_column($this->metadata(), 'code');
    }

    /**
     * @return list<string>
     */
    public function allowedOperationTypesForDirection(string $direction): array
    {
        $this->assertValidDirection($direction);

        return array_values(array_filter(
            $this->codes(),
            fn (string $operationType) => match ($direction) {
                self::DIRECTION_IN => ! in_array($operationType, [
                    self::PAYMENT,
                    self::EXPENSE,
                    self::FEE,
                ], true),
                self::DIRECTION_OUT => $operationType !== self::INCOME,
            },
        ));
    }

    public function isOperationTypeAllowedForDirection(
        string $operationType,
        string $direction,
    ): bool {
        $this->assertValidOperationType($operationType);
        $this->assertValidDirection($direction);

        return in_array(
            $operationType,
            $this->allowedOperationTypesForDirection($direction),
            true,
        );
    }

    public function validateOperationTypeForDirection(
        string $operationType,
        string $direction,
    ): void {
        if ($this->isOperationTypeAllowedForDirection($operationType, $direction)) {
            return;
        }

        throw new InvalidArgumentException(match ($direction) {
            self::DIRECTION_IN => 'Este tipo de operação não é permitido para uma entrada bancária.',
            self::DIRECTION_OUT => 'Este tipo de operação não é permitido para uma saída bancária.',
        });
    }

    public function supportsClassification(string $operationType): bool
    {
        $this->assertValidOperationType($operationType);

        return $operationType !== self::PAYMENT;
    }

    /**
     * @return Builder<ChartOfAccount>
     */
    public function eligibleAccountsQuery(
        Wallet $wallet,
        BankAccount $bankAccount,
        string $operationType,
    ): Builder {
        $this->assertBankAccountBelongsToWallet($wallet, $bankAccount);
        $this->assertValidOperationType($operationType);

        $query = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('allows_posting', true)
            ->whereDoesntHave('children')
            ->when(
                $wallet->suspense_account_id,
                fn (Builder $query) => $query->whereKeyNot($wallet->suspense_account_id),
            )
            ->whereKeyNot($bankAccount->chart_of_account_id);

        return match ($operationType) {
            self::TRANSFER => $query->whereExists(function ($bankQuery) use ($wallet) {
                $bankQuery->selectRaw('1')->from('bank_accounts')
                    ->whereColumn('bank_accounts.chart_of_account_id', 'chart_of_accounts.id')
                    ->where('bank_accounts.wallet_id', $wallet->id)->where('bank_accounts.is_active', true);
            }),
            self::EXPENSE, self::FEE => $query->where('type', 'despesa'),
            self::INCOME => $query->where('type', 'receita'),
            self::INVESTMENT => $query->where('type', 'ativo')->where('financial_group', 'investments'),
            self::OTHER => $query,
            self::PAYMENT => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * @return Collection<int, ChartOfAccount>
     */
    public function eligibleAccounts(
        Wallet $wallet,
        BankAccount $bankAccount,
        string $operationType,
    ): Collection {
        return $this->eligibleAccountsQuery($wallet, $bankAccount, $operationType)
            ->orderBy('code')
            ->get()
            ->filter(fn (ChartOfAccount $account) => $this->isAccountAllowed($wallet, $bankAccount, $operationType, $account));
    }

    /**
     * @return list<string>
     */
    public function allowedOperationTypesForAccount(
        Wallet $wallet,
        BankAccount $bankAccount,
        ChartOfAccount $account,
    ): array {
        if (! $this->passesBaseAccountRules($wallet, $bankAccount, $account)) {
            return [];
        }

        return array_values(array_filter(
            $this->codes(),
            fn (string $operationType) => $this->supportsClassification($operationType)
                && $this->passesOperationTypeRules($account, $operationType)
                && ($operationType !== self::TRANSFER || BankAccount::query()
                    ->where('wallet_id', $wallet->id)->where('is_active', true)
                    ->where('chart_of_account_id', $account->id)->exists()),
        ));
    }

    public function isAccountAllowed(
        Wallet $wallet,
        BankAccount $bankAccount,
        string $operationType,
        ChartOfAccount $account,
    ): bool {
        $this->assertBankAccountBelongsToWallet($wallet, $bankAccount);
        $this->assertValidOperationType($operationType);

        return $this->supportsClassification($operationType)
            && $this->passesBaseAccountRules($wallet, $bankAccount, $account)
            && $this->passesOperationTypeRules($account, $operationType)
            && ($operationType !== self::TRANSFER || BankAccount::query()
                ->where('wallet_id', $wallet->id)->where('is_active', true)
                ->where('chart_of_account_id', $account->id)->exists());
    }

    public function validateAccount(
        Wallet $wallet,
        BankAccount $bankAccount,
        string $operationType,
        ChartOfAccount $account,
    ): void {
        if (! $this->supportsClassification($operationType)) {
            throw new InvalidArgumentException(
                'Este tipo de operação ainda não permite classificação contábil neste fluxo.',
            );
        }

        if (! $this->isAccountAllowed($wallet, $bankAccount, $operationType, $account)) {
            throw new InvalidArgumentException(
                'A conta selecionada não é uma classificação válida para este tipo de operação.',
            );
        }
    }

    public function suggestOperationType(string $rawOfxType, string $direction): string
    {
        $rawOfxType = strtoupper(trim($rawOfxType));

        return match ($rawOfxType) {
            'XFER' => self::TRANSFER,
            'FEE', 'SRVCHG' => self::FEE,
            'PAYMENT', 'CHECK', 'DIRECTDEBIT', 'REPEATPMT' => self::PAYMENT,
            'CREDIT', 'DEP', 'DIRECTDEP', 'DIV', 'INT' => self::INCOME,
            'DEBIT', 'POS', 'ATM', 'CASH' => self::EXPENSE,
            default => $direction === 'in' ? self::INCOME : self::OTHER,
        };
    }

    private function passesBaseAccountRules(
        Wallet $wallet,
        BankAccount $bankAccount,
        ChartOfAccount $account,
    ): bool {
        return (int) $account->wallet_id === (int) $wallet->id
            && (int) $account->id !== (int) $wallet->suspense_account_id
            && (int) $account->id !== (int) $bankAccount->chart_of_account_id
            && $account->isPostingAllowed()
            && ! $account->isSynthetic()
            && ! $account->children()->exists();
    }

    private function passesOperationTypeRules(ChartOfAccount $account, string $operationType): bool
    {
        return match ($operationType) {
            self::TRANSFER => $account->financial_group === 'available',
            self::EXPENSE, self::FEE => $account->type === 'despesa',
            self::INCOME => $account->type === 'receita',
            self::INVESTMENT => $account->type === 'ativo'
                && $account->financial_group === 'investments'
                && $this->isDescendantOfInvestments($account),
            self::OTHER => true,
            self::PAYMENT => false,
        };
    }

    private function isDescendantOfInvestments(ChartOfAccount $account): bool
    {
        $ancestor = $account->parent;
        while ($ancestor) {
            if ($ancestor->code === '1.3'
                && $ancestor->type === 'ativo'
                && $ancestor->financial_group === 'investments') {
                return true;
            }
            $ancestor = $ancestor->parent;
        }

        return false;
    }

    private function assertBankAccountBelongsToWallet(Wallet $wallet, BankAccount $bankAccount): void
    {
        if ((int) $bankAccount->wallet_id !== (int) $wallet->id) {
            throw new InvalidArgumentException('A conta bancária deve pertencer à wallet ativa.');
        }
    }

    private function assertValidOperationType(string $operationType): void
    {
        if (! in_array($operationType, $this->codes(), true)) {
            throw new InvalidArgumentException('Tipo de operação OFX inválido.');
        }
    }

    private function assertValidDirection(string $direction): void
    {
        if (! in_array($direction, [self::DIRECTION_IN, self::DIRECTION_OUT], true)) {
            throw new InvalidArgumentException('Direção do movimento bancário inválida.');
        }
    }
}
