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
use App\Services\Accounting\IncomeStatementService;
use App\Services\Accounting\PostJournalEntry;
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

function postedCancellationContext(): array
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

function postedPayable(array $c, array $overrides = []): AccountPayable
{
    return app(CreateAccountPayable::class)->execute($c['wallet'], AccountPayableDTO::fromArray(array_merge([
        'expense_account_id' => $c['expense']->id, 'payable_account_id' => $c['payable']->id,
        'payee_name' => 'Fornecedor', 'description' => 'AP posted', 'due_date' => '2026-01-10', 'amount_cents' => 60_000,
    ], $overrides)));
}

function postedReceivable(array $c, array $overrides = []): AccountReceivable
{
    return app(CreateAccountReceivable::class)->execute($c['wallet'], AccountReceivableDTO::fromArray(array_merge([
        'revenue_account_id' => $c['revenue']->id, 'receivable_account_id' => $c['receivable']->id,
        'customer_name' => 'Cliente', 'description' => 'AR posted', 'due_date' => '2026-01-10', 'amount_cents' => 60_000,
    ], $overrides)));
}

function postProvision(AccountPayable|AccountReceivable $title): JournalEntry
{
    $entry = $title->series?->provisionJournalEntry ?? $title->provisionJournalEntry;

    return app(PostJournalEntry::class)->handle($entry);
}

it('cancels single AP and AR through posted compensating reversals without mutating originals', function () {
    $c = postedCancellationContext();
    $ap = postedPayable($c);
    $ar = postedReceivable($c);
    $apOriginal = postProvision($ap);
    $arOriginal = postProvision($ar);
    $apSnapshot = $apOriginal->fresh('lines')->toArray();
    $arSnapshot = $arOriginal->fresh('lines')->toArray();

    app(CancelAccountPayable::class)->execute($c['wallet'], $ap, $c['user'], 'Cancel AP', '2026-03-05');
    app(CancelAccountReceivable::class)->execute($c['wallet'], $ar, $c['user'], 'Cancel AR', '2026-03-05');

    foreach ([[$ap->fresh(), $apOriginal, $apSnapshot], [$ar->fresh(), $arOriginal, $arSnapshot]] as [$title, $original, $snapshot]) {
        $reversal = $title->cancellationJournalEntry()->with('lines')->firstOrFail();
        expect($title->status)->toBe('cancelled')->and($reversal->status)->toBe('posted')
            ->and($reversal->posted_at)->not->toBeNull()->and($reversal->reversal_of_journal_entry_id)->toBe($original->id)
            ->and($reversal->lines->where('type', 'debit')->sum('amount_cents'))->toBe(60_000)
            ->and($reversal->lines->where('type', 'credit')->sum('amount_cents'))->toBe(60_000)
            ->and($original->fresh('lines')->toArray())->toBe($snapshot);
        foreach ($original->lines as $line) {
            expect($reversal->lines->firstWhere('chart_of_account_id', $line->chart_of_account_id)?->type)
                ->toBe($line->type === 'debit' ? 'credit' : 'debit');
            $net = $original->lines->where('chart_of_account_id', $line->chart_of_account_id)
                ->sum(fn ($item) => $item->type === 'debit' ? $item->amount_cents : -$item->amount_cents)
                + $reversal->lines->where('chart_of_account_id', $line->chart_of_account_id)
                    ->sum(fn ($item) => $item->type === 'debit' ? $item->amount_cents : -$item->amount_cents);
            expect($net)->toBe(0);
        }
    }

    $reports = app(IncomeStatementService::class);
    expect($reports->build($c['wallet'], '2026-01-01', '2026-01-31')->expenseCents())->toBe(60_000)
        ->and($reports->build($c['wallet'], '2026-01-01', '2026-01-31')->revenueCents())->toBe(60_000)
        ->and($reports->build($c['wallet'], '2026-03-01', '2026-03-31')->expenseCents())->toBe(-60_000)
        ->and($reports->build($c['wallet'], '2026-03-01', '2026-03-31')->revenueCents())->toBe(-60_000)
        ->and($reports->build($c['wallet'], '2026-01-01', '2026-03-31')->netIncomeCents())->toBe(0);
});

