<?php

namespace App\Services\Financial;

use App\Exceptions\OfxImportException;
use App\Models\BankAccount;
use App\Models\BankStatementImportTransaction;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Wallet;

class PreviewOfxBankStatement
{
    public function __construct(
        private readonly ParseStatementFile $parser,
        private readonly FindMatchingOfxJournalLine $matchingJournalLines,
        private readonly OfxTransactionIdentity $identity,
        private readonly ValidateOfxBankAccount $accountValidation,
    ) {}

    /**
     * Parse an OFX statement and resolve its current import situation without
     * writing imports, journal entries, or journal lines.
     *
     * @return array<string, mixed>
     */
    public function execute(
        Wallet $wallet,
        BankAccount $bankAccount,
        string $contents,
        string $originalFilename,
    ): array {
        $this->validateContext($wallet, $bankAccount);

        $parsed = $this->parser->parse($contents, $originalFilename);
        $accountValidation = $this->accountValidation->execute($bankAccount, $parsed['account']);
        $fileHash = hash('sha256', $this->parser->format($originalFilename).'|'.$contents);
        $rows = [];
        $identitiesInFile = [];

        foreach ($parsed['transactions'] as $index => $transaction) {
            $identity = $this->identity->forTransaction(
                bankAccount: $bankAccount,
                transaction: $transaction,
                fileHash: $fileHash,
                index: $index,
            );
            $legacyExternalId = $this->identity->legacyExternalId($bankAccount, $transaction);

            $withinFileKey = $identity['has_fit_id']
                ? 'external:'.$identity['external_id']
                : 'hash:'.$identity['transaction_hash'];

            if (isset($identitiesInFile[$withinFileKey])) {
                $rows[] = $this->row(
                    index: $index,
                    transaction: $transaction,
                    identity: $identity,
                    situation: 'ignored',
                    defaultAction: 'ignore',
                    candidateCount: 0,
                    suggestion: [
                        'kind' => 'duplicate_in_file',
                        'label' => 'Linha repetida no próprio arquivo',
                    ],
                );

                continue;
            }

            $identitiesInFile[$withinFileKey] = true;

            $duplicate = $this->importedTransaction(
                $wallet,
                $bankAccount,
                $identity,
                $legacyExternalId,
            );

            if ($duplicate) {
                $rows[] = $this->row(
                    index: $index,
                    transaction: $transaction,
                    identity: $identity,
                    situation: 'already_imported',
                    defaultAction: 'ignore',
                    candidateCount: 0,
                    suggestion: [
                        'kind' => 'existing_import',
                        'journal_entry_id' => $duplicate->journal_entry_id,
                        'journal_line_id' => $duplicate->journal_line_id,
                        'label' => $duplicate->journal_entry_id
                            ? 'Já vinculado ao lançamento #'.$duplicate->journal_entry_id
                            : 'Transação já importada',
                    ],
                );

                continue;
            }

            $legacyEntry = JournalEntry::query()
                ->where('wallet_id', $wallet->id)
                ->where('source', 'ofx')
                ->whereIn('external_id', array_values(array_filter([
                    $identity['external_id'],
                    $legacyExternalId,
                ])))
                ->with('lines')
                ->first();

            if ($legacyEntry) {
                $legacyLine = $legacyEntry->lines
                    ->firstWhere('chart_of_account_id', $bankAccount->chart_of_account_id);

                $rows[] = $this->row(
                    index: $index,
                    transaction: $transaction,
                    identity: $identity,
                    situation: 'already_imported',
                    defaultAction: 'ignore',
                    candidateCount: 0,
                    suggestion: [
                        'kind' => 'legacy_import',
                        'journal_entry_id' => $legacyEntry->id,
                        'journal_line_id' => $legacyLine?->id,
                        'label' => 'Já importado no lançamento #'.$legacyEntry->id,
                    ],
                );

                continue;
            }

            $candidates = $this->matchingJournalLines->candidates(
                wallet: $wallet,
                bankAccount: $bankAccount,
                entryDate: $transaction->postedAt,
                amountCents: $transaction->amountCents,
                direction: $transaction->direction,
            );

            if ($candidates->count() === 1) {
                $candidate = $candidates->first();
                $entry = $candidate->journalEntry;

                $rows[] = $this->row(
                    index: $index,
                    transaction: $transaction,
                    identity: $identity,
                    situation: 'possible_match',
                    defaultAction: 'link',
                    candidateCount: 1,
                    suggestion: [
                        'kind' => 'manual_entry',
                        'journal_entry_id' => $entry->id,
                        'journal_line_id' => $candidate->id,
                        'label' => sprintf('#%d · %s', $entry->id, $entry->description),
                        'status' => $entry->status,
                    ],
                );

                continue;
            }

            if ($candidates->count() > 1) {
                $rows[] = $this->row(
                    index: $index,
                    transaction: $transaction,
                    identity: $identity,
                    situation: 'ambiguous_match',
                    defaultAction: 'ignore',
                    candidateCount: $candidates->count(),
                    suggestion: [
                        'kind' => 'ambiguous',
                        'label' => sprintf('%d lançamentos manuais compatíveis', $candidates->count()),
                        'candidate_ids' => $candidates->pluck('journal_entry_id')->values()->all(),
                    ],
                );

                continue;
            }

            $rows[] = $this->row(
                index: $index,
                transaction: $transaction,
                identity: $identity,
                situation: 'new',
                defaultAction: 'create',
                candidateCount: 0,
                suggestion: [
                    'kind' => 'new_entry',
                    'label' => 'Será criado como novo lançamento do extrato em rascunho',
                ],
            );
        }

        foreach ($parsed['errors'] as $error) {
            $rows[] = [
                'row_key' => hash('sha256', $fileHash.'|error|'.$error['index']),
                'index' => $error['index'],
                'date' => null,
                'description' => $error['message'],
                'amount_cents' => null,
                'signed_amount_cents' => null,
                'direction' => null,
                'situation' => 'error',
                'default_action' => 'ignore',
                'allowed_actions' => ['ignore'],
                'candidate_count' => 0,
                'suggestion' => [
                    'kind' => 'error',
                    'label' => $error['message'],
                ],
            ];
        }

        usort($rows, fn (array $left, array $right) => $left['index'] <=> $right['index']);

        return [
            'file_name' => $originalFilename,
            'format' => strtoupper($this->parser->format($originalFilename)),
            'origin' => $this->parser->format($originalFilename) === 'pdf' && ($parsed['read_source'] ?? null) === 'ocr' ? 'PDF/OCR' : strtoupper($this->parser->format($originalFilename)),
            'file_hash' => $fileHash,
            'bank_account_id' => $bankAccount->id,
            'bank_account_name' => $bankAccount->name,
            'statement_started_at' => $parsed['started_at'],
            'statement_ended_at' => $parsed['ended_at'],
            'account_validation' => $accountValidation,
            'rows' => $rows,
            'summary' => collect($rows)
                ->countBy('situation')
                ->all(),
            'has_errors' => collect($rows)->contains('situation', 'error'),
        ];
    }

