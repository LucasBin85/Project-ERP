<?php

use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\OfxOperationTypePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createOfxPolicyAccount(
    Wallet $wallet,
    string $code,
    string $name,
    string $type = 'ativo',
    bool $allowsPosting = true,
    ?string $financialGroup = null,
    ?int $parentId = null,
): ChartOfAccount {
    return ChartOfAccount::query()->create([
        'wallet_id' => $wallet->id,
        'parent_id' => $parentId,
        'code' => $code,
        'name' => $name,
        'type' => $type,
        'normal_balance' => ChartOfAccount::normalBalanceByType($type),
        'is_system' => false,
        'allows_posting' => $allowsPosting,
        'financial_group' => $financialGroup,
    ]);
}

/**
 * @return array{wallet: Wallet, bank_account: BankAccount}
 */
function createOfxPolicyScenario(): array
{
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail()->fresh();
    $bankChart = createOfxPolicyAccount(
        wallet: $wallet,
        code: '9.1.01',
        name: 'Banco principal',
        financialGroup: 'available',
    );
    $bankAccount = BankAccount::query()->create([
        'wallet_id' => $wallet->id,
        'chart_of_account_id' => $bankChart->id,
        'name' => 'Banco principal',
        'bank_name' => 'Banco principal',
        'account_type' => 'checking',
        'opening_balance_cents' => 0,
        'is_active' => true,
    ]);

    return [
        'wallet' => $wallet,
        'bank_account' => $bankAccount,
    ];
}

it('maps eligible accounts to the supported OFX operation types', function () {
    ['wallet' => $wallet, 'bank_account' => $bankAccount] = createOfxPolicyScenario();

    $expense = createOfxPolicyAccount($wallet, '9.2.01', 'Despesa', 'despesa');
    $income = createOfxPolicyAccount($wallet, '9.3.01', 'Receita', 'receita');
    $available = createOfxPolicyAccount($wallet, '9.4.01', 'Outro banco', 'ativo', true, 'available');
    $liability = createOfxPolicyAccount($wallet, '9.5.01', 'Passivo', 'passivo');

    $policy = app(OfxOperationTypePolicy::class);

    expect($policy->allowedOperationTypesForAccount($wallet, $bankAccount, $expense))
        ->toBe([
            OfxOperationTypePolicy::EXPENSE,
            OfxOperationTypePolicy::FEE,
            OfxOperationTypePolicy::OTHER,
        ])
        ->and($policy->allowedOperationTypesForAccount($wallet, $bankAccount, $income))
        ->toBe([
            OfxOperationTypePolicy::INCOME,
            OfxOperationTypePolicy::OTHER,
        ])
        ->and($policy->allowedOperationTypesForAccount($wallet, $bankAccount, $available))
        ->toBe([
            OfxOperationTypePolicy::TRANSFER,
            OfxOperationTypePolicy::OTHER,
        ])
        ->and($policy->allowedOperationTypesForAccount($wallet, $bankAccount, $liability))
        ->toBe([OfxOperationTypePolicy::OTHER]);
});

