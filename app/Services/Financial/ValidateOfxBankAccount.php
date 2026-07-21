<?php

namespace App\Services\Financial;

use App\Models\Bank;
use App\Models\BankAccount;

class ValidateOfxBankAccount
{
    public const STATUS_VALIDATED = 'validated';

    public const STATUS_UNVERIFIED = 'unverified';

    public const STATUS_MISMATCHED = 'mismatched';

    public const MISMATCH_MESSAGE = 'Este arquivo do extrato parece pertencer a outra conta bancária.';

    public const UNVERIFIED_MESSAGE = 'Não foi possível validar totalmente a conta do arquivo do extrato.';

    /**
     * @param  array<string, ?string>  $ofxMetadata
     * @return array{
     *     status: string,
     *     blocking: bool,
     *     message: string,
     *     current_account: array<string, mixed>,
     *     ofx_account: array<string, mixed>,
     *     matched_fields: array<int, string>,
     *     divergent_fields: array<int, string>,
     *     warnings: array<int, string>
     * }
     */
    public function execute(BankAccount $bankAccount, array $ofxMetadata): array
    {
        $bankAccount->loadMissing('bank:id,code,name,short_name,ispb');
        $catalogBank = $bankAccount->bank;

        if (! $catalogBank && $bankAccount->bank_code) {
            $normalizedBankCode = $this->normalizeIdentifier($bankAccount->bank_code);
            $catalogBank = Bank::query()
                ->get(['id', 'code', 'name', 'short_name', 'ispb'])
                ->first(fn (Bank $bank) => $this->normalizeIdentifier($bank->code) === $normalizedBankCode);
        }

        $currentAccount = [
            'id' => $bankAccount->id,
            'name' => $bankAccount->name,
            'bank_name' => $bankAccount->bank_name ?: $catalogBank?->short_name,
            'bank_code' => $bankAccount->bank_code ?: $catalogBank?->code,
            'ispb' => $catalogBank?->ispb,
            'agency' => $bankAccount->agency,
            'account_number' => $bankAccount->account_number,
            'account_type' => $bankAccount->account_type,
        ];

        $ofxAccount = [
            'bank_code' => $ofxMetadata['bank_id'] ?? null,
            'ispb' => $ofxMetadata['routing_number'] ?? null,
            'agency' => $ofxMetadata['branch_id'] ?? null,
            'account_number' => $ofxMetadata['account_id'] ?? null,
            'account_type' => $ofxMetadata['account_type'] ?? null,
            'container' => $ofxMetadata['container'] ?? null,
            'bank_id' => $ofxMetadata['bank_id'] ?? null,
            'branch_id' => $ofxMetadata['branch_id'] ?? null,
            'account_id' => $ofxMetadata['account_id'] ?? null,
            'broker_id' => $ofxMetadata['broker_id'] ?? null,
            'routing_number' => $ofxMetadata['routing_number'] ?? null,
            'bank_name' => $ofxMetadata['bank_name'] ?? $ofxMetadata['organization'] ?? null,
            'organization' => $ofxMetadata['organization'] ?? null,
            'financial_institution_id' => $ofxMetadata['financial_institution_id'] ?? null,
            'currency' => $ofxMetadata['currency'] ?? null,
        ];

        $matchedFields = [];
        $divergentFields = [];
        $warnings = [];

        if ($ofxAccount['container'] !== null && $ofxAccount['container'] !== 'BANKACCTFROM') {
            $divergentFields[] = 'account_container';
            $warnings[] = 'O arquivo do extrato identifica uma conta que não é uma conta bancária transacional.';
        }

        $this->compareBankIdentifiers(
            currentValues: [$currentAccount['bank_code'], $currentAccount['ispb']],
            ofxValues: [$ofxAccount['bank_code'], $ofxAccount['ispb']],
            matchedFields: $matchedFields,
            divergentFields: $divergentFields,
            warnings: $warnings,
        );

        foreach ([
            'agency' => 'agência',
            'account_number' => 'número da conta',
        ] as $field => $label) {
            $this->compareIdentifier(
                field: $field,
                label: $label,
                currentValue: $currentAccount[$field],
                ofxValue: $ofxAccount[$field],
                matchedFields: $matchedFields,
                divergentFields: $divergentFields,
                warnings: $warnings,
            );
        }

        $this->compareAccountType(
            currentValue: $currentAccount['account_type'],
            ofxValue: $ofxAccount['account_type'],
            matchedFields: $matchedFields,
            divergentFields: $divergentFields,
            warnings: $warnings,
        );

        if ($divergentFields !== []) {
            return $this->result(
                status: self::STATUS_MISMATCHED,
                message: self::MISMATCH_MESSAGE,
                currentAccount: $currentAccount,
                ofxAccount: $ofxAccount,
                matchedFields: $matchedFields,
                divergentFields: $divergentFields,
                warnings: $warnings,
            );
        }

        $hasAccountMatch = in_array('account_number', $matchedFields, true);
        $hasInstitutionMatch = in_array('bank_code', $matchedFields, true);

        if (! $hasAccountMatch || ! $hasInstitutionMatch) {
            $warnings[] = self::UNVERIFIED_MESSAGE;

            return $this->result(
                status: self::STATUS_UNVERIFIED,
                message: self::UNVERIFIED_MESSAGE,
                currentAccount: $currentAccount,
                ofxAccount: $ofxAccount,
                matchedFields: $matchedFields,
                divergentFields: [],
                warnings: $warnings,
            );
        }

        return $this->result(
            status: self::STATUS_VALIDATED,
            message: 'A conta do arquivo do extrato foi validada.',
            currentAccount: $currentAccount,
            ofxAccount: $ofxAccount,
            matchedFields: $matchedFields,
            divergentFields: [],
            warnings: $warnings,
        );
    }