    private function validateContext(Wallet $wallet, BankAccount $bankAccount): void
    {
        if ((int) $bankAccount->wallet_id !== (int) $wallet->id || ! $bankAccount->is_active) {
            throw new OfxImportException('A conta bancária deve estar ativa e pertencer à wallet atual.');
        }

        if (! $wallet->suspense_account_id) {
            throw new OfxImportException('A wallet ativa não possui conta "A classificar" definida.');
        }

        $suspenseAccount = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->whereKey($wallet->suspense_account_id)
            ->first();

        if (! $suspenseAccount
            || ! $suspenseAccount->isPostingAllowed()
            || $suspenseAccount->children()->exists()) {
            throw new OfxImportException(
                'A conta "A classificar" deve ser uma conta analítica e lançável da wallet ativa.',
            );
        }

    }

    /** @param array<string, mixed> $identity */
    private function importedTransaction(
        Wallet $wallet,
        BankAccount $bankAccount,
        array $identity,
        ?string $legacyExternalId,
    ): ?BankStatementImportTransaction {
        return BankStatementImportTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('bank_account_id', $bankAccount->id)
            ->where('status', 'imported')
            ->where(function ($query) use ($identity, $legacyExternalId) {
                $query->where('external_id', $identity['external_id'])
                    ->orWhere('transaction_hash', $identity['transaction_hash'])
                    ->when(
                        $legacyExternalId,
                        fn ($query) => $query->orWhere('external_id', $legacyExternalId),
                    );
            })
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $suggestion
     * @return array<string, mixed>
     */
    private function row(
        int $index,
        object $transaction,
        array $identity,
        string $situation,
        string $defaultAction,
        int $candidateCount,
        array $suggestion,
    ): array {
        $allowedActions = match ($situation) {
            'new' => ['create', 'ignore'],
            'possible_match' => ['link', 'ignore'],
            'ambiguous_match' => ['ignore', 'create'],
            default => ['ignore'],
        };

        return [
            ...$identity,
            'index' => $index,
            'date' => $transaction->postedAt,
            'description' => $transaction->description,
            'amount_cents' => $transaction->amountCents,
            'signed_amount_cents' => $transaction->direction === 'in'
                ? $transaction->amountCents
                : -$transaction->amountCents,
            'direction' => $transaction->direction,
            'situation' => $situation,
            'default_action' => $defaultAction,
            'allowed_actions' => $allowedActions,
            'candidate_count' => $candidateCount,
            'suggestion' => $suggestion,
        ];
    }
}