it('limits operation types according to the bank movement direction', function () {
    $policy = app(OfxOperationTypePolicy::class);

    expect($policy->allowedOperationTypesForDirection(OfxOperationTypePolicy::DIRECTION_IN))
        ->toBe([
            OfxOperationTypePolicy::TRANSFER,
            OfxOperationTypePolicy::INCOME,
            OfxOperationTypePolicy::INVESTMENT,
            OfxOperationTypePolicy::OTHER,
        ])
        ->and($policy->allowedOperationTypesForDirection(OfxOperationTypePolicy::DIRECTION_OUT))
        ->toBe([
            OfxOperationTypePolicy::TRANSFER,
            OfxOperationTypePolicy::PAYMENT,
            OfxOperationTypePolicy::INVESTMENT,
            OfxOperationTypePolicy::EXPENSE,
            OfxOperationTypePolicy::FEE,
            OfxOperationTypePolicy::OTHER,
        ])
        ->and($policy->isOperationTypeAllowedForDirection(
            OfxOperationTypePolicy::INCOME,
            OfxOperationTypePolicy::DIRECTION_IN,
        ))->toBeTrue()
        ->and($policy->isOperationTypeAllowedForDirection(
            OfxOperationTypePolicy::TRANSFER,
            OfxOperationTypePolicy::DIRECTION_IN,
        ))->toBeTrue()
        ->and($policy->isOperationTypeAllowedForDirection(
            OfxOperationTypePolicy::PAYMENT,
            OfxOperationTypePolicy::DIRECTION_IN,
        ))->toBeFalse()
        ->and($policy->isOperationTypeAllowedForDirection(
            OfxOperationTypePolicy::PAYMENT,
            OfxOperationTypePolicy::DIRECTION_OUT,
        ))->toBeTrue()
        ->and($policy->isOperationTypeAllowedForDirection(
            OfxOperationTypePolicy::EXPENSE,
            OfxOperationTypePolicy::DIRECTION_OUT,
        ))->toBeTrue()
        ->and($policy->isOperationTypeAllowedForDirection(
            OfxOperationTypePolicy::FEE,
            OfxOperationTypePolicy::DIRECTION_OUT,
        ))->toBeTrue()
        ->and($policy->isOperationTypeAllowedForDirection(
            OfxOperationTypePolicy::TRANSFER,
            OfxOperationTypePolicy::DIRECTION_OUT,
        ))->toBeTrue()
        ->and($policy->isOperationTypeAllowedForDirection(
            OfxOperationTypePolicy::INCOME,
            OfxOperationTypePolicy::DIRECTION_OUT,
        ))->toBeFalse();
});

it('rejects operation types incompatible with the bank movement direction', function () {
    $policy = app(OfxOperationTypePolicy::class);

    expect(fn () => $policy->validateOperationTypeForDirection(
        OfxOperationTypePolicy::PAYMENT,
        OfxOperationTypePolicy::DIRECTION_IN,
    ))->toThrow(InvalidArgumentException::class, 'entrada bancária')
        ->and(fn () => $policy->validateOperationTypeForDirection(
            OfxOperationTypePolicy::INCOME,
            OfxOperationTypePolicy::DIRECTION_OUT,
        ))->toThrow(InvalidArgumentException::class, 'saída bancária');
});

it('keeps payment and investment classifications reserved for future integrations', function () {
    ['wallet' => $wallet, 'bank_account' => $bankAccount] = createOfxPolicyScenario();
    createOfxPolicyAccount($wallet, '9.2.01', 'Despesa', 'despesa');
    createOfxPolicyAccount($wallet, '9.3.01', 'Receita', 'receita');

    $policy = app(OfxOperationTypePolicy::class);

    expect($policy->supportsClassification(OfxOperationTypePolicy::PAYMENT))->toBeFalse()
        ->and($policy->supportsClassification(OfxOperationTypePolicy::INVESTMENT))->toBeFalse()
        ->and($policy->eligibleAccounts($wallet, $bankAccount, OfxOperationTypePolicy::PAYMENT))->toBeEmpty()
        ->and($policy->eligibleAccounts($wallet, $bankAccount, OfxOperationTypePolicy::INVESTMENT))->toBeEmpty();
});

it('rejects accounts outside the active wallet and analytical posting rules', function () {
    ['wallet' => $wallet, 'bank_account' => $bankAccount] = createOfxPolicyScenario();
    $policy = app(OfxOperationTypePolicy::class);

    $suspense = ChartOfAccount::query()->findOrFail($wallet->suspense_account_id);
    $bankChart = $bankAccount->chartOfAccount()->firstOrFail();
    $notPosting = createOfxPolicyAccount($wallet, '9.2.01', 'Sintética', 'despesa', false);
    $parentWithChildren = createOfxPolicyAccount($wallet, '9.3.01', 'Pai lançável', 'despesa');
    createOfxPolicyAccount(
        wallet: $wallet,
        code: '9.3.01.01',
        name: 'Filha',
        type: 'despesa',
        parentId: $parentWithChildren->id,
    );

    $otherUser = User::factory()->create();
    $otherWallet = $otherUser->wallets()->firstOrFail();
    $otherWalletAccount = createOfxPolicyAccount($otherWallet, '9.9.01', 'Outra wallet', 'despesa');

    foreach ([$suspense, $bankChart, $notPosting, $parentWithChildren, $otherWalletAccount] as $account) {
        expect($policy->isAccountAllowed(
            $wallet,
            $bankAccount,
            OfxOperationTypePolicy::OTHER,
            $account,
        ))->toBeFalse();
    }
});
