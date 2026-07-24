<?php

use App\Models\Bank;
use App\Models\BankStatementImport;
use App\Models\BankStatementImportTransaction;
use App\Models\CreditCard;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\BuildBankAccountWorkspace;
use App\Services\Financial\OfxOperationTypePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

it('builds bank accounts overview with current balances', function () {
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
    $expense = AccountingTestHelper::account($wallet, '5.91.1', 'Despesa Administrativa', 'despesa', 'debit');
    $suspense = AccountingTestHelper::account($wallet, '1.1.9', 'A classificar', 'ativo', 'debit');
    $wallet->update(['suspense_account_id' => $suspense->id]);

    AccountingTestHelper::createPostedEntry($wallet, now()->subDays(3)->toDateString(), [
        [$bankAccount->chartOfAccount, 'debit', 100000],
        [$equity, 'credit', 100000],
    ]);

    AccountingTestHelper::createPostedEntry($wallet, now()->subDay()->toDateString(), [
        [$expense, 'debit', 25000],
        [$bankAccount->chartOfAccount, 'credit', 25000],
    ]);
    AccountingTestHelper::createDraftEntry($wallet, now()->toDateString(), [
        [$bankAccount->chartOfAccount, 'debit', 30000],
        [$equity, 'credit', 30000],
    ], 'ofx');

    $data = app(BuildBankAccountWorkspace::class)->index($wallet);

    expect($data['summary']['total_statement_balance_cents'])->toBe(105000)
        ->and($data['summary']['total_accounting_balance_cents'])->toBe(75000)
        ->and($data['summary']['total_current_balance_cents'])->toBe(105000)
        ->and($data['summary']['accounts_count'])->toBe(1)
        ->and($data['accounts'][0]['statement_balance_cents'])->toBe(105000)
        ->and($data['accounts'][0]['accounting_balance_cents'])->toBe(75000)
        ->and($data['accounts'][0]['current_balance_cents'])->toBe(105000)
        ->and($data['accounts'][0]['last_transaction_at'])->toBe(now()->toDateString())
        ->and($data['accounts'][0]['show_url'])->toBe(route('bank-accounts.show', $bankAccount))
        ->and($data['accounts'][0]['show_url'])->not->toContain('/statement');
});

