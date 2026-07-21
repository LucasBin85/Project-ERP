<?php

use App\Exceptions\OfxImportException;
use App\Models\Bank;
use App\Models\BankStatementImport;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\ConfirmOfxBankStatement;
use App\Services\Financial\ParseOfxStatement;
use App\Services\Financial\PreviewOfxBankStatement;
use App\Services\Financial\ValidateOfxBankAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

/** @return array{0: Wallet, 1: \App\Models\BankAccount} */
function createOfxAccountValidationContext(array $bankAttributes = []): array
{
    $user = User::factory()->create();
    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira de validação OFX',
    ]);
    $suspense = ChartOfAccount::query()->updateOrCreate(
        [
            'wallet_id' => $wallet->id,
            'code' => '1.1.99',
        ],
        [
            'name' => 'A classificar',
            'type' => 'ativo',
            'normal_balance' => 'debit',
            'allows_posting' => true,
        ],
    );
    $wallet->update(['suspense_account_id' => $suspense->id]);

    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.001',
        name: 'Conta principal',
        attributes: $bankAttributes,
    );

    return [$wallet->fresh(), $bankAccount];
}

function ofxWithAccountMetadata(
    ?string $bankId = '001',
    ?string $branchId = '1234',
    ?string $accountId = '98765-4',
    ?string $accountType = 'CHECKING',
): string {
    $tag = fn (string $name, ?string $value): string => $value === null ? '' : "<{$name}>{$value}\n";

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
<ORG>BANCO-TESTE
<FID>0001
</FI>
</SONRS>
</SIGNONMSGSRSV1>
<BANKMSGSRSV1>
<STMTTRNRS>
<STMTRS>
<CURDEF>BRL
<BANKACCTFROM>
{$tag('BANKID', $bankId)}{$tag('BRANCHID', $branchId)}{$tag('ACCTID', $accountId)}{$tag('ACCTTYPE', $accountType)}</BANKACCTFROM>
<BANKTRANLIST>
<DTSTART>20260701000000[-3:BRT]
<DTEND>20260731235959[-3:BRT]
<STMTTRN>
<TRNTYPE>DEBIT
<DTPOSTED>20260710120000[-3:BRT]
<TRNAMT>-25.90
<FITID>ACCOUNT-VALIDATION-001
<NAME>Movimento de teste
</STMTTRN>
</BANKTRANLIST>
</STMTRS>
</STMTTRNRS>
</BANKMSGSRSV1>
</OFX>
OFX;
}

it('extracts bank account identifiers from the OFX statement section', function () {
    $parsed = app(ParseOfxStatement::class)->parse(ofxWithAccountMetadata());

    expect($parsed['account'])->toMatchArray([
        'container' => 'BANKACCTFROM',
        'bank_id' => '001',
        'branch_id' => '1234',
        'account_id' => '98765-4',
        'account_type' => 'CHECKING',
        'organization' => 'BANCO-TESTE',
        'financial_institution_id' => '0001',
        'currency' => 'BRL',
    ]);
});

it('validates the same bank account after normalizing zeros and an omitted check digit', function () {
    [$wallet, $bankAccount] = createOfxAccountValidationContext([
        'bank_code' => '0001',
        'agency' => '01234-5',
        'account_number' => '00098765-4',
        'account_type' => 'checking',
    ]);

    $preview = app(PreviewOfxBankStatement::class)->execute(
        wallet: $wallet,
        bankAccount: $bankAccount,
        contents: ofxWithAccountMetadata(),
        originalFilename: 'mesma-conta.ofx',
    );
    $validation = $preview['account_validation'];

    expect($validation['status'])->toBe(ValidateOfxBankAccount::STATUS_VALIDATED)
        ->and($validation['blocking'])->toBeFalse()
        ->and($validation['matched_fields'])->toContain('bank_code', 'agency', 'account_number', 'account_type')
        ->and($validation['divergent_fields'])->toBe([])
        ->and($validation['current_account']['account_number'])->toBe('00098765-4')
        ->and($validation['ofx_account']['account_id'])->toBe('98765-4')
        ->and(array_key_exists('operation_types', $preview))->toBeFalse();
});