it('allows an open reversal period while preserving the closed original period', function () {
    $c = postedCancellationContext();
    $ap = postedPayable($c);
    $ar = postedReceivable($c);
    $apOriginal = postProvision($ap);
    $arOriginal = postProvision($ar);
    $closing = MonthlyWalletClosing::query()->create([
        'wallet_id' => $c['wallet']->id, 'year' => 2026, 'month' => 1, 'period_start' => '2026-01-01',
        'period_end' => '2026-01-31', 'status' => 'closed', 'closed_at' => now(), 'closed_by' => $c['user']->id,
    ]);
    $closedAt = $closing->closed_at;

    app(CancelAccountPayable::class)->execute($c['wallet'], $ap, $c['user'], 'Período posterior', '2026-03-05');
    app(CancelAccountReceivable::class)->execute($c['wallet'], $ar, $c['user'], 'Período posterior', '2026-03-05');

    expect($ap->fresh()->status)->toBe('cancelled')->and($ar->fresh()->status)->toBe('cancelled')
        ->and($apOriginal->fresh()->status)->toBe('posted')->and($arOriginal->fresh()->status)->toBe('posted')
        ->and($ap->fresh()->cancellationJournalEntry->entry_date->toDateString())->toBe('2026-03-05')
        ->and($ar->fresh()->cancellationJournalEntry->entry_date->toDateString())->toBe('2026-03-05')
        ->and($closing->fresh()->status)->toBe('closed')->and($closing->fresh()->closed_at->equalTo($closedAt))->toBeTrue();
});

it('keeps settlement journals intact when the remaining posted installment is cancelled', function () {
    $c = postedCancellationContext();
    $bank = FinancialTestHelper::bankAccount($c['wallet'], '1.1.2.889', 'Banco 6D');
    $ap = postedPayable($c, ['mode' => 'installment', 'installment_count' => 2]);
    $ar = postedReceivable($c, ['mode' => 'installment', 'installment_count' => 2]);
    postProvision($ap);
    postProvision($ar);
    $apSecond = $ap->series->payables()->where('installment_number', 2)->firstOrFail();
    $arSecond = $ar->series->receivables()->where('installment_number', 2)->firstOrFail();
    app(PayAccountPayable::class)->execute($c['wallet'], $ap, new PayAccountPayableDTO($bank->id, '2026-02-05'));
    app(ReceiveAccountReceivable::class)->execute($c['wallet'], $ar, new ReceiveAccountReceivableDTO($bank->id, '2026-02-05'));
    $paymentId = $ap->fresh()->payment_journal_entry_id;
    $receiptId = $ar->fresh()->receipt_journal_entry_id;

    app(CancelAccountPayable::class)->execute($c['wallet'], $apSecond, $c['user'], 'Restante', '2026-03-05');
    app(CancelAccountReceivable::class)->execute($c['wallet'], $arSecond, $c['user'], 'Restante', '2026-03-05');

    expect($ap->series->fresh()->status)->toBe('settled')->and($ar->series->fresh()->status)->toBe('settled')
        ->and($ap->fresh()->payment_journal_entry_id)->toBe($paymentId)->and($ar->fresh()->receipt_journal_entry_id)->toBe($receiptId)
        ->and($apSecond->fresh()->cancellationJournalEntry->lines()->where('type', 'debit')->sum('amount_cents'))->toBe($apSecond->amount_cents)
        ->and($arSecond->fresh()->cancellationJournalEntry->lines()->where('type', 'debit')->sum('amount_cents'))->toBe($arSecond->amount_cents);
});

