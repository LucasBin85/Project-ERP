<?php

namespace App\Services\Financial;

use App\DTOs\Financial\OfxImportResultDTO;
use App\Exceptions\OfxImportException;
use App\Models\BankAccount;
use App\Models\BankAccountTransfer;
use App\Models\BankStatementImport;
use App\Models\BankStatementImportTransaction;
use App\Models\Wallet;
use App\Services\Accounting\CreateBankImportEntry;
use Illuminate\Support\Facades\DB;

class ConfirmOfxBankStatement
{
    public function __construct(
        private readonly PreviewOfxBankStatement $preview,
        private readonly ParseStatementFile $parser,
        private readonly CreateBankImportEntry $createBankImportEntry,
        private readonly FindMatchingOfxJournalLine $matchingJournalLines,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $decisions
     * @param  array<int, array<string, mixed>>|null  $expectedRows
     */
    public function execute(
        Wallet $wallet,
        BankAccount $bankAccount,
        string $contents,
        string $originalFilename,
        string $expectedFileHash,
        array $decisions,
        array $expectedRows,
    ): OfxImportResultDTO {
        if (! hash_equals($expectedFileHash, hash('sha256', $this->parser->format($originalFilename).'|'.$contents))) {
            throw new OfxImportException('O arquivo não corresponde à pré-visualização confirmada.');
        }

        return DB::transaction(function () use (
            $wallet,
            $bankAccount,
            $contents,
            $originalFilename,
            $expectedFileHash,
            $decisions,
            $expectedRows,
        ) {
            $lockedBankAccount = BankAccount::query()
                ->whereKey($bankAccount->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedBankAccount->wallet_id !== (int) $wallet->id || ! $lockedBankAccount->is_active) {
                throw new OfxImportException('A conta bancária não está mais disponível na wallet ativa.');
            }

            $currentPreview = $this->preview->execute(
                wallet: $wallet,
                bankAccount: $lockedBankAccount,
                contents: $contents,
                originalFilename: $originalFilename,
            );

            if (! hash_equals($expectedFileHash, $currentPreview['file_hash'])) {
                throw new OfxImportException('O arquivo OFX foi alterado depois da pré-visualização.');
            }

            if ($currentPreview['account_validation']['blocking']) {
                throw new OfxImportException($currentPreview['account_validation']['message']);
            }

            if ($currentPreview['has_errors']) {
                throw new OfxImportException('Corrija os erros indicados na prévia antes de confirmar a importação.');
            }

            $this->ensurePreviewIsCurrent($expectedRows, $currentPreview['rows']);

            $parsed = $this->parser->parse($contents, $originalFilename);
            $transactions = $parsed['transactions'];
            $this->ensureDecisionsCoverPreview($decisions, $currentPreview['rows']);
            $decisionsByRowKey = collect($decisions)->keyBy('row_key');

            $import = BankStatementImport::query()->create([
                'wallet_id' => $wallet->id,
                'bank_account_id' => $lockedBankAccount->id,
                'source' => $this->parser->format($originalFilename),
                'original_filename' => $originalFilename,
                'file_hash' => $expectedFileHash,
                'statement_started_at' => $parsed['started_at'],
                'statement_ended_at' => $parsed['ended_at'],
                'total_transactions' => count($currentPreview['rows']),
                'status' => 'completed',
            ]);

            $created = 0;
            $linked = 0;
            $duplicates = 0;
            $ignored = 0;
            $totalIn = 0;
            $totalOut = 0;

            foreach ($currentPreview['rows'] as $row) {
                $transaction = $transactions[$row['index']] ?? null;

                if (! $transaction) {
                    throw new OfxImportException('A prévia contém uma linha que não pôde ser relida do arquivo.');
                }

                $decision = (array) ($decisionsByRowKey->get($row['row_key']) ?? []);
                $action = (string) ($decision['action'] ?? $row['default_action']);

                if ($row['situation'] === 'already_imported') {
                    $this->recordAuditTransaction(
                        import: $import,
                        wallet: $wallet,
                        bankAccount: $lockedBankAccount,
                        row: $row,
                        transaction: $transaction,
                        resolution: 'duplicate',
                        status: 'skipped_duplicate',
                        journalEntryId: $row['suggestion']['journal_entry_id'] ?? null,
                        journalLineId: $row['suggestion']['journal_line_id'] ?? null,
                    );
                    $duplicates++;

                    continue;
                }

                if (! in_array($action, $row['allowed_actions'], true)) {
                    throw new OfxImportException('A ação escolhida não é permitida para uma das linhas do OFX.');
                }

                if ($row['situation'] === 'ignored' || $action === 'ignore') {
                    $this->recordAuditTransaction(
                        import: $import,
                        wallet: $wallet,
                        bankAccount: $lockedBankAccount,
                        row: $row,
                        transaction: $transaction,
                        resolution: 'ignored',
                        status: 'skipped_duplicate',
                    );
                    $ignored++;

                    continue;
                }

                if ($row['situation'] === 'possible_match' && $action === 'link') {
                    $candidates = $this->matchingJournalLines->candidates(
                        wallet: $wallet,
                        bankAccount: $lockedBankAccount,
                        entryDate: $transaction->postedAt,
                        amountCents: $transaction->amountCents,
                        direction: $transaction->direction,
                        lockForUpdate: true,
                    );
                    $journalLine = $candidates->count() === 1
                        ? $candidates->first()
                        : null;

                    if (! $journalLine
                        || (int) $journalLine->id !== (int) ($row['suggestion']['journal_line_id'] ?? 0)
                        || ! $journalLine->journalEntry) {
                        throw new OfxImportException('O lançamento sugerido para vínculo não está mais disponível.');
                    }

                    $entry = $journalLine->journalEntry;

                    $this->recordAuditTransaction(
                        import: $import,
                        wallet: $wallet,
                        bankAccount: $lockedBankAccount,
                        row: $row,
                        transaction: $transaction,
                        resolution: 'linked',
                        status: 'imported',
                        journalEntryId: $entry->id,
                        journalLineId: $journalLine->id,
                    );
                    $linked++;
                    $this->addToTotals($transaction->direction, $transaction->amountCents, $totalIn, $totalOut);

                    continue;
                }

                $pendingTransfer = $this->pendingTransferCounterpart(
                    wallet: $wallet,
                    bankAccount: $lockedBankAccount,
                    date: $transaction->postedAt,
                    amountCents: $transaction->amountCents,
                    direction: $transaction->direction,
                );

                if ($pendingTransfer) {
                    $lineId = $transaction->direction === 'in'
                        ? $pendingTransfer->to_journal_line_id
                        : $pendingTransfer->from_journal_line_id;
                    $audit = $this->recordAuditTransaction(
                        import: $import, wallet: $wallet, bankAccount: $lockedBankAccount,
                        row: $row, transaction: $transaction, resolution: 'linked_transfer', status: 'imported',
                        journalEntryId: $pendingTransfer->journal_entry_id, journalLineId: $lineId,
                    );
                    $otherBankAccountId = $transaction->direction === 'in'
                        ? $pendingTransfer->from_bank_account_id
                        : $pendingTransfer->to_bank_account_id;
                    $audit->update([
                        'operation_type' => OfxOperationTypePolicy::TRANSFER,
                        'classification_account_id' => BankAccount::query()->whereKey($otherBankAccountId)->value('chart_of_account_id'),
                    ]);
                    $pendingTransfer->update([
                        $transaction->direction === 'in' ? 'to_import_transaction_id' : 'from_import_transaction_id' => $audit->id,
                        'validation_status' => 'fully_validated',
                    ]);
                    $linked++;
                    $this->addToTotals($transaction->direction, $transaction->amountCents, $totalIn, $totalOut);
                    continue;
                }

                if ($action !== 'create' || ! in_array($row['situation'], ['new', 'ambiguous_match'], true)) {
                    throw new OfxImportException('Não foi possível resolver uma das linhas confirmadas do OFX.');
                }

                $entry = $this->createBankImportEntry->handle(
                    wallet: $wallet,
                    bankAccountId: $lockedBankAccount->chart_of_account_id,
                    amountCents: $transaction->amountCents,
                    direction: $transaction->direction,
                    entryDate: $transaction->postedAt,
                    description: $transaction->description,
                    source: $this->parser->format($originalFilename),
                    externalId: $row['external_id'],
                    autoPostIfBalanced: false,
                );

                $bankLine = $entry->lines
                    ->firstWhere('chart_of_account_id', $lockedBankAccount->chart_of_account_id);

                if (! $bankLine) {
                    throw new OfxImportException('Não foi possível identificar a linha bancária do lançamento criado.');
                }

                $this->recordAuditTransaction(
                    import: $import,
                    wallet: $wallet,
                    bankAccount: $lockedBankAccount,
                    row: $row,
                    transaction: $transaction,
                    resolution: $row['situation'] === 'ambiguous_match' ? 'kept' : 'created',
                    status: 'imported',
                    journalEntryId: $entry->id,
                    journalLineId: $bankLine->id,
                );
                $created++;
                $this->addToTotals($transaction->direction, $transaction->amountCents, $totalIn, $totalOut);
            }

            $import->update([
                'imported_transactions' => $created + $linked,
                'skipped_duplicates' => $duplicates,
                'total_in_cents' => $totalIn,
                'total_out_cents' => $totalOut,
            ]);

            return new OfxImportResultDTO(
                import: $import->fresh(['bankAccount', 'transactions.journalEntry', 'transactions.journalLine']),
                created: $created,
                linked: $linked,
                duplicates: $duplicates,
                ignored: $ignored,
            );
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $expectedRows
     * @param  array<int, array<string, mixed>>  $currentRows
     */
    private function ensurePreviewIsCurrent(array $expectedRows, array $currentRows): void
    {
        $expected = collect($expectedRows)->keyBy('row_key');

        if ($expected->count() !== count($currentRows)) {
            throw new OfxImportException('A pré-visualização está desatualizada. Carregue o arquivo novamente.');
        }

        foreach ($currentRows as $current) {
            $previous = $expected->get($current['row_key']);

            if (! $previous) {
                throw new OfxImportException('A pré-visualização está desatualizada. Carregue o arquivo novamente.');
            }

            $situationChanged = $previous['situation'] !== $current['situation'];
            $becameDuplicate = $current['situation'] === 'already_imported';

            if ($situationChanged && ! $becameDuplicate) {
                throw new OfxImportException('Os vínculos possíveis mudaram desde a prévia. Revise o arquivo novamente.');
            }

            $previousLineId = $previous['suggestion']['journal_line_id'] ?? null;
            $currentLineId = $current['suggestion']['journal_line_id'] ?? null;

            if (! $becameDuplicate && $previousLineId !== $currentLineId) {
                throw new OfxImportException('O lançamento sugerido mudou desde a prévia. Revise o arquivo novamente.');
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $decisions
     * @param  array<int, array<string, mixed>>  $currentRows
     */
    private function ensureDecisionsCoverPreview(array $decisions, array $currentRows): void
    {
        $decisionKeys = collect($decisions)
            ->pluck('row_key')
            ->filter(fn ($rowKey) => is_string($rowKey))
            ->values();
        $previewKeys = collect($currentRows)->pluck('row_key')->values();

        if ($decisionKeys->count() !== $previewKeys->count()
            || $decisionKeys->unique()->count() !== $decisionKeys->count()
            || $decisionKeys->sort()->values()->all() !== $previewKeys->sort()->values()->all()) {
            throw new OfxImportException(
                'A confirmação deve incluir exatamente todas as linhas exibidas na pré-visualização.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function recordAuditTransaction(
        BankStatementImport $import,
        Wallet $wallet,
        BankAccount $bankAccount,
        array $row,
        object $transaction,
        string $resolution,
        string $status,
        ?int $journalEntryId = null,
        ?int $journalLineId = null,
    ): BankStatementImportTransaction {
        return BankStatementImportTransaction::query()->create([
            'bank_statement_import_id' => $import->id,
            'wallet_id' => $wallet->id,
            'bank_account_id' => $bankAccount->id,
            'file_format' => $import->source,
            'journal_entry_id' => $journalEntryId,
            'journal_line_id' => $journalLineId,
            'classification_account_id' => null,
            'external_id' => $row['external_id'],
            'transaction_hash' => $row['transaction_hash'],
            'fit_id' => $row['has_fit_id'] ? $transaction->fitId : null,
            'posted_at' => $transaction->postedAt,
            'description' => $transaction->description,
            'amount_cents' => $transaction->amountCents,
            'direction' => $transaction->direction,
            'operation_type' => null,
            'status' => $status,
            'resolution' => $resolution,
            'raw_payload' => $transaction->raw,
        ]);
    }

    private function addToTotals(
        string $direction,
        int $amountCents,
        int &$totalIn,
        int &$totalOut,
    ): void {
        if ($direction === 'in') {
            $totalIn += $amountCents;

            return;
        }

        $totalOut += $amountCents;
    }

    private function pendingTransferCounterpart(Wallet $wallet, BankAccount $bankAccount, string $date, int $amountCents, string $direction): ?BankAccountTransfer
    {
        $query = BankAccountTransfer::query()->where('wallet_id', $wallet->id)
            ->whereDate('transfer_date', $date)->where('amount_cents', $amountCents)
            ->where('validation_status', 'pending_counterpart_ofx');

        $direction === 'in'
            ? $query->where('to_bank_account_id', $bankAccount->id)->whereNull('to_import_transaction_id')
            : $query->where('from_bank_account_id', $bankAccount->id)->whereNull('from_import_transaction_id');

        $candidates = $query->lockForUpdate()->limit(2)->get();
        return $candidates->count() === 1 ? $candidates->first() : null;
    }
}
