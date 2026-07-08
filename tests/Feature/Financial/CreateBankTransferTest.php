<?php

use App\DTOs\Financial\BankTransferDTO;
use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\CreateBankTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function createTransferAccount(Wallet $wallet, string $code, string $name): ChartOfAccount
{
    return ChartOfAccount::query()->create([
        'wallet_id' => $wallet->id,
        'code' => $code,
        'name' => $name,
        'type' => 'ativo',
        'normal_balance' => 'debit',
        'allows_posting' => true,
    ]);
}

function createTransferBankAccount(Wallet $wallet, ChartOfAccount $chartOfAccount, string $name): BankAccount
{
    return BankAccount::query()->create([
        'wallet_id' => $wallet->id,
        'chart_of_account_id' => $chartOfAccount->id,
        'name' => $name,
        'bank_name' => $name,
        'account_type' => 'checking',
        'opening_balance_cents' => 0,
        'is_active' => true,
    ]);
}

it('creates a posted journal entry when creating a bank transfer', function () {
    $user = User::factory()->create();

    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    $fromChartAccount = createTransferAccount($wallet, '1.1.2.001', 'Banco Origem');
    $toChartAccount = createTransferAccount($wallet, '1.1.2.002', 'Banco Destino');

    $fromBankAccount = createTransferBankAccount($wallet, $fromChartAccount, 'Banco Origem');
    $toBankAccount = createTransferBankAccount($wallet, $toChartAccount, 'Banco Destino');

    $transfer = app(CreateBankTransfer::class)->execute(
        $wallet,
        new BankTransferDTO(
            fromBankAccountId: $fromBankAccount->id,
            toBankAccountId: $toBankAccount->id,
            amountCents: 250000,
            transferDate: '2026-07-07',
            description: 'Transferência para investimento',
        ),
    );

    expect($transfer->amount_cents)->toBe(250000)
        ->and($transfer->status)->toBe('posted')
        ->and($transfer->journalEntry->status)->toBe('posted')
        ->and($transfer->journalEntry->is_balanced)->toBeTrue();

    expect(JournalEntry::query()->count())->toBe(1)
        ->and(JournalLine::query()->count())->toBe(2);

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $transfer->journal_entry_id,
        'chart_of_account_id' => $toChartAccount->id,
        'type' => 'debit',
        'amount_cents' => 250000,
    ]);

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $transfer->journal_entry_id,
        'chart_of_account_id' => $fromChartAccount->id,
        'type' => 'credit',
        'amount_cents' => 250000,
    ]);
});

it('does not allow a transfer to the same bank account', function () {
    $user = User::factory()->create();

    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    $chartAccount = createTransferAccount($wallet, '1.1.2.001', 'Banco Origem');
    $bankAccount = createTransferBankAccount($wallet, $chartAccount, 'Banco Origem');

    app(CreateBankTransfer::class)->execute(
        $wallet,
        new BankTransferDTO(
            fromBankAccountId: $bankAccount->id,
            toBankAccountId: $bankAccount->id,
            amountCents: 10000,
            transferDate: '2026-07-07',
            description: 'Transferência inválida',
        ),
    );
})->throws(ValidationException::class);