it('builds a bank account workspace with recent transactions and actions', function () {
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
    $expense = AccountingTestHelper::account($wallet, '5.91.1', 'Despesa Administrativa', 'despesa', 'debit');

    $suspense = AccountingTestHelper::account($wallet, '1.1.9', 'A classificar', 'ativo', 'debit');
    $wallet->update(['suspense_account_id' => $suspense->id]);

    AccountingTestHelper::createPostedEntry($wallet, now()->subDays(3)->toDateString(), [
        [$bankAccount->chartOfAccount, 'debit', 100000],
        [$equity, 'credit', 100000],
    ]);

    AccountingTestHelper::createPostedEntry($wallet, now()->subDay()->toDateString(), [
        [$expense, 'debit', 25000],
        [$bankAccount->chartOfAccount, 'credit', 25000],
    ]);
    AccountingTestHelper::createDraftEntry($wallet, now()->toDateString(), [
        [$expense, 'debit', 10000],
        [$bankAccount->chartOfAccount, 'credit', 10000],
    ], 'ofx');
    AccountingTestHelper::createDraftEntry($wallet, now()->toDateString(), [
        [$bankAccount->chartOfAccount, 'debit', 7000],
        [$suspense, 'credit', 7000],
    ], 'ofx');
    $paymentEntry = AccountingTestHelper::createDraftEntry($wallet, now()->toDateString(), [
        [$suspense, 'debit', 8000],
        [$bankAccount->chartOfAccount, 'credit', 8000],
    ], 'ofx');
    $paymentBankLine = $paymentEntry->lines()
        ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
        ->firstOrFail();
    $import = BankStatementImport::query()->create([
        'wallet_id' => $wallet->id,
        'bank_account_id' => $bankAccount->id,
        'source' => 'ofx',
        'original_filename' => 'resumo-conta.ofx',
        'file_hash' => hash('sha256', 'resumo-conta-'.$wallet->id),
        'status' => 'completed',
    ]);
    BankStatementImportTransaction::query()->create([
        'bank_statement_import_id' => $import->id,
        'wallet_id' => $wallet->id,
        'bank_account_id' => $bankAccount->id,
        'journal_entry_id' => $paymentEntry->id,
        'journal_line_id' => $paymentBankLine->id,
        'external_id' => 'workspace-payment-'.$paymentEntry->id,
        'transaction_hash' => hash('sha256', 'workspace-payment-'.$paymentEntry->id),
        'posted_at' => now()->toDateString(),
        'description' => 'Pagamento pendente de vínculo',
        'amount_cents' => 8000,
        'direction' => 'out',
        'operation_type' => OfxOperationTypePolicy::PAYMENT,
        'status' => 'imported',
        'resolution' => 'created',
    ]);

    $data = app(BuildBankAccountWorkspace::class)->show($wallet, $bankAccount);

    expect($data['summary']['statement_balance_cents'])->toBe(64000)
        ->and($data['summary']['accounting_balance_cents'])->toBe(75000)
        ->and($data['summary']['current_balance_cents'])->toBe(64000)
        ->and($data['account']['statement_balance_cents'])->toBe(64000)
        ->and($data['account']['accounting_balance_cents'])->toBe(75000)
        ->and($data['account']['current_balance_cents'])->toBe(64000)
        ->and($data['summary']['month_inflows_cents'])->toBe(100000)
        ->and($data['summary']['month_outflows_cents'])->toBe(25000)
        ->and($data['recent_transactions'])->toHaveCount(2)
        ->and($data['summary']['unclassified_entries'])->toBe(1)
        ->and($data['summary']['ready_for_accounting_entries'])->toBe(1)
        ->and($data['summary']['pending_link_entries'])->toBe(1)
        ->and($data['summary']['posted_entries'])->toBe(2)
        ->and($data['actions']['statement_url'])->toContain('/bank-accounts/'.$bankAccount->id.'/statement')
        ->and($data['actions'])->not->toHaveKey('ofx_import_url')
        ->and($data['actions'])->not->toHaveKey('reconciliation_url');
});

it('lists only credit cards from the bank account institution', function () {
    $wallet = Wallet::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Carteira']);
    $nubank = Bank::query()->create(['code' => '999', 'name' => 'Nubank', 'short_name' => 'Nubank', 'ispb' => '99999999', 'active' => true]);
    $itau = Bank::query()->create(['code' => '341', 'name' => 'Itaú', 'short_name' => 'Itaú', 'ispb' => '60701190', 'active' => true]);
    $nubankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.101', 'Conta Nubank');
    $itauAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.102', 'Conta Itaú');
    $nubankAccount->update(['bank_id' => $nubank->id]);
    $itauAccount->update(['bank_id' => $itau->id]);
    $group = $wallet->chartOfAccounts()->where('code', '2.2')->firstOrFail();
    $liability = AccountingTestHelper::account($wallet, '2.2.901', 'Cartão teste', 'passivo', 'credit');
    $liability->update(['parent_id' => $group->id]);
    foreach ([[$nubank, 'Cartão Nubank'], [$itau, 'Cartão Itaú']] as [$bank, $name]) {
        CreditCard::query()->create([
            'wallet_id' => $wallet->id, 'issuer_bank_id' => $bank->id, 'liability_account_id' => $liability->id,
            'name' => $name, 'issuer_name' => $bank->short_name, 'network' => 'mastercard', 'card_type' => 'main',
            'closing_day' => 5, 'due_day' => 15, 'best_purchase_day' => 6, 'credit_limit_cents' => 100000, 'is_active' => true,
        ]);
    }

    expect(collect(app(BuildBankAccountWorkspace::class)->show($wallet, $nubankAccount)['credit_cards'])->pluck('name')->all())
        ->toBe(['Cartão Nubank'])
        ->and(collect(app(BuildBankAccountWorkspace::class)->show($wallet, $itauAccount)['credit_cards'])->pluck('name')->all())
        ->toBe(['Cartão Itaú']);
});
