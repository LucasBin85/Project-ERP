<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\BankStatementImport;
use App\Models\BankStatementImportTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

function bankAccountSetupOfxContent(
    ?string $bankId = '001',
    ?string $routingNumber = '00000000',
    ?string $branchId = '0123',
    ?string $accountId = '456789',
    ?string $accountKey = '0',
    ?string $accountType = 'CHECKING',
    ?string $organization = 'BANCO DO BRASIL',
): string {
    $tag = static fn (string $name, ?string $value): string => $value === null
        ? ''
        : "<{$name}>{$value}\n";

    return <<<OFX
OFXHEADER:100
DATA:OFXSGML
VERSION:102
SECURITY:NONE
ENCODING:USASCII

<OFX>
<SIGNONMSGSRSV1>
<SONRS>
<FI>
{$tag('ORG', $organization)}</FI>
</SONRS>
</SIGNONMSGSRSV1>
<BANKMSGSRSV1>
<STMTTRNRS>
<STMTRS>
<CURDEF>BRL
<BANKACCTFROM>
{$tag('BANKID', $bankId)}{$tag('ROUTINGNUM', $routingNumber)}{$tag('BRANCHID', $branchId)}{$tag('ACCTID', $accountId)}{$tag('ACCTKEY', $accountKey)}{$tag('ACCTTYPE', $accountType)}</BANKACCTFROM>
</STMTRS>
</STMTTRNRS>
</BANKMSGSRSV1>
</OFX>
OFX;
}

function requestBankAccountSetupPreview(
    TestCase $test,
    User $user,
    Wallet $wallet,
    string $contents,
    string $filename = 'conta.ofx',
): TestResponse {
    return $test
        ->actingAs($user)
        ->withSession(['active_wallet' => $wallet->id])
        ->withHeader('Accept', 'application/json')
        ->post(route('bank-accounts.ofx-preview'), [
            'ofx_file' => UploadedFile::fake()->createWithContent($filename, $contents),
        ]);
}

it('previews account data from an OFX without transactions and creates no records', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $bank = Bank::query()->create([
        'code' => '001',
        'name' => 'Banco do Brasil S.A.',
        'short_name' => 'Banco do Brasil',
        'ispb' => '00000000',
        'active' => true,
    ]);
    $contents = bankAccountSetupOfxContent();

    expect($contents)->not->toContain('<STMTTRN>');

    $response = requestBankAccountSetupPreview($this, $user, $wallet, $contents);

    $response
        ->assertOk()
        ->assertJsonPath('file_name', 'conta.ofx')
        ->assertJsonPath('account.container', 'BANKACCTFROM')
        ->assertJsonPath('account.bank_code', '001')
        ->assertJsonPath('account.ispb', '00000000')
        ->assertJsonPath('account.agency', '0123')
        ->assertJsonPath('account.account_number', '456789')
        ->assertJsonPath('account.account_digit', '0')
        ->assertJsonPath('account.account_type', 'checking')
        ->assertJsonPath('account.raw_account_number', '456789')
        ->assertJsonPath('account.raw_account_type', 'CHECKING')
        ->assertJsonPath('matched_bank.id', $bank->id)
        ->assertJsonPath('suggested.bank_id', $bank->id)
        ->assertJsonPath('suggested.agency', '0123')
        ->assertJsonPath('suggested.account_number', '4567890')
        ->assertJsonPath('suggested.account_type', 'checking')
        ->assertJsonPath('warnings', [])
        ->assertJsonMissingPath('transactions')
        ->assertJsonMissingPath('rows');

    expect($response->json('suggested.name'))
        ->toBeString()
        ->toContain('Banco do Brasil')
        ->toContain('456789');

    expect(BankAccount::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(JournalLine::query()->count())->toBe(0)
        ->and(BankStatementImport::query()->count())->toBe(0)
        ->and(BankStatementImportTransaction::query()->count())->toBe(0);
});

it('extracts the check digit from ACCTID when ACCTKEY is absent', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();

    $response = requestBankAccountSetupPreview(
        $this,
        $user,
        $wallet,
        bankAccountSetupOfxContent(accountId: '98765-4', accountKey: null),
    );

    $response
        ->assertOk()
        ->assertJsonPath('account.account_number', '98765')
        ->assertJsonPath('account.account_digit', '4')
        ->assertJsonPath('account.raw_account_number', '98765-4')
        ->assertJsonPath('suggested.account_number', '987654');
});

it('identifies an active catalog bank by ISPB when BANKID is absent', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $bank = Bank::query()->create([
        'code' => '260',
        'name' => 'Nu Pagamentos S.A.',
        'short_name' => 'Nubank',
        'ispb' => '18236120',
        'active' => true,
    ]);

    $response = requestBankAccountSetupPreview(
        $this,
        $user,
        $wallet,
        bankAccountSetupOfxContent(
            bankId: null,
            routingNumber: '18236120',
            organization: 'NU PAGAMENTOS',
        ),
    );

    $response
        ->assertOk()
        ->assertJsonPath('account.bank_code', null)
        ->assertJsonPath('account.ispb', '18236120')
        ->assertJsonPath('matched_bank.id', $bank->id)
        ->assertJsonPath('suggested.bank_id', $bank->id);
});

