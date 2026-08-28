<?php

use App\DTOs\Financial\CashFlowFiltersDTO;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\BankStatementImport;
use App\Models\BankStatementImportTransaction;
use App\Models\FinancialTitleSettlementReversal;
use App\Models\JournalEntry;
use App\Models\MonthlyWalletClosing;
use App\Models\User;
use App\Services\Accounting\AssessJournalEntryPostingReadiness;
use App\Services\Accounting\CreateBankImportEntry;
use App\Services\Accounting\PostJournalEntry;
use App\Services\Financial\BankAccountBalanceService;
use App\Services\Financial\BuildCashFlow;
use App\Services\Financial\FindBankStatementPayableCandidates;
use App\Services\Financial\LinkAccountPayableFromBankStatement;
use App\Services\Financial\LinkAccountReceivableFromBankStatement;
use App\Services\Financial\OfxOperationTypePolicy;
use App\Services\Financial\ReverseAccountPayableSettlement;
use App\Services\Financial\ReverseAccountReceivableSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

function bankReversalContext(): array
{
    static $sequence = 0;
    $sequence++;
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $bank = FinancialTestHelper::bankAccount($wallet, '1.1.2.95'.$sequence, 'Banco reversal '.$sequence);
    $expense = AccountingTestHelper::account($wallet, '5.95.'.$sequence, 'Despesa reversal', 'despesa', 'debit');
    $revenue = AccountingTestHelper::account($wallet, '4.95.'.$sequence, 'Receita reversal', 'receita', 'credit');
    $import = BankStatementImport::query()->create([
        'wallet_id' => $wallet->id, 'bank_account_id' => $bank->id, 'source' => 'ofx',
        'original_filename' => "reversal-{$sequence}.ofx", 'file_hash' => hash('sha256', "reversal-{$sequence}"), 'status' => 'completed',
    ]);

    return compact('user', 'wallet', 'bank', 'expense', 'revenue', 'import');
}

function bankReversalMovement(array $c, string $direction): array
{
    static $sequence = 0;
    $sequence++;
    $entry = app(CreateBankImportEntry::class)->handle(
        $c['wallet'], $c['bank']->chart_of_account_id, 30_000, $direction, '2026-07-10',
        'Movimento bancário reversal', 'ofx', "bank-reversal-{$sequence}", false,
    );
    $bankLine = $entry->lines->firstWhere('chart_of_account_id', $c['bank']->chart_of_account_id);
    $counterpart = $entry->lines->firstWhere('chart_of_account_id', $c['wallet']->suspense_account_id);
    $audit = BankStatementImportTransaction::query()->create([
        'bank_statement_import_id' => $c['import']->id, 'wallet_id' => $c['wallet']->id, 'bank_account_id' => $c['bank']->id,
        'journal_entry_id' => $entry->id, 'journal_line_id' => $bankLine->id, 'external_id' => "REV-{$sequence}",
        'transaction_hash' => hash('sha256', "rev-audit-{$sequence}"), 'posted_at' => '2026-07-10',
        'description' => 'Movimento reversal', 'amount_cents' => 30_000, 'direction' => $direction,
        'operation_type' => $direction === 'out' ? OfxOperationTypePolicy::PAYMENT : OfxOperationTypePolicy::INCOME,
        'status' => 'imported', 'resolution' => 'created',
    ]);

    return compact('entry', 'bankLine', 'counterpart', 'audit');
}

function bankReversalPayable(array $c): AccountPayable
{
    return AccountPayable::query()->create([
        'wallet_id' => $c['wallet']->id,
        'payable_account_id' => $c['wallet']->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->value('id'),
        'expense_account_id' => $c['expense']->id, 'payee_name' => 'Fornecedor', 'description' => 'AP bank reversal',
        'due_date' => '2026-07-12', 'amount_cents' => 30_000, 'status' => 'pending',
    ]);
}

function bankReversalReceivable(array $c): AccountReceivable
{
    return AccountReceivable::query()->create([
        'wallet_id' => $c['wallet']->id,
        'receivable_account_id' => $c['wallet']->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->value('id'),
        'revenue_account_id' => $c['revenue']->id, 'customer_name' => 'Cliente', 'description' => 'AR bank reversal',
        'due_date' => '2026-07-12', 'amount_cents' => 30_000, 'status' => 'pending',
    ]);
}

