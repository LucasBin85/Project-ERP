<?php

use App\DTOs\Financial\AccountPayableDTO;
use App\DTOs\Financial\AccountReceivableDTO;
use App\DTOs\Financial\PayAccountPayableDTO;
use App\DTOs\Financial\ReceiveAccountReceivableDTO;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\JournalEntry;
use App\Models\MonthlyWalletClosing;
use App\Models\User;
use App\Services\Financial\CancelAccountPayable;
use App\Services\Financial\CancelAccountReceivable;
use App\Services\Financial\CreateAccountPayable;
use App\Services\Financial\CreateAccountReceivable;
use App\Services\Financial\PayAccountPayable;
use App\Services\Financial\ReceiveAccountReceivable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

function cancellationContext(): array
{
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();

    return [
        'user' => $user,
        'wallet' => $wallet,
        'expense' => $wallet->chartOfAccounts()->where('type', 'despesa')->where('allows_posting', true)->firstOrFail(),
        'revenue' => $wallet->chartOfAccounts()->where('type', 'receita')->where('allows_posting', true)->firstOrFail(),
        'payable' => $wallet->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->firstOrFail(),
        'receivable' => $wallet->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->firstOrFail(),
    ];
}

function cancellationPayable(array $c, array $overrides = []): AccountPayable
{
    return app(CreateAccountPayable::class)->execute($c['wallet'], AccountPayableDTO::fromArray(array_merge([
        'expense_account_id' => $c['expense']->id, 'payee_name' => 'Fornecedor', 'description' => 'Título AP',
        'due_date' => '2026-07-20', 'amount_cents' => 30_001, 'payable_account_id' => $c['payable']->id,
    ], $overrides)));
}

function cancellationReceivable(array $c, array $overrides = []): AccountReceivable
{
    return app(CreateAccountReceivable::class)->execute($c['wallet'], AccountReceivableDTO::fromArray(array_merge([
        'revenue_account_id' => $c['revenue']->id, 'customer_name' => 'Cliente', 'description' => 'Título AR',
        'due_date' => '2026-07-20', 'amount_cents' => 30_001, 'receivable_account_id' => $c['receivable']->id,
    ], $overrides)));
}

it('cancels single AP and AR with audit metadata and removes their draft provisions', function () {
    $c = cancellationContext();
    $payable = cancellationPayable($c);
    $receivable = cancellationReceivable($c);
    $payableProvision = $payable->provision_journal_entry_id;
    $receivableProvision = $receivable->provision_journal_entry_id;

    app(CancelAccountPayable::class)->execute($c['wallet'], $payable, $c['user'], 'Compra desfeita');
    app(CancelAccountReceivable::class)->execute($c['wallet'], $receivable, $c['user'], 'Contrato encerrado');

    expect($payable->fresh()->status)->toBe('cancelled')->and($payable->fresh()->amount_cents)->toBe(30_001)
        ->and($payable->fresh()->cancelled_by_user_id)->toBe($c['user']->id)->and($payable->fresh()->cancelled_at)->not->toBeNull()
        ->and($receivable->fresh()->status)->toBe('cancelled')->and($receivable->fresh()->amount_cents)->toBe(30_001)
        ->and(JournalEntry::query()->find($payableProvision))->toBeNull()->and(JournalEntry::query()->find($receivableProvision))->toBeNull();
    $this->assertDatabaseHas('accounts_payable', ['id' => $payable->id, 'cancellation_reason' => 'Compra desfeita']);
    $this->assertDatabaseHas('accounts_receivable', ['id' => $receivable->id, 'cancellation_reason' => 'Contrato encerrado']);
});

