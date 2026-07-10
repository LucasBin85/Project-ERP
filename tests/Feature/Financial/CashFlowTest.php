<?php

use App\DTOs\Financial\CashFlowFiltersDTO;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\BuildCashFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

function createCashFlowPostedEntry(Wallet $wallet, string $date, string $description, array $lines): JournalEntry
{
    $debit = collect($lines)->where('type', 'debit')->sum('amount_cents');
    $credit = collect($lines)->where('type', 'credit')->sum('amount_cents');

    $entry = JournalEntry::query()->create([
        'wallet_id' => $wallet->id,
        'source' => 'manual',
        'entry_date' => $date,
        'description' => $description,
        'status' => 'posted',
        'posted_at' => now(),
        'is_balanced' => $debit === $credit,
        'balance_diff_cents' => $debit - $credit,
    ]);

    foreach ($lines as $line) {
        JournalLine::query()->create([
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $line['account']->id,
            'type' => $line['type'],
            'amount_cents' => $line['amount_cents'],
            'memo' => $line['memo'] ?? null,
        ]);
    }

    return $entry;
}

it('builds cash flow with realized and projected movements', function () {
    $user = User::factory()->create();

    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.001',
        name: 'Banco Principal',
    );

    $equity = AccountingTestHelper::account($wallet, '3.1', 'Capital Social', 'patrimonio', 'credit');
    $expense = AccountingTestHelper::account($wallet, '5.1', 'Despesa Administrativa', 'despesa', 'debit');
    $revenue = AccountingTestHelper::account($wallet, '4.1', 'Receita de Serviços', 'receita', 'credit');
    $cardLiability = AccountingTestHelper::account($wallet, '2.2.001', 'Nubank Principal', 'passivo', 'credit');

    createCashFlowPostedEntry($wallet, '2026-07-01', 'Saldo inicial', [
        ['account' => $bankAccount->chartOfAccount, 'type' => 'debit', 'amount_cents' => 100000],
        ['account' => $equity, 'type' => 'credit', 'amount_cents' => 100000],
    ]);

    createCashFlowPostedEntry($wallet, '2026-07-12', 'Pagamento combustível', [
        ['account' => $expense, 'type' => 'debit', 'amount_cents' => 20000],
        ['account' => $bankAccount->chartOfAccount, 'type' => 'credit', 'amount_cents' => 20000],
    ]);

    AccountReceivable::query()->create([
        'wallet_id' => $wallet->id,
        'revenue_account_id' => $revenue->id,
        'customer_name' => 'Cliente Alpha',
        'description' => 'Projeto Alpha',
        'due_date' => '2026-07-20',
        'amount_cents' => 150000,
        'status' => 'pending',
    ]);

    AccountPayable::query()->create([
        'wallet_id' => $wallet->id,
        'expense_account_id' => $expense->id,
        'payee_name' => 'Fornecedor Beta',
        'description' => 'Aluguel',
        'due_date' => '2026-07-25',
        'amount_cents' => 50000,
        'status' => 'pending',
    ]);

    $creditCard = CreditCard::query()->create([
        'wallet_id' => $wallet->id,
        'liability_account_id' => $cardLiability->id,
        'bank_account_id' => $bankAccount->id,
        'name' => 'Nubank Principal',
        'issuer_name' => 'Nubank',
        'network' => 'mastercard',
        'card_type' => 'main',
        'closing_day' => 5,
        'due_day' => 15,
        'best_purchase_day' => 6,
        'credit_limit_cents' => 500000,
        'is_active' => true,
    ]);

    CreditCardInvoice::query()->create([
        'wallet_id' => $wallet->id,
        'credit_card_id' => $creditCard->id,
        'reference_year' => 2026,
        'reference_month' => 7,
        'starts_at' => '2026-06-06',
        'closes_at' => '2026-07-05',
        'due_at' => '2026-07-30',
        'total_cents' => 30000,
        'paid_cents' => 0,
        'balance_cents' => 30000,
        'status' => 'open',
    ]);

    $cashFlow = app(BuildCashFlow::class)->handle(
        $wallet,
        new CashFlowFiltersDTO(
            startDate: '2026-07-10',
            endDate: '2026-07-31',
        ),
    );

    expect($cashFlow['summary']['opening_balance_cents'])->toBe(100000)
        ->and($cashFlow['summary']['realized_outflows_cents'])->toBe(20000)
        ->and($cashFlow['summary']['projected_inflows_cents'])->toBe(150000)
        ->and($cashFlow['summary']['projected_outflows_cents'])->toBe(80000)
        ->and($cashFlow['summary']['realized_closing_balance_cents'])->toBe(80000)
        ->and($cashFlow['summary']['projected_closing_balance_cents'])->toBe(150000);

    expect($cashFlow['items'])->toHaveCount(4)
        ->and(collect($cashFlow['items'])->pluck('source')->all())->toBe([
            'bank_movement',
            'accounts_receivable',
            'accounts_payable',
            'credit_card_invoice',
        ]);
});