it('rejects missing early and closed reversal dates atomically for AP and AR', function () {
    $c = postedCancellationContext();
    MonthlyWalletClosing::query()->create([
        'wallet_id' => $c['wallet']->id, 'year' => 2026, 'month' => 3, 'period_start' => '2026-03-01',
        'period_end' => '2026-03-31', 'status' => 'closed', 'closed_at' => now(), 'closed_by' => $c['user']->id,
    ]);
    $cases = [
        [postedPayable($c), CancelAccountPayable::class],
        [postedReceivable($c), CancelAccountReceivable::class],
    ];
    foreach ($cases as [$title, $service]) {
        postProvision($title);
        foreach ([null, '2025-12-31', '2026-03-05'] as $date) {
            expect(fn () => app($service)->execute($c['wallet'], $title, $c['user'], 'Inválido', $date))->toThrow(ValidationException::class);
            expect($title->fresh()->status)->toBe('pending')->and($title->fresh()->cancellation_journal_entry_id)->toBeNull();
        }
    }
    expect(JournalEntry::query()->whereNotNull('reversal_of_journal_entry_id')->count())->toBe(0);
});

it('is idempotent and creates only one reversal for each posted title', function () {
    $c = postedCancellationContext();
    $ap = postedPayable($c);
    postProvision($ap);
    $first = app(CancelAccountPayable::class)->execute($c['wallet'], $ap, $c['user'], 'Original', '2026-03-05');
    $other = User::factory()->create();

    app(CancelAccountPayable::class)->execute($c['wallet'], $ap, $other, 'Outro', '2026-04-05');

    expect($ap->fresh()->cancellation_journal_entry_id)->toBe($first->cancellation_journal_entry_id)
        ->and($ap->fresh()->cancellation_reason)->toBe('Original')
        ->and($ap->fresh()->cancelled_by_user_id)->toBe($c['user']->id)
        ->and(JournalEntry::query()->whereNotNull('reversal_of_journal_entry_id')->count())->toBe(1);
});

it('creates independent partial reversals for every cancelled installment and preserves the original provision', function () {
    $c = postedCancellationContext();
    $ap = postedPayable($c, ['mode' => 'installment', 'installment_count' => 3]);
    $ar = postedReceivable($c, ['mode' => 'installment', 'installment_count' => 3]);
    $apOriginal = postProvision($ap);
    $arOriginal = postProvision($ar);

    foreach ([$ap->series->payables, $ar->series->receivables] as $titles) {
        foreach ($titles as $title) {
            $service = $title instanceof AccountPayable ? CancelAccountPayable::class : CancelAccountReceivable::class;
            app($service)->execute($c['wallet'], $title, $c['user'], 'Parcela', '2026-03-05');
        }
    }

    foreach ([[$ap->series->fresh(), $apOriginal], [$ar->series->fresh(), $arOriginal]] as [$series, $original]) {
        $reversals = $original->reversals()->with('lines')->get();
        expect($series->status)->toBe('cancelled')->and($series->total_amount_cents)->toBe(60_000)
            ->and($original->fresh()->status)->toBe('posted')->and($reversals)->toHaveCount(3)
            ->and($reversals->sum(fn ($entry) => $entry->lines->where('type', 'debit')->sum('amount_cents')))->toBe(60_000);
    }
});

it('keeps draft and null provision cancellation compatible without a reversal date', function () {
    $c = postedCancellationContext();
    $draft = postedPayable($c);
    $legacy = AccountReceivable::query()->create([
        'wallet_id' => $c['wallet']->id, 'revenue_account_id' => $c['revenue']->id,
        'receivable_account_id' => $c['receivable']->id, 'customer_name' => 'Legacy', 'description' => 'Legacy',
        'due_date' => '2026-01-10', 'amount_cents' => 1000, 'status' => 'pending',
    ]);

    app(CancelAccountPayable::class)->execute($c['wallet'], $draft, $c['user'], 'Draft');
    app(CancelAccountReceivable::class)->execute($c['wallet'], $legacy, $c['user'], 'Null');

    expect($draft->fresh()->status)->toBe('cancelled')->and($legacy->fresh()->status)->toBe('cancelled')
        ->and(JournalEntry::query()->whereNotNull('reversal_of_journal_entry_id')->count())->toBe(0);
});