it('cancels legacy titles without provisions and creates no journal entry', function () {
    $c = cancellationContext();
    $payable = AccountPayable::query()->create(['wallet_id' => $c['wallet']->id, 'expense_account_id' => $c['expense']->id, 'payable_account_id' => $c['payable']->id, 'payee_name' => 'Legacy', 'description' => 'Legacy AP', 'due_date' => '2026-07-20', 'amount_cents' => 1000, 'status' => 'pending']);
    $receivable = AccountReceivable::query()->create(['wallet_id' => $c['wallet']->id, 'revenue_account_id' => $c['revenue']->id, 'receivable_account_id' => $c['receivable']->id, 'customer_name' => 'Legacy', 'description' => 'Legacy AR', 'due_date' => '2026-07-20', 'amount_cents' => 1000, 'status' => 'pending']);

    app(CancelAccountPayable::class)->execute($c['wallet'], $payable, $c['user'], 'Legado');
    app(CancelAccountReceivable::class)->execute($c['wallet'], $receivable, $c['user'], 'Legado');

    expect(JournalEntry::query()->count())->toBe(0)->and($payable->fresh()->status)->toBe('cancelled')->and($receivable->fresh()->status)->toBe('cancelled');
});

it('blocks posted provisions without a reversal date and existing settlements without mutating cancellation metadata', function () {
    $c = cancellationContext();
    $postedAp = cancellationPayable($c);
    $postedAr = cancellationReceivable($c);
    $postedAp->provisionJournalEntry()->update(['status' => 'posted', 'posted_at' => now()]);
    $postedAr->provisionJournalEntry()->update(['status' => 'posted', 'posted_at' => now()]);

    expect(fn () => app(CancelAccountPayable::class)->execute($c['wallet'], $postedAp, $c['user'], 'Não pode'))->toThrow(ValidationException::class)
        ->and(fn () => app(CancelAccountReceivable::class)->execute($c['wallet'], $postedAr, $c['user'], 'Não pode'))->toThrow(ValidationException::class)
        ->and($postedAp->fresh()->status)->toBe('pending')->and($postedAr->fresh()->status)->toBe('pending');

    $settledAp = cancellationPayable($c);
    $settledAr = cancellationReceivable($c);
    $settledAp->update(['payment_journal_entry_id' => $settledAp->provision_journal_entry_id]);
    $settledAr->update(['receipt_journal_entry_id' => $settledAr->provision_journal_entry_id]);
    expect(fn () => app(CancelAccountPayable::class)->execute($c['wallet'], $settledAp, $c['user'], 'Não pode'))->toThrow(ValidationException::class)
        ->and(fn () => app(CancelAccountReceivable::class)->execute($c['wallet'], $settledAr, $c['user'], 'Não pode'))->toThrow(ValidationException::class);
});

it('is idempotent and preserves the original cancellation audit', function () {
    $c = cancellationContext();
    $payable = cancellationPayable($c);
    $first = app(CancelAccountPayable::class)->execute($c['wallet'], $payable, $c['user'], 'Motivo original');
    $timestamp = $first->cancelled_at;
    $other = User::factory()->create();

    app(CancelAccountPayable::class)->execute($c['wallet'], $payable, $other, 'Tentativa de troca');
    expect($payable->fresh()->cancellation_reason)->toBe('Motivo original')
        ->and($payable->fresh()->cancelled_by_user_id)->toBe($c['user']->id)
        ->and($payable->fresh()->cancelled_at->equalTo($timestamp))->toBeTrue();
});

it('blocks cancellation when the draft provision belongs to a closed period', function () {
    $c = cancellationContext();
    $payable = cancellationPayable($c);
    $lines = $payable->provisionJournalEntry->lines()->get()->map->only(['type', 'amount_cents'])->all();
    MonthlyWalletClosing::query()->create(['wallet_id' => $c['wallet']->id, 'year' => 2026, 'month' => 7, 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'status' => 'closed', 'closed_at' => now(), 'closed_by' => $c['user']->id]);

    expect(fn () => app(CancelAccountPayable::class)->execute($c['wallet'], $payable, $c['user'], 'Fechado'))->toThrow(ValidationException::class)
        ->and($payable->fresh()->status)->toBe('pending')->and($payable->provisionJournalEntry->fresh()->status)->toBe('draft')
        ->and($payable->provisionJournalEntry->lines()->get()->map->only(['type', 'amount_cents'])->all())->toBe($lines);
});

