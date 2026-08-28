<?php

use App\DTOs\Financial\AccountPayableDTO;
use App\DTOs\Financial\AccountReceivableDTO;
use App\DTOs\Financial\CashFlowFiltersDTO;
use App\DTOs\Financial\PayAccountPayableDTO;
use App\DTOs\Financial\ReceiveAccountReceivableDTO;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\BankStatementImport;
use App\Models\BankStatementImportTransaction;
use App\Models\FinancialTitleSettlementReversal;
use App\Models\JournalEntry;
use App\Models\MonthlyWalletClosing;
use App\Models\User;
use App\Services\Accounting\PostJournalEntry;
use App\Services\Financial\BuildCashFlow;
use App\Services\Financial\CancelAccountPayable;
use App\Services\Financial\CreateAccountPayable;
use App\Services\Financial\CreateAccountReceivable;
use App\Services\Financial\PayAccountPayable;
use App\Services\Financial\ReceiveAccountReceivable;
use App\Services\Financial\ReverseAccountPayableSettlement;
use App\Services\Financial\ReverseAccountReceivableSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

function manualReversalContext(): array
{
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();

    return [
        'user' => $user, 'wallet' => $wallet,
        'expense' => $wallet->chartOfAccounts()->where('type', 'despesa')->where('allows_posting', true)->firstOrFail(),
        'revenue' => $wallet->chartOfAccounts()->where('type', 'receita')->where('allows_posting', true)->firstOrFail(),
        'payable' => $wallet->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->firstOrFail(),
        'receivable' => $wallet->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->firstOrFail(),
        'bank' => FinancialTestHelper::bankAccount($wallet, '1.1.2.990', 'Banco reversão manual'),
    ];
}

function manualReversalPayable(array $c, array $overrides = []): AccountPayable
{
    return app(CreateAccountPayable::class)->execute($c['wallet'], AccountPayableDTO::fromArray(array_merge([
        'expense_account_id' => $c['expense']->id, 'payable_account_id' => $c['payable']->id,
        'payee_name' => 'Fornecedor', 'description' => 'AP reversal', 'due_date' => '2026-01-10', 'amount_cents' => 40_000,
    ], $overrides)));
}

function manualReversalReceivable(array $c, array $overrides = []): AccountReceivable
{
    return app(CreateAccountReceivable::class)->execute($c['wallet'], AccountReceivableDTO::fromArray(array_merge([
        'revenue_account_id' => $c['revenue']->id, 'receivable_account_id' => $c['receivable']->id,
        'customer_name' => 'Cliente', 'description' => 'AR reversal', 'due_date' => '2026-01-10', 'amount_cents' => 40_000,
    ], $overrides)));
}

function settleManually(array $c, AccountPayable|AccountReceivable $title, string $date = '2026-02-05'): JournalEntry
{
    if ($title instanceof AccountPayable) {
        app(PayAccountPayable::class)->execute($c['wallet'], $title, new PayAccountPayableDTO($c['bank']->id, $date));

        return $title->fresh()->paymentJournalEntry;
    }
    app(ReceiveAccountReceivable::class)->execute($c['wallet'], $title, new ReceiveAccountReceivableDTO($c['bank']->id, $date));

    return $title->fresh()->receiptJournalEntry;
}