it('unlinks draft AP bank settlement while preserving the imported movement and supports another cycle', function () {
    $c = bankReversalContext();
    $movement = bankReversalMovement($c, 'out');
    $title = bankReversalPayable($c);
    app(LinkAccountPayableFromBankStatement::class)->execute($c['wallet'], $c['bank'], $movement['entry'], $title);
    $bankSnapshot = $movement['bankLine']->fresh()->toArray();

    $this->actingAs($c['user'])->withSession(['active_wallet' => $c['wallet']->id])
        ->post(route('accounts-payable.reverse-settlement', $title), ['reason' => 'Vínculo incorreto'])
        ->assertRedirect(route('accounts-payable.show', $title));

    expect($title->fresh()->status)->toBe('pending')->and($title->fresh()->payment_journal_entry_id)->toBeNull()
        ->and($movement['entry']->fresh()->status)->toBe('draft')->and($movement['bankLine']->fresh()->toArray())->toBe($bankSnapshot)
        ->and($movement['counterpart']->fresh()->chart_of_account_id)->toBe($c['wallet']->suspense_account_id)
        ->and($movement['audit']->fresh()->classification_account_id)->toBeNull()
        ->and(app(AssessJournalEntryPostingReadiness::class)->handle($c['wallet'], $movement['entry']->fresh())->ready)->toBeFalse()
        ->and(FinancialTitleSettlementReversal::query()->sole()->mode)->toBe('bank_draft_unlink');
    expect(app(FindBankStatementPayableCandidates::class)->execute($c['wallet'], $c['bank'], $movement['entry']->fresh())->pluck('id'))->toContain($title->id);
    $cashFlow = app(BuildCashFlow::class)->handle($c['wallet'], new CashFlowFiltersDTO(startDate: '2026-07-01', endDate: '2026-08-31'));
    expect($cashFlow['summary']['projected_outflows_cents'])->toBe(30_000)
        ->and($cashFlow['summary']['realized_outflows_cents'])->toBe(0);

    app(LinkAccountPayableFromBankStatement::class)->execute($c['wallet'], $c['bank'], $movement['entry']->fresh(), $title->fresh());
    app(ReverseAccountPayableSettlement::class)->execute($c['wallet'], $title, $c['user'], 'Segundo ciclo');
    app(ReverseAccountPayableSettlement::class)->execute($c['wallet'], $title, $c['user'], 'Idempotente');
    expect($title->settlementReversals()->count())->toBe(2);
});

it('unlinks draft AR bank settlement symmetrically', function () {
    $c = bankReversalContext();
    $movement = bankReversalMovement($c, 'in');
    $title = bankReversalReceivable($c);
    app(LinkAccountReceivableFromBankStatement::class)->execute($c['wallet'], $c['bank'], $movement['entry'], $title);
    $bankSnapshot = $movement['bankLine']->fresh()->toArray();

    app(ReverseAccountReceivableSettlement::class)->execute($c['wallet'], $title, $c['user'], 'Vínculo incorreto');

    expect($title->fresh()->status)->toBe('pending')->and($title->fresh()->receipt_journal_entry_id)->toBeNull()
        ->and($movement['entry']->fresh()->status)->toBe('draft')->and($movement['bankLine']->fresh()->toArray())->toBe($bankSnapshot)
        ->and($movement['counterpart']->fresh()->chart_of_account_id)->toBe($c['wallet']->suspense_account_id)
        ->and($movement['audit']->fresh()->classification_account_id)->toBeNull()
        ->and(FinancialTitleSettlementReversal::query()->sole()->mode)->toBe('bank_draft_unlink');
});

