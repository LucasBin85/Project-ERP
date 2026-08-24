<?php

use App\DTOs\Financial\BankReconciliationDTO;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationItem;
use App\Models\BankReconciliationStatementItem;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\CreateBankReconciliation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

it('keeps financial navigation routes aligned with the contextual bank statement flow', function () {
    expect(\Illuminate\Support\Facades\Route::has('bank-statements.index'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Route::has('bank-reconciliations.create'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Route::has('bank-reconciliations.store'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Route::has('bank-accounts.statement'))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Route::has('ofx-imports.index'))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Route::has('bank-reconciliations.index'))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Route::has('bank-reconciliations.show'))->toBeTrue();
});

it('creates a completed reconciliation when statement items match system movements', function () {
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

    $capital = AccountingTestHelper::account($wallet, '3.1', 'Capital Social', 'patrimonio', 'credit');
    $revenue = AccountingTestHelper::account($wallet, '4.1', 'Receita de Serviços', 'receita', 'credit');
    $expense = AccountingTestHelper::account($wallet, '5.1', 'Despesa Administrativa', 'despesa', 'debit');

    AccountingTestHelper::createPostedEntry($wallet, '2026-06-30', [
        [$bankAccount->chartOfAccount, 'debit', 100000],
        [$capital, 'credit', 100000],
    ]);

    AccountingTestHelper::createPostedEntry($wallet, '2026-07-01', [
        [$bankAccount->chartOfAccount, 'debit', 50000],
        [$revenue, 'credit', 50000],
    ]);

    AccountingTestHelper::createPostedEntry($wallet, '2026-07-02', [
        [$expense, 'debit', 12000],
        [$bankAccount->chartOfAccount, 'credit', 12000],
    ]);

    $bankLines = JournalLine::query()
        ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
        ->whereHas('journalEntry', fn ($query) => $query
            ->whereDate('entry_date', '>=', '2026-07-01')
            ->whereDate('entry_date', '<=', '2026-07-31'))
        ->orderBy('id')
        ->get();

    $reconciliation = app(CreateBankReconciliation::class)->execute(
        $wallet,
        new BankReconciliationDTO(
            bankAccountId: $bankAccount->id,
            periodStart: '2026-07-01',
            periodEnd: '2026-07-31',
            statementBalanceCents: 138000,
            statementItems: [
                [
                    'transaction_date' => '2026-07-01',
                    'description' => 'PIX recebido',
                    'amount_cents' => 50000,
                    'journal_line_id' => $bankLines[0]->id,
                ],
                [
                    'transaction_date' => '2026-07-02',
                    'description' => 'Pagamento despesa',
                    'amount_cents' => -12000,
                    'journal_line_id' => $bankLines[1]->id,
                ],
            ],
        ),
    );

    expect($reconciliation->status)->toBe('completed')
        ->and($reconciliation->opening_balance_cents)->toBe(100000)
        ->and($reconciliation->book_balance_cents)->toBe(138000)
        ->and($reconciliation->reconciled_balance_cents)->toBe(138000)
        ->and($reconciliation->statement_balance_cents)->toBe(138000)
        ->and($reconciliation->difference_cents)->toBe(0)
        ->and($reconciliation->items)->toHaveCount(2)
        ->and($reconciliation->statementItems)->toHaveCount(2)
        ->and($reconciliation->statementItems->pluck('status')->unique()->values()->all())->toBe(['reconciled']);
});