it('voids draft AP and AR settlements and preserves history and provisions', function () {
    $c = manualReversalContext();
    $ap = manualReversalPayable($c);
    $ar = manualReversalReceivable($c);
    $apProvision = $ap->provision_journal_entry_id;
    $arProvision = $ar->provision_journal_entry_id;
    $payment = settleManually($c, $ap);
    $receipt = settleManually($c, $ar);

    app(ReverseAccountPayableSettlement::class)->execute($c['wallet'], $ap, $c['user'], 'Pagamento incorreto');
    app(ReverseAccountReceivableSettlement::class)->execute($c['wallet'], $ar, $c['user'], 'Recebimento incorreto');

    expect($ap->fresh()->status)->toBe('pending')->and($ap->fresh()->paid_at)->toBeNull()
        ->and($ap->fresh()->bank_account_id)->toBeNull()->and($ap->fresh()->payment_journal_entry_id)->toBeNull()
        ->and($ar->fresh()->status)->toBe('pending')->and($ar->fresh()->received_at)->toBeNull()
        ->and($ar->fresh()->bank_account_id)->toBeNull()->and($ar->fresh()->receipt_journal_entry_id)->toBeNull()
        ->and(JournalEntry::query()->find($payment->id))->toBeNull()->and(JournalEntry::query()->find($receipt->id))->toBeNull()
        ->and(JournalEntry::query()->find($apProvision))->not->toBeNull()->and(JournalEntry::query()->find($arProvision))->not->toBeNull();
    expect($ap->settlementReversals()->first()->mode)->toBe('draft_void')
        ->and($ap->settlementReversals()->first()->settlement_journal_entry_id)->toBeNull()
        ->and($ap->settlementReversals()->first()->settlement_journal_entry_id_snapshot)->toBe($payment->id)
        ->and($ar->settlementReversals()->first()->settlement_journal_entry_id_snapshot)->toBe($receipt->id);
    $cashFlow = app(BuildCashFlow::class)->handle($c['wallet'], new CashFlowFiltersDTO(startDate: '2026-01-01', endDate: '2026-03-31'));
    expect($cashFlow['summary']['projected_outflows_cents'])->toBe(40_000)
        ->and($cashFlow['summary']['projected_inflows_cents'])->toBe(40_000)
        ->and($cashFlow['summary']['realized_outflows_cents'])->toBe(0)
        ->and($cashFlow['summary']['realized_inflows_cents'])->toBe(0);
});

it('reverses posted AP and AR settlements atomically while preserving originals and closing', function () {
    $c = manualReversalContext();
    $ap = manualReversalPayable($c);
    $ar = manualReversalReceivable($c);
    $payment = app(PostJournalEntry::class)->handle(settleManually($c, $ap));
    $receipt = app(PostJournalEntry::class)->handle(settleManually($c, $ar));
    $paymentSnapshot = $payment->fresh('lines')->toArray();
    $receiptSnapshot = $receipt->fresh('lines')->toArray();
    $closing = MonthlyWalletClosing::query()->create([
        'wallet_id' => $c['wallet']->id, 'year' => 2026, 'month' => 2, 'period_start' => '2026-02-01',
        'period_end' => '2026-02-28', 'status' => 'closed', 'closed_at' => now(), 'closed_by' => $c['user']->id,
    ]);
    $closedAt = $closing->closed_at;

    app(ReverseAccountPayableSettlement::class)->execute($c['wallet'], $ap, $c['user'], 'Estorno AP', '2026-03-05');
    app(ReverseAccountReceivableSettlement::class)->execute($c['wallet'], $ar, $c['user'], 'Estorno AR', '2026-03-05');

    foreach ([[$ap->fresh(), $payment, $paymentSnapshot], [$ar->fresh(), $receipt, $receiptSnapshot]] as [$title, $original, $snapshot]) {
        $history = $title->settlementReversals()->with('reversalJournalEntry.lines')->firstOrFail();
        expect($title->status)->toBe('pending')->and($original->fresh('lines')->toArray())->toBe($snapshot)
            ->and($history->mode)->toBe('posted_reversal')->and($history->reversalJournalEntry->status)->toBe('posted')
            ->and($history->reversalJournalEntry->reversal_of_journal_entry_id)->toBe($original->id)
            ->and($history->reversalJournalEntry->lines->where('type', 'debit')->sum('amount_cents'))->toBe(40_000)
            ->and($history->reversalJournalEntry->lines->where('type', 'credit')->sum('amount_cents'))->toBe(40_000);
    }
    expect($closing->fresh()->status)->toBe('closed')->and($closing->fresh()->closed_at->equalTo($closedAt))->toBeTrue();
    $cashFlow = app(BuildCashFlow::class)->handle($c['wallet'], new CashFlowFiltersDTO(startDate: '2026-01-01', endDate: '2026-03-31'));
    expect($cashFlow['summary']['projected_outflows_cents'])->toBe(40_000)
        ->and($cashFlow['summary']['projected_inflows_cents'])->toBe(40_000)
        ->and($cashFlow['summary']['realized_outflows_cents'])->toBe(80_000)
        ->and($cashFlow['summary']['realized_inflows_cents'])->toBe(80_000);
});

