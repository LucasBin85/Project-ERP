<?php

namespace App\Services\Financial;

use App\DTOs\Financial\ParsedOfxTransactionDTO;
use App\Models\BankAccount;
use Illuminate\Support\Str;

class OfxTransactionIdentity
{
    /**
     * @return array{
     *     external_id: string,
     *     transaction_hash: string,
     *     row_key: string,
     *     has_fit_id: bool
     * }
     */
    public function forTransaction(
        BankAccount $bankAccount,
        ParsedOfxTransactionDTO $transaction,
        string $fileHash,
        int $index,
    ): array {
        return [
            'external_id' => $this->externalId($bankAccount, $transaction),
            'transaction_hash' => $this->transactionHash($transaction),
            'row_key' => $this->rowKey($fileHash, $index, $transaction),
            'has_fit_id' => $this->hasProvidedFitId($transaction),
        ];
    }

    public function externalId(
        BankAccount $bankAccount,
        ParsedOfxTransactionDTO $transaction,
    ): string {
        $fitId = $this->hasProvidedFitId($transaction)
            ? trim($transaction->fitId)
            : $this->transactionHash($transaction);

        if ($fitId === '') {
            $fitId = $this->transactionHash($transaction);
        }

        $externalId = 'ofx:bank-account:'.$bankAccount->id.':'.$fitId;

        if (mb_strlen($externalId) <= 255) {
            return $externalId;
        }

        return 'ofx:bank-account:'.$bankAccount->id.':'.hash('sha256', $fitId);
    }

    public function legacyExternalId(
        BankAccount $bankAccount,
        ParsedOfxTransactionDTO $transaction,
    ): ?string {
        if ($this->hasProvidedFitId($transaction)) {
            return null;
        }

        $legacyFitId = trim($transaction->fitId);

        return $legacyFitId === ''
            ? null
            : 'ofx:bank-account:'.$bankAccount->id.':'.$legacyFitId;
    }

    public function transactionHash(ParsedOfxTransactionDTO $transaction): string
    {
        $identity = $this->hasProvidedFitId($transaction)
            ? [
                'fit_id' => trim($transaction->fitId),
            ]
            : [
                'posted_at' => $transaction->postedAt,
                'amount_cents' => $transaction->amountCents,
                'direction' => $transaction->direction,
                'transaction_type' => $this->normalize($transaction->raw['trntype'] ?? null),
                'name' => $this->normalize($transaction->raw['name'] ?? null),
                'memo' => $this->normalize($transaction->raw['memo'] ?? null),
                'payee' => $this->normalize($transaction->raw['payee'] ?? null),
                'check_number' => $this->normalize($transaction->raw['checknum'] ?? null),
                'description' => $this->normalize($transaction->description),
            ];

        return hash('sha256', json_encode(
            $identity,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    public function rowKey(
        string $fileHash,
        int $index,
        ParsedOfxTransactionDTO $transaction,
    ): string {
        return hash('sha256', implode('|', [
            strtolower(trim($fileHash)),
            $index,
            $this->transactionHash($transaction),
        ]));
    }

    public function hasProvidedFitId(ParsedOfxTransactionDTO $transaction): bool
    {
        if (array_key_exists('fitid_provided', $transaction->raw)) {
            return (bool) $transaction->raw['fitid_provided'];
        }

        $fitId = trim((string) ($transaction->raw['fitid'] ?? $transaction->fitId));

        if ($fitId === '') {
            return false;
        }

        $legacyFallback = sha1(
            (string) ($transaction->raw['dtposted'] ?? '')
            .'|'.(string) ($transaction->raw['trnamt'] ?? '')
            .'|'.(string) ($transaction->raw['memo'] ?? '')
            .'|'.(string) ($transaction->raw['name'] ?? ''),
        );

        return ! hash_equals($legacyFallback, trim($transaction->fitId));
    }

    private function normalize(mixed $value): string
    {
        return mb_strtolower(Str::squish((string) $value), 'UTF-8');
    }
}