    /**
     * @param  array<int, string>  $matchedFields
     * @param  array<int, string>  $divergentFields
     * @param  array<int, string>  $warnings
     */
    private function compareIdentifier(
        string $field,
        string $label,
        mixed $currentValue,
        mixed $ofxValue,
        array &$matchedFields,
        array &$divergentFields,
        array &$warnings,
    ): void {
        $currentValue = $this->nullableString($currentValue);
        $ofxValue = $this->nullableString($ofxValue);

        if ($currentValue === null) {
            $warnings[] = sprintf('A conta bancária atual não possui %s cadastrado.', $label);
        }

        if ($ofxValue === null) {
            $warnings[] = sprintf('O arquivo do extrato não informa %s.', $label);
        }

        if ($currentValue === null || $ofxValue === null) {
            return;
        }

        if ($this->normalizeIdentifier($currentValue) === $this->normalizeIdentifier($ofxValue)) {
            $matchedFields[] = $field;

            return;
        }

        if (in_array($field, ['agency', 'account_number'], true)
            && $this->matchesWithoutExplicitCheckDigit($currentValue, $ofxValue)) {
            $matchedFields[] = $field;
            $warnings[] = sprintf(
                'O %s foi validado considerando um dígito verificador omitido no arquivo ou no cadastro.',
                $label,
            );

            return;
        }

        $divergentFields[] = $field;
    }

    /**
     * @param  array<int, string>  $matchedFields
     * @param  array<int, string>  $divergentFields
     * @param  array<int, string>  $warnings
     */
    private function compareAccountType(
        mixed $currentValue,
        mixed $ofxValue,
        array &$matchedFields,
        array &$divergentFields,
        array &$warnings,
    ): void {
        $currentValue = $this->nullableString($currentValue);
        $ofxValue = $this->nullableString($ofxValue);

        if ($currentValue === null) {
            $warnings[] = 'A conta bancária atual não possui tipo de conta cadastrado.';
        }

        if ($ofxValue === null) {
            $warnings[] = 'O arquivo do extrato não informa o tipo da conta.';
        }

        if ($currentValue === null || $ofxValue === null) {
            return;
        }

        $normalizedCurrent = $this->normalizeAccountType($currentValue);
        $normalizedOfx = $this->normalizeAccountType($ofxValue);

        if ($normalizedCurrent === null || $normalizedOfx === null
            || in_array('other', [$normalizedCurrent, $normalizedOfx], true)) {
            $warnings[] = 'O tipo da conta não pôde ser comparado com segurança.';

            return;
        }

        if ($normalizedCurrent === $normalizedOfx) {
            $matchedFields[] = 'account_type';

            return;
        }

        $divergentFields[] = 'account_type';
    }

