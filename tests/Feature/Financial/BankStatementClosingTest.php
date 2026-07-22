<?php

use App\DTOs\Financial\OfxClassificationDTO;
use App\Models\BankStatementImport;
use App\Models\BankStatementImportTransaction;
use App\Models\User;
use App\Services\Accounting\CreateBankImportEntry;
use App\Services\Accounting\PostJournalEntry;
use App\Services\Financial\BuildBankStatementClosingSummary;
use App\Services\Financial\ClassifyOfxDraftEntry;
use App\Services\Financial\OfxOperationTypePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

function closingContext(): array
{
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $bank = FinancialTestHelper::bankAccount($wallet, '1.1.2.951', 'Banco fechamento');
    $other = FinancialTestHelper::bankAccount($wallet, '1.1.2.952', 'Banco contraparte');
    $expense = AccountingTestHelper::account($wallet, '5.9.51', 'Despesa fechamento', 'despesa', 'debit');
    $revenue = AccountingTestHelper::account($wallet, '4.9.51', 'Receita fechamento', 'receita', 'credit');
    $import = BankStatementImport::query()->create(['wallet_id' => $wallet->id, 'bank_account_id' => $bank->id, 'source' => 'ofx', 'original_filename' => 'closing.ofx', 'file_hash' => hash('sha256', 'closing'.$wallet->id), 'status' => 'completed']);

    return compact('user', 'wallet', 'bank', 'other', 'expense', 'revenue', 'import');
}

function closingImported(array $context, string $direction = 'out', ?string $operation = null, int $amount = 10000): array
{
    static $sequence = 0;
    $sequence++;
    $entry = app(CreateBankImportEntry::class)->handle($context['wallet'], $context['bank']->chart_of_account_id, $amount, $direction, '2026-07-10', 'Movimento fechamento '.$sequence, 'ofx', autoPostIfBalanced: false);
    $line = $entry->lines->firstWhere('chart_of_account_id', $context['bank']->chart_of_account_id);
    $audit = BankStatementImportTransaction::query()->create(['bank_statement_import_id' => $context['import']->id, 'wallet_id' => $context['wallet']->id, 'bank_account_id' => $context['bank']->id, 'journal_entry_id' => $entry->id, 'journal_line_id' => $line->id, 'external_id' => 'closing:'.$sequence, 'transaction_hash' => hash('sha256', 'closing'.$sequence), 'posted_at' => '2026-07-10', 'description' => $entry->description, 'amount_cents' => $amount, 'direction' => $direction, 'operation_type' => $operation, 'status' => 'imported', 'resolution' => 'created']);

    return compact('entry', 'audit');
}

function closingSummary(array $context): array
{
    return app(BuildBankStatementClosingSummary::class)->execute($context['wallet'], $context['bank'], '2026-07-01', '2026-07-31');
}

it('marks unclassified and AP-link periods as incomplete', function () {
    $context = closingContext();
    closingImported($context);
    $summary = closingSummary($context);
    expect($summary['status'])->toBe('incomplete')->and($summary['counts']['pending_classification'])->toBe(1);
    $context = closingContext();
    closingImported($context, 'out', OfxOperationTypePolicy::PAYMENT);
    $summary = closingSummary($context);
    expect($summary['status'])->toBe('incomplete')->and($summary['counts']['pending_links'])->toBe(1);
});

it('marks a transfer awaiting its counterpart as incomplete', function () {
    $context = closingContext();
    $movement = closingImported($context, 'out', OfxOperationTypePolicy::TRANSFER);
    app(ClassifyOfxDraftEntry::class)->execute($context['wallet'], $context['bank'], $movement['entry'], new OfxClassificationDTO('transfer', $context['other']->chart_of_account_id, false));
    $summary = closingSummary($context);
    expect($summary['status'])->toBe('incomplete')->and($summary['counts']['pending_transfers'])->toBe(1);
});

it('derives ready partially posted and closed period statuses', function () {
    $context = closingContext();
    $first = AccountingTestHelper::createDraftEntry($context['wallet'], '2026-07-10', [[$context['expense'], 'debit', 1000], [$context['bank']->chartOfAccount, 'credit', 1000]], 'manual');
    expect(closingSummary($context)['status'])->toBe('ready_for_accounting');
    app(PostJournalEntry::class)->handle($first);
    AccountingTestHelper::createDraftEntry($context['wallet'], '2026-07-11', [[$context['expense'], 'debit', 2000], [$context['bank']->chartOfAccount, 'credit', 2000]], 'manual');
    expect(closingSummary($context)['status'])->toBe('partially_posted');
    app(PostJournalEntry::class)->handle(\App\Models\JournalEntry::query()->where('status', 'draft')->firstOrFail());
    expect(closingSummary($context)['status'])->toBe('closed');
});

it('keeps operational balance stable while accounting balance includes only posted entries', function () {
    $context = closingContext();
    $posted = AccountingTestHelper::createDraftEntry($context['wallet'], '2026-07-10', [[$context['bank']->chartOfAccount, 'debit', 5000], [$context['revenue'], 'credit', 5000]], 'manual');
    AccountingTestHelper::createDraftEntry($context['wallet'], '2026-07-11', [[$context['expense'], 'debit', 2000], [$context['bank']->chartOfAccount, 'credit', 2000]], 'manual');
    $before = closingSummary($context);
    expect($before['balances']['closing_operational_cents'])->toBe(3000)->and($before['balances']['posted_accounting_cents'])->toBe(0);
    app(PostJournalEntry::class)->handle($posted);
    $after = closingSummary($context);
    expect($after['balances']['closing_operational_cents'])->toBe(3000)->and($after['balances']['posted_accounting_cents'])->toBe(5000)->and($after['balances']['difference_cents'])->toBe(-2000);
});

it('isolates the active wallet and renders closing summary actions', function () {
    $context = closingContext();
    AccountingTestHelper::createDraftEntry($context['wallet'], '2026-07-10', [[$context['expense'], 'debit', 1000], [$context['bank']->chartOfAccount, 'credit', 1000]], 'manual');
    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])->get(route('bank-accounts.closing.show', ['bankAccount' => $context['bank']->id, 'start_date' => '2026-07-01', 'end_date' => '2026-07-31']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Financial/BankStatementClosings/Show')->where('summary.status', 'ready_for_accounting')->where('summary.counts.ready_for_accounting', 1)->where('summary.balances.closing_operational_cents',-1000));
    $foreign = closingContext();
    $this->get(route('bank-accounts.closing.show',$foreign['bank']))->assertNotFound();
    expect(file_get_contents(resource_path('js/pages/Financial/BankStatements/Index.vue')))->toContain('Conferir período');
});
