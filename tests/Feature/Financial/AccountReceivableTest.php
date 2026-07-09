<?php

use App\DTOs\Financial\AccountReceivableDTO;
use App\DTOs\Financial\ReceiveAccountReceivableDTO;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\CreateAccountReceivable;
use App\Services\Financial\ReceiveAccountReceivable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

it('creates a pending account receivable without journal entry', function () {
    $user = User::factory()->create();

    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    $revenueAccount = AccountingTestHelper::account($wallet, '4.1', 'Receita de Serviços', 'receita', 'credit');

    $accountReceivable = app(CreateAccountReceivable::class)->execute(
        $wallet,
        new AccountReceivableDTO(
            revenueAccountId: $revenueAccount->id,
            customerName: 'Cliente ABC',
            description: 'Serviços julho',
            dueDate: '2026-07-20',
            amountCents: 150000,
        ),
    );

    expect($accountReceivable->status)->toBe('pending')
        ->and($accountReceivable->amount_cents)->toBe(150000)
        ->and($accountReceivable->receipt_journal_entry_id)->toBeNull();

    expect(JournalEntry::query()->count())->toBe(0);
});

it('receives an account receivable and creates a posted journal entry', function () {
    $user = User::factory()->create();

    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    $revenueAccount = AccountingTestHelper::account($wallet, '4.1', 'Receita de Serviços', 'receita', 'credit');

    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.001',
        name: 'Banco Principal',
    );

    $accountReceivable = app(CreateAccountReceivable::class)->execute(
        $wallet,
        new AccountReceivableDTO(
            revenueAccountId: $revenueAccount->id,
            customerName: 'Cliente ABC',
            description: 'Serviços julho',
            dueDate: '2026-07-20',
            amountCents: 150000,
        ),
    );

    $receivedAccountReceivable = app(ReceiveAccountReceivable::class)->execute(
        $wallet,
        $accountReceivable,
        new ReceiveAccountReceivableDTO(
            bankAccountId: $bankAccount->id,
            receivedAt: '2026-07-12',
        ),
    );

    expect($receivedAccountReceivable->status)->toBe('received')
        ->and($receivedAccountReceivable->received_at->toDateString())->toBe('2026-07-12')
        ->and($receivedAccountReceivable->bank_account_id)->toBe($bankAccount->id)
        ->and($receivedAccountReceivable->receipt_journal_entry_id)->not->toBeNull()
        ->and($receivedAccountReceivable->receiptJournalEntry->status)->toBe('posted')
        ->and($receivedAccountReceivable->receiptJournalEntry->is_balanced)->toBeTrue();

    expect(JournalEntry::query()->count())->toBe(1)
        ->and(JournalLine::query()->count())->toBe(2);

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $receivedAccountReceivable->receipt_journal_entry_id,
        'chart_of_account_id' => $bankAccount->chart_of_account_id,
        'type' => 'debit',
        'amount_cents' => 150000,
    ]);

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $receivedAccountReceivable->receipt_journal_entry_id,
        'chart_of_account_id' => $revenueAccount->id,
        'type' => 'credit',
        'amount_cents' => 150000,
    ]);
});