it('rejects missing early and closed posted reversal dates without partial mutations', function () {
    $c = manualReversalContext();
    MonthlyWalletClosing::query()->create([
        'wallet_id' => $c['wallet']->id, 'year' => 2026, 'month' => 3, 'period_start' => '2026-03-01',
        'period_end' => '2026-03-31', 'status' => 'closed', 'closed_at' => now(), 'closed_by' => $c['user']->id,
    ]);
    foreach ([[manualReversalPayable($c), ReverseAccountPayableSettlement::class], [manualReversalReceivable($c), ReverseAccountReceivableSettlement::class]] as [$title, $service]) {
        $settlement = app(PostJournalEntry::class)->handle(settleManually($c, $title));
        foreach ([null, '2026-01-01', '2026-03-05'] as $date) {
            expect(fn () => app($service)->execute($c['wallet'], $title, $c['user'], 'Inválido', $date))->toThrow(ValidationException::class);
            expect($title->fresh()->status)->toBe($title instanceof AccountPayable ? 'paid' : 'received');
        }
        expect($settlement->fresh()->status)->toBe('posted');
    }
    expect(FinancialTitleSettlementReversal::query()->count())->toBe(0);
});

it('rejects draft settlement undo in a closed period', function () {
    $c = manualReversalContext();
    $ap = manualReversalPayable($c);
    $ar = manualReversalReceivable($c);
    $payment = settleManually($c, $ap);
    $receipt = settleManually($c, $ar);
    MonthlyWalletClosing::query()->create([
        'wallet_id' => $c['wallet']->id, 'year' => 2026, 'month' => 2, 'period_start' => '2026-02-01',
        'period_end' => '2026-02-28', 'status' => 'closed', 'closed_at' => now(), 'closed_by' => $c['user']->id,
    ]);

    expect(fn () => app(ReverseAccountPayableSettlement::class)->execute($c['wallet'], $ap, $c['user'], 'Fechado'))->toThrow(ValidationException::class)
        ->and(fn () => app(ReverseAccountReceivableSettlement::class)->execute($c['wallet'], $ar, $c['user'], 'Fechado'))->toThrow(ValidationException::class)
        ->and($ap->fresh()->status)->toBe('paid')->and($ar->fresh()->status)->toBe('received')
        ->and(JournalEntry::query()->find($payment->id))->not->toBeNull()->and(JournalEntry::query()->find($receipt->id))->not->toBeNull()
        ->and(FinancialTitleSettlementReversal::query()->count())->toBe(0);
});

it('is idempotent and supports repeated manual settlement cycles', function () {
    $c = manualReversalContext();
    $ap = manualReversalPayable($c);
    settleManually($c, $ap);
    app(ReverseAccountPayableSettlement::class)->execute($c['wallet'], $ap, $c['user'], 'Primeiro');
    app(ReverseAccountPayableSettlement::class)->execute($c['wallet'], $ap, User::factory()->create(), 'Ignorado');
    expect($ap->settlementReversals()->count())->toBe(1)->and($ap->settlementReversals()->first()->reason)->toBe('Primeiro');

    settleManually($c, $ap, '2026-02-10');
    app(ReverseAccountPayableSettlement::class)->execute($c['wallet'], $ap, $c['user'], 'Segundo');
    expect($ap->settlementReversals()->count())->toBe(2)->and($ap->fresh()->status)->toBe('pending');

    $never = manualReversalPayable($c);
    expect(fn () => app(ReverseAccountPayableSettlement::class)->execute($c['wallet'], $never, $c['user'], 'Nada'))->toThrow(ValidationException::class);
});

it('refreshes installment series when settlements are reopened', function () {
    $c = manualReversalContext();
    $ap = manualReversalPayable($c, ['mode' => 'installment', 'installment_count' => 2]);
    $ar = manualReversalReceivable($c, ['mode' => 'installment', 'installment_count' => 2]);
    $apTitles = $ap->series->payables;
    $arTitles = $ar->series->receivables;
    foreach ($apTitles as $title) {
        settleManually($c, $title);
    }
    foreach ($arTitles as $title) {
        settleManually($c, $title);
    }

    app(ReverseAccountPayableSettlement::class)->execute($c['wallet'], $apTitles->last(), $c['user'], 'Reabrir parcela');
    app(ReverseAccountReceivableSettlement::class)->execute($c['wallet'], $arTitles->last(), $c['user'], 'Reabrir parcela');

    expect($ap->series->fresh()->status)->toBe('partially_settled')->and($ar->series->fresh()->status)->toBe('partially_settled')
        ->and($apTitles->last()->fresh()->status)->toBe('pending')->and($arTitles->last()->fresh()->status)->toBe('pending');
});

