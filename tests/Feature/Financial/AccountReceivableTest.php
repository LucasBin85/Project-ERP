<?php

use App\DTOs\Financial\AccountReceivableDTO;
use App\DTOs\Financial\ReceiveAccountReceivableDTO;
use App\Models\Customer;
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

it('stores a receivable title and redirects to the existing index route', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $revenue = $wallet->chartOfAccounts()->where('type', 'receita')->where('allows_posting', true)->firstOrFail();
    $control = $wallet->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->firstOrFail();
    $customer = Customer::create(['wallet_id' => $wallet->id, 'name' => 'Cliente', 'receivable_account_id' => $control->id, 'default_revenue_account_id' => $revenue->id, 'active' => true]);

    $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])
        ->post(route('accounts-receivable.store'), [
            'customer_id' => $customer->id, 'receivable_account_id' => 999999, 'revenue_account_id' => 999999,
            'description' => 'Título novo', 'due_date' => '2026-07-31', 'amount_cents' => 10000,
        ])->assertRedirect(route('accounts-receivable.index'))->assertSessionHas('success', 'Título a receber cadastrado com sucesso.');

    $this->assertDatabaseHas('accounts_receivable', ['wallet_id' => $wallet->id, 'customer_id' => $customer->id, 'receivable_account_id' => $control->id, 'revenue_account_id' => $revenue->id, 'status' => 'pending']);
});

it('creates a pending account receivable with a draft accrual entry', function () {
    $user = User::factory()->create();

    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    $revenueAccount = AccountingTestHelper::account($wallet, '4.9.1', 'Receita de Serviços', 'receita', 'credit');
    $receivableAccount = AccountingTestHelper::account($wallet, '1.2.1', 'Clientes Diversos', 'ativo', 'debit');
    $receivableAccount->update(['financial_group' => 'accounts_receivable']);

    $accountReceivable = app(CreateAccountReceivable::class)->execute(
        $wallet,
        new AccountReceivableDTO(
            revenueAccountId: $revenueAccount->id,
            customerName: 'Cliente ABC',
            description: 'Serviços julho',
            dueDate: '2026-07-20',
            amountCents: 150000,
            receivableAccountId: $receivableAccount->id,
        ),
    );

    expect($accountReceivable->status)->toBe('pending')
        ->and($accountReceivable->amount_cents)->toBe(150000)
        ->and($accountReceivable->provision_journal_entry_id)->not->toBeNull()
        ->and($accountReceivable->receipt_journal_entry_id)->toBeNull();

    expect(JournalEntry::query()->count())->toBe(1)
        ->and($accountReceivable->provisionJournalEntry->status)->toBe('draft');
    $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $accountReceivable->provision_journal_entry_id, 'chart_of_account_id' => $receivableAccount->id, 'type' => 'debit']);
    $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $accountReceivable->provision_journal_entry_id, 'chart_of_account_id' => $revenueAccount->id, 'type' => 'credit']);
});

it('receives an account receivable against its control account and keeps the entry in draft', function () {
    $user = User::factory()->create();

    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    $revenueAccount = AccountingTestHelper::account($wallet, '4.9.1', 'Receita de Serviços', 'receita', 'credit');
    $receivableAccount = AccountingTestHelper::account($wallet, '1.2.1', 'Clientes Diversos', 'ativo', 'debit');
    $receivableAccount->update(['financial_group' => 'accounts_receivable']);

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
            receivableAccountId: $receivableAccount->id,
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
        ->and($receivedAccountReceivable->receiptJournalEntry->status)->toBe('draft')
        ->and($receivedAccountReceivable->receiptJournalEntry->is_balanced)->toBeTrue();

    expect(JournalEntry::query()->count())->toBe(2)
        ->and(JournalLine::query()->count())->toBe(4);

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $receivedAccountReceivable->receipt_journal_entry_id,
        'chart_of_account_id' => $bankAccount->chart_of_account_id,
        'type' => 'debit',
        'amount_cents' => 150000,
    ]);

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $receivedAccountReceivable->receipt_journal_entry_id,
        'chart_of_account_id' => $receivableAccount->id,
        'type' => 'credit',
        'amount_cents' => 150000,
    ]);
});
