<?php

use App\DTOs\Financial\AccountPayableDTO;
use App\DTOs\Financial\PayAccountPayableDTO;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\CreateAccountPayable;
use App\Services\Financial\PayAccountPayable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

it('creates a pending account payable without journal entry', function () {
    $user = User::factory()->create();

    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    $expenseAccount = AccountingTestHelper::account($wallet, '5.1', 'Energia Elétrica', 'despesa', 'debit');

    $accountPayable = app(CreateAccountPayable::class)->execute(
        $wallet,
        new AccountPayableDTO(
            expenseAccountId: $expenseAccount->id,
            payeeName: 'CEEE',
            description: 'Conta de energia julho',
            dueDate: '2026-07-15',
            amountCents: 35000,
        ),
    );

    expect($accountPayable->status)->toBe('pending')
        ->and($accountPayable->amount_cents)->toBe(35000)
        ->and($accountPayable->payment_journal_entry_id)->toBeNull();

    expect(JournalEntry::query()->count())->toBe(0);
});

it('pays an account payable and creates a posted journal entry', function () {
    $user = User::factory()->create();

    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    $expenseAccount = AccountingTestHelper::account($wallet, '5.1', 'Energia Elétrica', 'despesa', 'debit');

    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.001',
        name: 'Banco Principal',
    );

    $accountPayable = app(CreateAccountPayable::class)->execute(
        $wallet,
        new AccountPayableDTO(
            expenseAccountId: $expenseAccount->id,
            payeeName: 'CEEE',
            description: 'Conta de energia julho',
            dueDate: '2026-07-15',
            amountCents: 35000,
        ),
    );

    $paidAccountPayable = app(PayAccountPayable::class)->execute(
        $wallet,
        $accountPayable,
        new PayAccountPayableDTO(
            bankAccountId: $bankAccount->id,
            paidAt: '2026-07-10',
        ),
    );

    expect($paidAccountPayable->status)->toBe('paid')
        ->and($paidAccountPayable->paid_at->toDateString())->toBe('2026-07-10')
        ->and($paidAccountPayable->bank_account_id)->toBe($bankAccount->id)
        ->and($paidAccountPayable->payment_journal_entry_id)->not->toBeNull()
        ->and($paidAccountPayable->paymentJournalEntry->status)->toBe('posted')
        ->and($paidAccountPayable->paymentJournalEntry->is_balanced)->toBeTrue();

    expect(JournalEntry::query()->count())->toBe(1)
        ->and(JournalLine::query()->count())->toBe(2);

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $paidAccountPayable->payment_journal_entry_id,
        'chart_of_account_id' => $expenseAccount->id,
        'type' => 'debit',
        'amount_cents' => 35000,
    ]);

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $paidAccountPayable->payment_journal_entry_id,
        'chart_of_account_id' => $bankAccount->chart_of_account_id,
        'type' => 'credit',
        'amount_cents' => 35000,
    ]);
});