it('keeps reconciliation as draft when there is a difference', function () {
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

    $capital = AccountingTestHelper::account($wallet, '3.1', 'Capital Social', 'patrimonio', 'credit');
    $revenue = AccountingTestHelper::account($wallet, '4.1', 'Receita de Serviços', 'receita', 'credit');

    AccountingTestHelper::createPostedEntry($wallet, '2026-06-30', [
        [$bankAccount->chartOfAccount, 'debit', 100000],
        [$capital, 'credit', 100000],
    ]);

    AccountingTestHelper::createPostedEntry($wallet, '2026-07-01', [
        [$bankAccount->chartOfAccount, 'debit', 50000],
        [$revenue, 'credit', 50000],
    ]);

    $bankLine = JournalLine::query()
        ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
        ->whereHas('journalEntry', fn ($query) => $query
            ->whereDate('entry_date', '>=', '2026-07-01')
            ->whereDate('entry_date', '<=', '2026-07-31'))
        ->firstOrFail();

    $reconciliation = app(CreateBankReconciliation::class)->execute(
        $wallet,
        new BankReconciliationDTO(
            bankAccountId: $bankAccount->id,
            periodStart: '2026-07-01',
            periodEnd: '2026-07-31',
            statementBalanceCents: 140000,
            statementItems: [
                [
                    'transaction_date' => '2026-07-01',
                    'description' => 'PIX recebido parcial no banco',
                    'amount_cents' => 40000,
                    'journal_line_id' => $bankLine->id,
                ],
            ],
        ),
    );

    expect($reconciliation->status)->toBe('draft')
        ->and($reconciliation->opening_balance_cents)->toBe(100000)
        ->and($reconciliation->reconciled_balance_cents)->toBe(150000)
        ->and($reconciliation->statement_balance_cents)->toBe(140000)
        ->and($reconciliation->difference_cents)->toBe(10000)
        ->and($reconciliation->statementItems)->toHaveCount(1)
        ->and($reconciliation->statementItems->first()->status)->toBe('reconciled');
});

it('keeps reconciliation as draft when a statement item is pending', function () {
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

    $capital = AccountingTestHelper::account($wallet, '3.1', 'Capital Social', 'patrimonio', 'credit');

    AccountingTestHelper::createPostedEntry($wallet, '2026-06-30', [
        [$bankAccount->chartOfAccount, 'debit', 100000],
        [$capital, 'credit', 100000],
    ]);

    $reconciliation = app(CreateBankReconciliation::class)->execute(
        $wallet,
        new BankReconciliationDTO(
            bankAccountId: $bankAccount->id,
            periodStart: '2026-07-01',
            periodEnd: '2026-07-31',
            statementBalanceCents: 100000,
            statementItems: [
                [
                    'transaction_date' => '2026-07-03',
                    'description' => 'Tarifa não lançada no ERP',
                    'amount_cents' => -1500,
                    'journal_line_id' => null,
                ],
            ],
        ),
    );

    expect($reconciliation->status)->toBe('draft')
        ->and($reconciliation->statementItems)->toHaveCount(1)
        ->and($reconciliation->statementItems->first()->status)->toBe('pending');
});

it('isolates the wallet and bank account at the domain boundary', function () {
    $user = User::factory()->create();
    $wallet = Wallet::query()->create(['user_id' => $user->id, 'name' => 'Carteira A']);
    $otherWallet = Wallet::query()->create(['user_id' => $user->id, 'name' => 'Carteira B']);
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.101', 'Banco A');
    $otherAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.102', 'Banco A secundário');
    $foreignAccount = FinancialTestHelper::bankAccount($otherWallet, '1.1.2.103', 'Banco B');
    $counterpart = AccountingTestHelper::account($wallet, '3.101', 'Contrapartida', 'patrimonio', 'credit');

    $otherAccountEntry = AccountingTestHelper::createPostedEntry($wallet, '2026-07-10', [
        [$otherAccount->chartOfAccount, 'debit', 2500],
        [$counterpart, 'credit', 2500],
    ]);
    $otherAccountLine = $otherAccountEntry->lines->firstWhere('chart_of_account_id', $otherAccount->chart_of_account_id);

    expect(fn () => app(CreateBankReconciliation::class)->execute(
        $wallet,
        new BankReconciliationDTO($foreignAccount->id, '2026-07-01', '2026-07-31', 0),
    ))->toThrow(ModelNotFoundException::class);

    expect(fn () => app(CreateBankReconciliation::class)->execute(
        $wallet,
        new BankReconciliationDTO($bankAccount->id, '2026-07-01', '2026-07-31', 2500, [[
            'transaction_date' => '2026-07-10',
            'description' => 'Movimento de outra conta',
            'amount_cents' => 2500,
            'journal_line_id' => $otherAccountLine->id,
        ]]),
    ))->toThrow(ValidationException::class);

    expect(BankReconciliation::query()->count())->toBe(0);
});