it('resizes AP and AR installment provisions and cancels the series when all installments are cancelled', function () {
    $c = cancellationContext();
    $ap = cancellationPayable($c, ['mode' => 'installment', 'installment_count' => 2]);
    $ar = cancellationReceivable($c, ['mode' => 'installment', 'installment_count' => 2]);
    $apSeries = $ap->series()->firstOrFail();
    $arSeries = $ar->series()->firstOrFail();
    $apSecond = $apSeries->payables()->where('installment_number', 2)->firstOrFail();
    $arSecond = $arSeries->receivables()->where('installment_number', 2)->firstOrFail();

    app(CancelAccountPayable::class)->execute($c['wallet'], $ap, $c['user'], 'Parcela AP');
    app(CancelAccountReceivable::class)->execute($c['wallet'], $ar, $c['user'], 'Parcela AR');
    expect($apSeries->fresh()->status)->toBe('pending')->and($arSeries->fresh()->status)->toBe('pending')
        ->and($apSeries->fresh()->total_amount_cents)->toBe(30_001)->and($arSeries->fresh()->total_amount_cents)->toBe(30_001)
        ->and($apSeries->provisionJournalEntry->lines()->pluck('amount_cents')->unique()->all())->toBe([$apSecond->amount_cents])
        ->and($arSeries->provisionJournalEntry->lines()->pluck('amount_cents')->unique()->all())->toBe([$arSecond->amount_cents]);

    app(CancelAccountPayable::class)->execute($c['wallet'], $apSecond, $c['user'], 'Última AP');
    app(CancelAccountReceivable::class)->execute($c['wallet'], $arSecond, $c['user'], 'Última AR');
    expect($apSeries->fresh()->status)->toBe('cancelled')->and($arSeries->fresh()->status)->toBe('cancelled')
        ->and($apSeries->fresh()->provision_journal_entry_id)->toBeNull()->and($arSeries->fresh()->provision_journal_entry_id)->toBeNull();
});

it('marks paid plus cancelled and received plus cancelled series as settled', function () {
    $c = cancellationContext();
    $bank = FinancialTestHelper::bankAccount($c['wallet'], '1.1.2.777', 'Banco cancellation');
    $ap = cancellationPayable($c, ['mode' => 'installment', 'installment_count' => 2]);
    $ar = cancellationReceivable($c, ['mode' => 'installment', 'installment_count' => 2]);
    $apSecond = $ap->series->payables()->where('installment_number', 2)->firstOrFail();
    $arSecond = $ar->series->receivables()->where('installment_number', 2)->firstOrFail();
    app(PayAccountPayable::class)->execute($c['wallet'], $ap, new PayAccountPayableDTO($bank->id, '2026-07-21'));
    app(ReceiveAccountReceivable::class)->execute($c['wallet'], $ar, new ReceiveAccountReceivableDTO($bank->id, '2026-07-21'));

    app(CancelAccountPayable::class)->execute($c['wallet'], $apSecond, $c['user'], 'Restante AP');
    app(CancelAccountReceivable::class)->execute($c['wallet'], $arSecond, $c['user'], 'Restante AR');
    expect($ap->series->fresh()->status)->toBe('settled')->and($ar->series->fresh()->status)->toBe('settled')
        ->and($ap->fresh()->payment_journal_entry_id)->not->toBeNull()->and($ar->fresh()->receipt_journal_entry_id)->not->toBeNull();
});

it('validates cancellation reason through the semantic HTTP routes', function () {
    $c = cancellationContext();
    $payable = cancellationPayable($c);
    $this->actingAs($c['user'])->withSession(['active_wallet' => $c['wallet']->id])
        ->post(route('accounts-payable.cancel', $payable), ['reason' => '   '])->assertSessionHasErrors('reason');
    $this->post(route('accounts-payable.cancel', $payable), ['reason' => 'Solicitado pelo fornecedor'])
        ->assertRedirect(route('accounts-payable.show', $payable));
    expect($payable->fresh()->status)->toBe('cancelled');
});