it('does not select a bank when OFX code and ISPB identify different catalog banks', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    Bank::query()->create([
        'code' => '001',
        'name' => 'Banco do Brasil S.A.',
        'short_name' => 'Banco do Brasil',
        'ispb' => '00000000',
        'active' => true,
    ]);
    Bank::query()->create([
        'code' => '260',
        'name' => 'Nu Pagamentos S.A.',
        'short_name' => 'Nubank',
        'ispb' => '18236120',
        'active' => true,
    ]);

    $response = requestBankAccountSetupPreview(
        $this,
        $user,
        $wallet,
        bankAccountSetupOfxContent(bankId: '001', routingNumber: '18236120'),
    );

    $response
        ->assertOk()
        ->assertJsonPath('account.bank_code', '001')
        ->assertJsonPath('account.ispb', '18236120')
        ->assertJsonPath('matched_bank', null)
        ->assertJsonPath('suggested.bank_id', null);

    expect(implode(' ', $response->json('warnings')))
        ->toContain('instituições diferentes')
        ->toContain('manualmente');

    expect(BankAccount::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('warns when the OFX bank is not in the catalog without creating it', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();

    $response = requestBankAccountSetupPreview(
        $this,
        $user,
        $wallet,
        bankAccountSetupOfxContent(
            bankId: '999',
            routingNumber: '12345678',
            organization: 'BANCO FORA DO CATALOGO',
        ),
    );

    $response
        ->assertOk()
        ->assertJsonPath('account.bank_code', '999')
        ->assertJsonPath('account.ispb', '12345678')
        ->assertJsonPath('matched_bank', null)
        ->assertJsonPath('suggested.bank_id', null);

    expect(implode(' ', $response->json('warnings')))
        ->toContain('catálogo local')
        ->toContain('manualmente');

    expect(Bank::query()->count())->toBe(0)
        ->and(BankAccount::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('returns a usable preview with warnings when account metadata is incomplete', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();

    $response = requestBankAccountSetupPreview(
        $this,
        $user,
        $wallet,
        bankAccountSetupOfxContent(
            bankId: null,
            routingNumber: null,
            branchId: null,
            accountId: null,
            accountKey: null,
            accountType: null,
            organization: null,
        ),
    );

    $response
        ->assertOk()
        ->assertJsonPath('account.bank_code', null)
        ->assertJsonPath('account.ispb', null)
        ->assertJsonPath('account.agency', null)
        ->assertJsonPath('account.account_number', null)
        ->assertJsonPath('account.account_digit', null)
        ->assertJsonPath('account.account_type', null)
        ->assertJsonPath('matched_bank', null)
        ->assertJsonPath('suggested.bank_id', null)
        ->assertJsonPath('suggested.agency', null)
        ->assertJsonPath('suggested.account_number', null)
        ->assertJsonPath('suggested.account_type', null);

    expect($response->json('message'))->toBeString()->not->toBeEmpty()
        ->and($response->json('warnings'))->toBeArray()->not->toBeEmpty()
        ->and(BankAccount::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('returns a friendly validation error for invalid OFX contents', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();

    $response = requestBankAccountSetupPreview(
        $this,
        $user,
        $wallet,
        'este conteúdo não é um arquivo OFX',
        'invalido.ofx',
    );

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ofx_file');

    expect($response->json('errors.ofx_file.0'))
        ->toBeString()
        ->toContain('estrutura OFX válida');

    expect(BankAccount::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('rejects files without an OFX extension', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();

    requestBankAccountSetupPreview(
        $this,
        $user,
        $wallet,
        bankAccountSetupOfxContent(),
        'conta.txt',
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ofx_file');
});

it('requires authentication', function () {
    $this
        ->withHeader('Accept', 'application/json')
        ->post(route('bank-accounts.ofx-preview'), [
            'ofx_file' => UploadedFile::fake()->createWithContent(
                'conta.ofx',
                bankAccountSetupOfxContent(),
            ),
        ])
        ->assertUnauthorized();
});

it('does not accept an active wallet belonging to another user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherWallet = $otherUser->wallets()->firstOrFail();

    $this
        ->actingAs($user)
        ->withSession(['active_wallet' => $otherWallet->id])
        ->withHeader('Accept', 'application/json')
        ->post(route('bank-accounts.ofx-preview'), [
            'ofx_file' => UploadedFile::fake()->createWithContent(
                'conta.ofx',
                bankAccountSetupOfxContent(),
            ),
        ])
        ->assertNotFound();

    expect(BankAccount::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);
});