it('recognizes the selected bank by ISPB when the OFX uses it as the bank identifier', function () {
    [$wallet, $bankAccount] = createOfxAccountValidationContext([
        'bank_code' => '001',
        'agency' => '1234',
        'account_number' => '98765-4',
        'account_type' => 'checking',
    ]);
    $bank = Bank::query()->create([
        'code' => '001',
        'name' => 'Banco do Brasil S.A.',
        'short_name' => 'Banco do Brasil',
        'ispb' => '00000000',
        'active' => true,
    ]);
    $bankAccount->update(['bank_id' => $bank->id]);

    $preview = app(PreviewOfxBankStatement::class)->execute(
        wallet: $wallet,
        bankAccount: $bankAccount->fresh(),
        contents: ofxWithAccountMetadata(bankId: '00000000'),
        originalFilename: 'mesmo-banco-por-ispb.ofx',
    );

    expect($preview['account_validation']['status'])->toBe(ValidateOfxBankAccount::STATUS_VALIDATED)
        ->and($preview['account_validation']['blocking'])->toBeFalse()
        ->and($preview['account_validation']['current_account']['ispb'])->toBe('00000000')
        ->and($preview['account_validation']['matched_fields'])->toContain('bank_code');
});

it('resolves ISPB from the catalog for a legacy account without bank_id', function () {
    [$wallet, $bankAccount] = createOfxAccountValidationContext([
        'bank_code' => '1',
        'agency' => '1234',
        'account_number' => '98765-4',
        'account_type' => 'checking',
    ]);
    Bank::query()->create([
        'code' => '001',
        'name' => 'Banco do Brasil S.A.',
        'short_name' => 'Banco do Brasil',
        'ispb' => '00000000',
        'active' => true,
    ]);

    $preview = app(PreviewOfxBankStatement::class)->execute(
        wallet: $wallet,
        bankAccount: $bankAccount,
        contents: ofxWithAccountMetadata(bankId: '00000000'),
        originalFilename: 'legado-mesmo-banco-por-ispb.ofx',
    );

    expect($preview['account_validation']['status'])->toBe(ValidateOfxBankAccount::STATUS_VALIDATED)
        ->and($preview['account_validation']['blocking'])->toBeFalse()
        ->and($preview['account_validation']['current_account']['ispb'])->toBe('00000000');
});

it('accepts a numeric check digit omitted by the OFX exporter with a warning', function () {
    [$wallet, $bankAccount] = createOfxAccountValidationContext([
        'bank_code' => '001',
        'agency' => '12345',
        'account_number' => '987654',
        'account_type' => 'checking',
    ]);

    $preview = app(PreviewOfxBankStatement::class)->execute(
        wallet: $wallet,
        bankAccount: $bankAccount,
        contents: ofxWithAccountMetadata(branchId: '1234', accountId: '98765'),
        originalFilename: 'digito-omitido.ofx',
    );

    expect($preview['account_validation']['status'])->toBe(ValidateOfxBankAccount::STATUS_VALIDATED)
        ->and($preview['account_validation']['blocking'])->toBeFalse()
        ->and($preview['account_validation']['warnings'])->toContain(
            'O número da conta foi validado considerando um dígito verificador omitido no arquivo ou no cadastro.',
        );
});

it('blocks OFX containers that represent credit card or investment accounts', function (string $container) {
    [$wallet, $bankAccount] = createOfxAccountValidationContext([
        'bank_code' => '001',
        'agency' => '1234',
        'account_number' => '98765-4',
        'account_type' => 'checking',
    ]);
    $contents = str_replace('BANKACCTFROM', $container, ofxWithAccountMetadata());

    $preview = app(PreviewOfxBankStatement::class)->execute(
        wallet: $wallet,
        bankAccount: $bankAccount,
        contents: $contents,
        originalFilename: 'conta-nao-bancaria.ofx',
    );

    expect($preview['account_validation']['status'])->toBe(ValidateOfxBankAccount::STATUS_MISMATCHED)
        ->and($preview['account_validation']['blocking'])->toBeTrue()
        ->and($preview['account_validation']['divergent_fields'])->toContain('account_container');
})->with(['CCACCTFROM', 'INVACCTFROM']);