it('posts AP and AR classification adjustments without reversing bank effects', function () {
    foreach (['out', 'in'] as $direction) {
        $c = bankReversalContext();
        $movement = bankReversalMovement($c, $direction);
        $title = $direction === 'out' ? bankReversalPayable($c) : bankReversalReceivable($c);
        $service = $direction === 'out' ? ReverseAccountPayableSettlement::class : ReverseAccountReceivableSettlement::class;
        $link = $direction === 'out' ? LinkAccountPayableFromBankStatement::class : LinkAccountReceivableFromBankStatement::class;
        app($link)->execute($c['wallet'], $c['bank'], $movement['entry'], $title);
        $original = app(PostJournalEntry::class)->handle($movement['entry']);
        $snapshot = $original->fresh('lines')->toArray();
        $bankEffect = app(BankAccountBalanceService::class)->calculate($c['wallet'], $c['bank'])['accounting_balance_cents'];

        app($service)->execute($c['wallet'], $title, $c['user'], 'Reclassificar', '2026-08-05');

        $history = $title->settlementReversals()->with('classificationAdjustmentJournalEntry.lines')->sole();
        $adjustment = $history->classificationAdjustmentJournalEntry;
        expect($title->fresh()->status)->toBe('pending')->and($original->fresh('lines')->toArray())->toBe($snapshot)
            ->and($history->mode)->toBe('bank_posted_reclassification')->and($history->reversal_journal_entry_id)->toBeNull()
            ->and($adjustment->status)->toBe('posted')->and($adjustment->reversal_of_journal_entry_id)->toBeNull()
            ->and($adjustment->lines->contains('chart_of_account_id', $c['bank']->chart_of_account_id))->toBeFalse()
            ->and($adjustment->lines->where('chart_of_account_id', $c['wallet']->suspense_account_id)->first()->type)
            ->toBe($direction === 'out' ? 'debit' : 'credit')
            ->and($movement['audit']->fresh()->journal_entry_id)->toBe($original->id)
            ->and(app(BankAccountBalanceService::class)->calculate($c['wallet'], $c['bank'])['accounting_balance_cents'])->toBe($bankEffect);
        $cashFlow = app(BuildCashFlow::class)->handle($c['wallet'], new CashFlowFiltersDTO(startDate: '2026-07-01', endDate: '2026-08-31'));
        expect($cashFlow['summary'][$direction === 'out' ? 'projected_outflows_cents' : 'projected_inflows_cents'])->toBe(30_000)
            ->and($cashFlow['summary'][$direction === 'out' ? 'realized_outflows_cents' : 'realized_inflows_cents'])->toBe(30_000);
    }
});

it('rejects draft bank unlink in a closed period without mutations', function () {
    $c = bankReversalContext();
    $movement = bankReversalMovement($c, 'out');
    $title = bankReversalPayable($c);
    app(LinkAccountPayableFromBankStatement::class)->execute($c['wallet'], $c['bank'], $movement['entry'], $title);
    $counterpartAccount = $movement['counterpart']->fresh()->chart_of_account_id;
    MonthlyWalletClosing::query()->create([
        'wallet_id' => $c['wallet']->id, 'year' => 2026, 'month' => 7, 'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
        'status' => 'closed', 'closed_at' => now(), 'closed_by' => $c['user']->id,
    ]);

    expect(fn () => app(ReverseAccountPayableSettlement::class)->execute($c['wallet'], $title, $c['user'], 'Fechado'))
        ->toThrow(ValidationException::class);
    expect($title->fresh()->status)->toBe('paid')->and($movement['counterpart']->fresh()->chart_of_account_id)->toBe($counterpartAccount)
        ->and($movement['audit']->fresh()->classification_account_id)->toBe($title->payable_account_id)
        ->and(FinancialTitleSettlementReversal::query()->count())->toBe(0);
});

it('allows an open adjustment after a closed original and rejects a closed adjustment atomically', function () {
    $c = bankReversalContext();
    $movement = bankReversalMovement($c, 'out');
    $title = bankReversalPayable($c);
    app(LinkAccountPayableFromBankStatement::class)->execute($c['wallet'], $c['bank'], $movement['entry'], $title);
    app(PostJournalEntry::class)->handle($movement['entry']);
    $closing = MonthlyWalletClosing::query()->create([
        'wallet_id' => $c['wallet']->id, 'year' => 2026, 'month' => 7, 'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
        'status' => 'closed', 'closed_at' => now(), 'closed_by' => $c['user']->id,
    ]);
    app(ReverseAccountPayableSettlement::class)->execute($c['wallet'], $title, $c['user'], 'Período aberto', '2026-08-05');
    expect($closing->fresh()->status)->toBe('closed')->and($title->fresh()->status)->toBe('pending');
    $adjustmentCount = JournalEntry::query()->where('description', 'like', 'Reclassificação%')->count();

    $c = bankReversalContext();
    $movement = bankReversalMovement($c, 'out');
    $title = bankReversalPayable($c);
    app(LinkAccountPayableFromBankStatement::class)->execute($c['wallet'], $c['bank'], $movement['entry'], $title);
    app(PostJournalEntry::class)->handle($movement['entry']);
    MonthlyWalletClosing::query()->create([
        'wallet_id' => $c['wallet']->id, 'year' => 2026, 'month' => 8, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
        'status' => 'closed', 'closed_at' => now(), 'closed_by' => $c['user']->id,
    ]);
    expect(fn () => app(ReverseAccountPayableSettlement::class)->execute($c['wallet'], $title, $c['user'], 'Fechado', '2026-08-05'))
        ->toThrow(ValidationException::class);
    expect($title->fresh()->status)->toBe('paid')->and($title->settlementReversals()->count())->toBe(0)
        ->and(JournalEntry::query()->where('description', 'like', 'Reclassificação%')->count())->toBe($adjustmentCount);
});