it('uses inclusive period boundaries and posted journal entries only', function () {
    $user = User::factory()->create();
    $wallet = Wallet::query()->create(['user_id' => $user->id, 'name' => 'Carteira período']);
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.201', 'Banco período');
    $counterpart = AccountingTestHelper::account($wallet, '3.201', 'Contrapartida', 'patrimonio', 'credit');

    AccountingTestHelper::createPostedEntry($wallet, '2026-06-30', [
        [$bankAccount->chartOfAccount, 'debit', 10000],
        [$counterpart, 'credit', 10000],
    ]);
    $first = AccountingTestHelper::createPostedEntry($wallet, '2026-07-01', [
        [$bankAccount->chartOfAccount, 'debit', 2000],
        [$counterpart, 'credit', 2000],
    ]);
    $last = AccountingTestHelper::createPostedEntry($wallet, '2026-07-31', [
        [$counterpart, 'debit', 500],
        [$bankAccount->chartOfAccount, 'credit', 500],
    ]);
    AccountingTestHelper::createPostedEntry($wallet, '2026-08-01', [
        [$bankAccount->chartOfAccount, 'debit', 9000],
        [$counterpart, 'credit', 9000],
    ]);
    AccountingTestHelper::createDraftEntry($wallet, '2026-07-15', [
        [$bankAccount->chartOfAccount, 'debit', 7000],
        [$counterpart, 'credit', 7000],
    ]);

    $firstLine = $first->lines->firstWhere('chart_of_account_id', $bankAccount->chart_of_account_id);
    $lastLine = $last->lines->firstWhere('chart_of_account_id', $bankAccount->chart_of_account_id);
    $reconciliation = app(CreateBankReconciliation::class)->execute(
        $wallet,
        new BankReconciliationDTO($bankAccount->id, '2026-07-01', '2026-07-31', 11500, [
            ['transaction_date' => '2026-07-01', 'description' => 'Borda inicial', 'amount_cents' => 2000, 'journal_line_id' => $firstLine->id],
            ['transaction_date' => '2026-07-31', 'description' => 'Borda final', 'amount_cents' => -500, 'journal_line_id' => $lastLine->id],
        ]),
    );

    expect($reconciliation->opening_balance_cents)->toBe(10000)
        ->and($reconciliation->book_balance_cents)->toBe(11500)
        ->and($reconciliation->items->pluck('journal_line_id')->all())->toBe([$firstLine->id, $lastLine->id])
        ->and($reconciliation->status)->toBe('completed');
});

it('rejects invalid periods and statement items outside the inclusive period', function () {
    $user = User::factory()->create();
    $wallet = Wallet::query()->create(['user_id' => $user->id, 'name' => 'Carteira validação']);
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.301', 'Banco validação');

    expect(fn () => app(CreateBankReconciliation::class)->execute(
        $wallet,
        new BankReconciliationDTO($bankAccount->id, '2026-07-31', '2026-07-01', 0),
    ))->toThrow(ValidationException::class);

    expect(fn () => app(CreateBankReconciliation::class)->execute(
        $wallet,
        new BankReconciliationDTO($bankAccount->id, '2026-07-01', '2026-07-31', 0, [[
            'transaction_date' => '2026-08-01',
            'description' => 'Fora do período',
            'amount_cents' => 100,
            'journal_line_id' => null,
        ]]),
    ))->toThrow(ValidationException::class);

    expect(BankReconciliation::query()->count())->toBe(0);
});