it('marks another OFX account as mismatched and blocks confirmation atomically', function () {
    [$wallet, $bankAccount] = createOfxAccountValidationContext([
        'bank_code' => '001',
        'agency' => '1234',
        'account_number' => '98765-4',
        'account_type' => 'checking',
    ]);
    $contents = ofxWithAccountMetadata(accountId: '11111-0');
    $preview = app(PreviewOfxBankStatement::class)->execute(
        wallet: $wallet,
        bankAccount: $bankAccount,
        contents: $contents,
        originalFilename: 'outra-conta.ofx',
    );
    $validation = $preview['account_validation'];

    expect($validation['status'])->toBe(ValidateOfxBankAccount::STATUS_MISMATCHED)
        ->and($validation['blocking'])->toBeTrue()
        ->and($validation['message'])->toBe('Este arquivo do extrato parece pertencer a outra conta bancária.')
        ->and($validation['divergent_fields'])->toContain('account_number');

    $decisions = collect($preview['rows'])
        ->map(fn (array $row) => [
            'row_key' => $row['row_key'],
            'action' => $row['default_action'],
        ])
        ->all();

    expect(fn () => app(ConfirmOfxBankStatement::class)->execute(
        wallet: $wallet,
        bankAccount: $bankAccount,
        contents: $contents,
        originalFilename: 'outra-conta.ofx',
        expectedFileHash: $preview['file_hash'],
        decisions: $decisions,
        expectedRows: $preview['rows'],
    ))->toThrow(OfxImportException::class, ValidateOfxBankAccount::MISMATCH_MESSAGE);

    expect(BankStatementImport::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('keeps an incomplete account unverified without blocking the preview', function () {
    [$wallet, $bankAccount] = createOfxAccountValidationContext();
    $contents = ofxWithAccountMetadata(
        bankId: null,
        branchId: null,
        accountId: null,
        accountType: null,
    );

    $preview = app(PreviewOfxBankStatement::class)->execute(
        wallet: $wallet,
        bankAccount: $bankAccount,
        contents: $contents,
        originalFilename: 'sem-conta.ofx',
    );
    $validation = $preview['account_validation'];

    expect($validation['status'])->toBe(ValidateOfxBankAccount::STATUS_UNVERIFIED)
        ->and($validation['blocking'])->toBeFalse()
        ->and($validation['message'])->toBe('Não foi possível validar totalmente a conta do arquivo do extrato.')
        ->and($validation['matched_fields'])->toBe([])
        ->and($validation['divergent_fields'])->toBe([])
        ->and($validation['warnings'])->toContain('Não foi possível validar totalmente a conta do arquivo do extrato.');
});

it('does not treat agency alone as validation of the financial institution', function () {
    [$wallet, $bankAccount] = createOfxAccountValidationContext([
        'bank_code' => '001',
        'agency' => '1234',
        'account_number' => '98765-4',
        'account_type' => 'checking',
    ]);

    $preview = app(PreviewOfxBankStatement::class)->execute(
        wallet: $wallet,
        bankAccount: $bankAccount,
        contents: ofxWithAccountMetadata(bankId: null),
        originalFilename: 'sem-identificador-do-banco.ofx',
    );

    expect($preview['account_validation']['status'])->toBe(ValidateOfxBankAccount::STATUS_UNVERIFIED)
        ->and($preview['account_validation']['blocking'])->toBeFalse()
        ->and($preview['account_validation']['matched_fields'])->toContain('agency', 'account_number')
        ->and($preview['account_validation']['matched_fields'])->not->toContain('bank_code');
});