it('returns a cancelled plus settled series to pending when settlement is reversed', function () {
    $c = manualReversalContext();
    $ap = manualReversalPayable($c, ['mode' => 'installment', 'installment_count' => 2]);
    $first = $ap->series->payables()->where('installment_number', 1)->firstOrFail();
    $second = $ap->series->payables()->where('installment_number', 2)->firstOrFail();
    app(CancelAccountPayable::class)->execute($c['wallet'], $first, $c['user'], 'Cancelar primeira');
    settleManually($c, $second);
    expect($ap->series->fresh()->status)->toBe('settled');

    app(ReverseAccountPayableSettlement::class)->execute($c['wallet'], $second, $c['user'], 'Reabrir segunda');

    expect($first->fresh()->status)->toBe('cancelled')->and($second->fresh()->status)->toBe('pending')
        ->and($ap->series->fresh()->status)->toBe('pending');
});

it('blocks settlements linked to imported bank movements even when their source is manual', function () {
    $c = manualReversalContext();
    $ap = manualReversalPayable($c);
    $ar = manualReversalReceivable($c);
    foreach ([[$ap, settleManually($c, $ap), ReverseAccountPayableSettlement::class, 'out'], [$ar, settleManually($c, $ar), ReverseAccountReceivableSettlement::class, 'in']] as $index => [$title, $settlement, $service, $direction]) {
        $import = BankStatementImport::query()->create([
            'wallet_id' => $c['wallet']->id, 'bank_account_id' => $c['bank']->id, 'source' => 'ofx',
            'original_filename' => "bank-{$index}.ofx", 'file_hash' => str_repeat((string) ($index + 1), 64),
        ]);
        BankStatementImportTransaction::query()->create([
            'bank_statement_import_id' => $import->id, 'wallet_id' => $c['wallet']->id, 'bank_account_id' => $c['bank']->id,
            'journal_entry_id' => $settlement->id, 'external_id' => "BANK-{$index}", 'posted_at' => '2026-02-05',
            'description' => 'Movimento importado', 'amount_cents' => 40_000, 'direction' => $direction, 'status' => 'imported',
        ]);
        expect(fn () => app($service)->execute($c['wallet'], $title, $c['user'], 'Não permitido'))->toThrow(ValidationException::class);
        expect($title->fresh()->status)->toBe($title instanceof AccountPayable ? 'paid' : 'received')
            ->and(JournalEntry::query()->find($settlement->id))->not->toBeNull();
    }
});

it('composes posted settlement reversal with posted provision cancellation', function () {
    $c = manualReversalContext();
    $ap = manualReversalPayable($c);
    $provision = app(PostJournalEntry::class)->handle($ap->provisionJournalEntry);
    $payment = app(PostJournalEntry::class)->handle(settleManually($c, $ap));
    app(ReverseAccountPayableSettlement::class)->execute($c['wallet'], $ap, $c['user'], 'Reabrir', '2026-03-05');
    app(CancelAccountPayable::class)->execute($c['wallet'], $ap, $c['user'], 'Cancelar depois', '2026-03-06');

    expect($ap->fresh()->status)->toBe('cancelled')
        ->and($ap->settlementReversals()->first()->reversalJournalEntry->reversal_of_journal_entry_id)->toBe($payment->id)
        ->and($ap->fresh()->cancellationJournalEntry->reversal_of_journal_entry_id)->toBe($provision->id);
});

it('validates and executes the semantic manual settlement reversal endpoints', function () {
    $c = manualReversalContext();
    $ap = manualReversalPayable($c);
    $ar = manualReversalReceivable($c);
    settleManually($c, $ap);
    settleManually($c, $ar);

    $this->actingAs($c['user'])->withSession(['active_wallet' => $c['wallet']->id])
        ->post(route('accounts-payable.reverse-settlement', $ap), ['reason' => '   '])->assertSessionHasErrors('reason');
    $this->post(route('accounts-payable.reverse-settlement', $ap), ['reason' => 'Correção AP'])
        ->assertRedirect(route('accounts-payable.show', $ap));
    $this->post(route('accounts-receivable.reverse-settlement', $ar), ['reason' => 'Correção AR'])
        ->assertRedirect(route('accounts-receivable.show', $ar));

    expect($ap->fresh()->status)->toBe('pending')->and($ar->fresh()->status)->toBe('pending');
});