it('preserves persisted snapshots and keeps reconciliation GET routes read only', function () {
    $user = User::factory()->create();
    $wallet = Wallet::query()->create(['user_id' => $user->id, 'name' => 'Carteira snapshot']);
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.401', 'Banco snapshot');
    $counterpart = AccountingTestHelper::account($wallet, '3.401', 'Contrapartida', 'patrimonio', 'credit');
    $entry = AccountingTestHelper::createPostedEntry($wallet, '2026-07-10', [
        [$bankAccount->chartOfAccount, 'debit', 3000],
        [$counterpart, 'credit', 3000],
    ]);
    $line = $entry->lines->firstWhere('chart_of_account_id', $bankAccount->chart_of_account_id);
    $reconciliation = app(CreateBankReconciliation::class)->execute(
        $wallet,
        new BankReconciliationDTO($bankAccount->id, '2026-07-01', '2026-07-31', 3000, [[
            'transaction_date' => '2026-07-10', 'description' => 'Snapshot', 'amount_cents' => 3000, 'journal_line_id' => $line->id,
        ]]),
    );
    $line->update(['amount_cents' => 9999]);
    $before = [BankReconciliation::query()->count(), BankReconciliationItem::query()->count(), BankReconciliationStatementItem::query()->count()];

    $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])
        ->get(route('bank-reconciliations.index'))->assertOk();
    $this->get(route('bank-reconciliations.show', $reconciliation))->assertOk();

    expect($reconciliation->fresh()->book_balance_cents)->toBe(3000)
        ->and($reconciliation->fresh()->items->first()->amount_cents)->toBe(3000)
        ->and([BankReconciliation::query()->count(), BankReconciliationItem::query()->count(), BankReconciliationStatementItem::query()->count()])->toBe($before);
});

it('rolls back the reconciliation and all items when item persistence fails', function () {
    $user = User::factory()->create();
    $wallet = Wallet::query()->create(['user_id' => $user->id, 'name' => 'Carteira rollback']);
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.501', 'Banco rollback');
    $listener = function () {
        throw new RuntimeException('Falha simulada ao persistir item.');
    };
    Event::listen('eloquent.creating: '.BankReconciliationStatementItem::class, $listener);

    try {
        expect(fn () => app(CreateBankReconciliation::class)->execute(
            $wallet,
            new BankReconciliationDTO($bankAccount->id, '2026-07-01', '2026-07-31', 100, [[
                'transaction_date' => '2026-07-10', 'description' => 'Falha', 'amount_cents' => 100, 'journal_line_id' => null,
            ]]),
        ))->toThrow(RuntimeException::class);
    } finally {
        Event::forget('eloquent.creating: '.BankReconciliationStatementItem::class);
    }

    expect(BankReconciliation::query()->count())->toBe(0)
        ->and(BankReconciliationStatementItem::query()->count())->toBe(0)
        ->and(BankReconciliationItem::query()->count())->toBe(0);
});

it('preserves the existing duplicate policy', function () {
    $user = User::factory()->create();
    $wallet = Wallet::query()->create(['user_id' => $user->id, 'name' => 'Carteira duplicidade']);
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.601', 'Banco duplicidade');
    $counterpart = AccountingTestHelper::account($wallet, '3.601', 'Contrapartida', 'patrimonio', 'credit');
    $entry = AccountingTestHelper::createPostedEntry($wallet, '2026-07-10', [
        [$bankAccount->chartOfAccount, 'debit', 1000],
        [$counterpart, 'credit', 1000],
    ]);
    $line = $entry->lines->firstWhere('chart_of_account_id', $bankAccount->chart_of_account_id);
    $item = ['transaction_date' => '2026-07-10', 'description' => 'Mesmo lançamento', 'amount_cents' => 1000, 'journal_line_id' => $line->id];

    expect(fn () => app(CreateBankReconciliation::class)->execute(
        $wallet,
        new BankReconciliationDTO($bankAccount->id, '2026-07-01', '2026-07-31', 2000, [$item, $item]),
    ))->toThrow(ValidationException::class);

    app(CreateBankReconciliation::class)->execute($wallet, new BankReconciliationDTO($bankAccount->id, '2026-07-01', '2026-07-31', 1000, [$item]));
    app(CreateBankReconciliation::class)->execute($wallet, new BankReconciliationDTO($bankAccount->id, '2026-07-01', '2026-07-31', 1000, [$item]));

    expect(BankReconciliation::query()->count())->toBe(2);
});