    private function normalizeIdentifier(string $value): string
    {
        $normalized = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $value));

        if ($normalized !== '' && ctype_digit($normalized)) {
            return ltrim($normalized, '0') ?: '0';
        }

        return $normalized;
    }

    private function matchesWithoutExplicitCheckDigit(string $left, string $right): bool
    {
        $leftBase = $this->baseWithoutExplicitCheckDigit($left);
        $rightBase = $this->baseWithoutExplicitCheckDigit($right);
        $normalizedLeft = $this->normalizeIdentifier($left);
        $normalizedRight = $this->normalizeIdentifier($right);

        if (($leftBase !== null && $leftBase === $normalizedRight)
            || ($rightBase !== null && $rightBase === $normalizedLeft)) {
            return true;
        }

        $shorter = strlen($normalizedLeft) < strlen($normalizedRight)
            ? $normalizedLeft
            : $normalizedRight;
        $longer = strlen($normalizedLeft) < strlen($normalizedRight)
            ? $normalizedRight
            : $normalizedLeft;

        return strlen($longer) === strlen($shorter) + 1
            && str_starts_with($longer, $shorter);
    }

    private function baseWithoutExplicitCheckDigit(string $value): ?string
    {
        if (! preg_match('/^(.+?)[\-\/.]([A-Z0-9])$/i', trim($value), $match)) {
            return null;
        }

        return $this->normalizeIdentifier($match[1]);
    }

    private function normalizeAccountType(string $value): ?string
    {
        $normalized = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $value));

        return match ($normalized) {
            'CHECKING', 'CHECK', 'CURRENT', 'CORRENTE' => 'checking',
            'SAVINGS', 'SAVING', 'POUPANCA' => 'savings',
            'INVESTMENT', 'INVEST', 'MONEYMRKT', 'MONEYMARKET', 'CD' => 'investment',
            'CASH' => 'cash',
            'OTHER' => 'other',
            default => null,
        };
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $currentAccount
     * @param  array<string, mixed>  $ofxAccount
     * @param  array<int, string>  $matchedFields
     * @param  array<int, string>  $divergentFields
     * @param  array<int, string>  $warnings
     * @return array<string, mixed>
     */
    private function result(
        string $status,
        string $message,
        array $currentAccount,
        array $ofxAccount,
        array $matchedFields,
        array $divergentFields,
        array $warnings,
    ): array {
        return [
            'status' => $status,
            'blocking' => $status === self::STATUS_MISMATCHED,
            'message' => $message,
            'current_account' => $currentAccount,
            'ofx_account' => $ofxAccount,
            'matched_fields' => array_values(array_unique($matchedFields)),
            'divergent_fields' => array_values(array_unique($divergentFields)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param  array<int, mixed>  $currentValues
     * @param  array<int, mixed>  $ofxValues
     * @param  array<int, string>  $matchedFields
     * @param  array<int, string>  $divergentFields
     * @param  array<int, string>  $warnings
     */
    private function compareBankIdentifiers(
        array $currentValues,
        array $ofxValues,
        array &$matchedFields,
        array &$divergentFields,
        array &$warnings,
    ): void {
        $currentIdentifiers = collect($currentValues)
            ->map(fn ($value) => $this->nullableString($value))
            ->filter()
            ->map(fn (string $value) => $this->normalizeIdentifier($value))
            ->unique()
            ->values();
        $ofxIdentifiers = collect($ofxValues)
            ->map(fn ($value) => $this->nullableString($value))
            ->filter()
            ->map(fn (string $value) => $this->normalizeIdentifier($value))
            ->unique()
            ->values();

        if ($currentIdentifiers->isEmpty()) {
            $warnings[] = 'A conta bancária atual não possui código do banco ou ISPB cadastrado.';
        }

        if ($ofxIdentifiers->isEmpty()) {
            $warnings[] = 'O arquivo do extrato não informa código do banco ou ISPB.';
        }

        if ($currentIdentifiers->isEmpty() || $ofxIdentifiers->isEmpty()) {
            return;
        }

        if ($currentIdentifiers->intersect($ofxIdentifiers)->isNotEmpty()) {
            $matchedFields[] = 'bank_code';

            return;
        }

        $divergentFields[] = 'bank_code';
    }
}
