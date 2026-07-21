<?php

use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Financial\OfxOperationTypePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

it('quick creates a posting investment account below 1.3 for the active wallet', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.980', 'Banco investimentos');
    $group = ChartOfAccount::query()->where('wallet_id', $wallet->id)->where('code', '1.3')->firstOrFail();

    $response = $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])
        ->postJson(route('investment-accounts.quick-store'), ['name' => '  Tesouro   Selic  ']);

    $response->assertCreated()
        ->assertJsonPath('account.name', 'Tesouro Selic')
        ->assertJsonPath('account.type', 'ativo')
        ->assertJsonPath('account.financial_group', 'investments')
        ->assertJsonPath('account.allowed_operation_types.0', 'investment');

    $account = ChartOfAccount::query()->findOrFail($response->json('account.id'));
    expect($account->parent_id)->toBe($group->id)
        ->and($account->allows_posting)->toBeTrue()
        ->and($account->code)->toStartWith('1.3.')
        ->and(app(OfxOperationTypePolicy::class)->isAccountAllowed($wallet, $bankAccount, OfxOperationTypePolicy::INVESTMENT, $account))->toBeTrue()
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(BankAccount::query()->count())->toBe(1);
});

it('isolates quick investment creation by authentication wallet and duplicate name', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();

    $this->postJson(route('investment-accounts.quick-store'), ['name' => 'IVVB11'])->assertUnauthorized();
    $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])
        ->postJson(route('investment-accounts.quick-store'), ['name' => 'IVVB11'])->assertCreated();
    $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])
        ->postJson(route('investment-accounts.quick-store'), ['name' => 'ivvb11'])
        ->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('renders investment selection quick creation and classified badge behavior', function () {
    $classification = file_get_contents(resource_path('js/components/financial/bankStatements/InlineOfxClassification.vue'));
    $dialog = file_get_contents(resource_path('js/components/financial/bankStatements/InvestmentAccountQuickCreateDialog.vue'));
    $table = file_get_contents(resource_path('js/components/financial/bankStatements/BankStatementTable.vue'));

    expect($classification)->toContain('Conta de investimento destino')
        ->toContain('Conta de investimento origem')
        ->toContain('Cadastrar investimento')
        ->toContain('form.chart_of_account_id = String(account.id)')
        ->toContain('Pronto para contabilidade')
        ->toContain('Investimento')
        ->and($dialog)->toContain("route('investment-accounts.quick-store')")
        ->toContain('1.3 Investimentos')
        ->and($table)->toContain('InlineOfxClassification');
});
