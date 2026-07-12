<?php

namespace App\Services\Financial;

use App\Models\BankAccount;
use App\Models\BankStatementImport;
use App\Models\BankStatementImportTransaction;
use App\Models\JournalEntry;
use App\Models\Wallet;
use App\Services\Accounting\CreateBankImportEntry;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportOfxBankStatement
{
    public function __construct(
        private readonly ParseOfxStatement $parser,
        private readonly FindMatchingOfxJournalLine $findMatchingOfxJournalLine,
        private readonly CreateBankImportEntry $createBankImportEntry,
    ) {}

    public function execute(Wallet $wallet, BankAccount $bankAccount, string $contents, string $originalFilename): BankStatementImport
    {
        if ($bankAccount->wallet_id !== $wallet->id) {
            abort(404);
        }

        if (! $wallet->suspense_account_id) {
            throw new RuntimeException('A carteira ativa não possui conta "A classificar" definida.');
        }

        $parsed = $this->parser->parse($contents);
        $fileHash = sha1($contents);

        return DB::transaction(function () use ($wallet, $bankAccount, $parsed, $fileHash, $originalFilename) {
            $import = BankStatementImport::query()->create([
                'wallet_id' => $wallet->id,
                'bank_account_id' => $bankAccount->id,
                'source' => 'ofx',
                'original_filename' => $originalFilename,
                'file_hash' => $fileHash,
                'statement_started_at' => $parsed['started_at'],
                'statement_ended_at' => $parsed['ended_at'],
                'total_transactions' => count($parsed['transactions']),
                'status' => 'completed',
            ]);

            $imported = 0;
            $skipped = 0;
            $totalIn = 0;
            $totalOut = 0;

            foreach ($parsed['transactions'] as $transaction) {
                $externalId = $this->externalId($bankAccount, $transaction->fitId);

                $existingTransaction = BankStatementImportTransaction::query()
                    ->where('wallet_id', $wallet->id)
                    ->where('bank_account_id', $bankAccount->id)
                    ->where('external_id', $externalId)
                    ->where('status', 'imported')
                    ->first();

                if ($existingTransaction) {
                    $skipped++;

                    BankStatementImportTransaction::query()->create([
                        'bank_statement_import_id' => $import->id,
                        'wallet_id' => $wallet->id,
                        'bank_account_id' => $bankAccount->id,
                        'journal_entry_id' => $existingTransaction->journal_entry_id,
                        'journal_line_id' => $existingTransaction->journal_line_id,
                        'external_id' => $externalId,
                        'fit_id' => $transaction->fitId,
                        'posted_at' => $transaction->postedAt,
                        'description' => $transaction->description,
                        'amount_cents' => $transaction->amountCents,
                        'direction' => $transaction->direction,
                        'status' => 'skipped_duplicate',
                        'raw_payload' => $transaction->raw,
                    ]);

                    continue;
                }

                $legacyEntry = JournalEntry::query()
                    ->where('wallet_id', $wallet->id)
                    ->where('source', 'ofx')
                    ->where('external_id', $externalId)
                    ->with('lines')
                    ->first();

                if ($legacyEntry) {
                    $legacyLine = $legacyEntry->lines
                        ->firstWhere('chart_of_account_id', $bankAccount->chart_of_account_id);

                    $skipped++;

                    BankStatementImportTransaction::query()->create([
                        'bank_statement_import_id' => $import->id,
                        'wallet_id' => $wallet->id,
                        'bank_account_id' => $bankAccount->id,
                        'journal_entry_id' => $legacyEntry->id,
                        'journal_line_id' => $legacyLine?->id,
                        'external_id' => $externalId,
                        'fit_id' => $transaction->fitId,
                        'posted_at' => $transaction->postedAt,
                        'description' => $transaction->description,
                        'amount_cents' => $transaction->amountCents,
                        'direction' => $transaction->direction,
                        'status' => 'skipped_duplicate',
                        'raw_payload' => $transaction->raw,
                    ]);

                    continue;
                }

                $journalLine = $this->findMatchingOfxJournalLine->find(
                    wallet: $wallet,
                    bankAccount: $bankAccount,
                    entryDate: $transaction->postedAt,
                    amountCents: $transaction->amountCents,
                    direction: $transaction->direction,
                );

                if ($journalLine) {
                    $entry = $journalLine->journalEntry;
                } else {
                    $entry = $this->createBankImportEntry->handle(
                        wallet: $wallet,
                        bankAccountId: $bankAccount->chart_of_account_id,
                        amountCents: $transaction->amountCents,
                        direction: $transaction->direction,
                        entryDate: $transaction->postedAt,
                        description: $transaction->description,
                        source: 'ofx',
                        externalId: $externalId,
                        autoPostIfBalanced: false,
                    );

                    $journalLine = $entry->lines
                        ->firstWhere('chart_of_account_id', $bankAccount->chart_of_account_id);
                }

                if (! $journalLine) {
                    throw new RuntimeException('Não foi possível identificar a linha da conta bancária no lançamento importado.');
                }

                BankStatementImportTransaction::query()->create([
                    'bank_statement_import_id' => $import->id,
                    'wallet_id' => $wallet->id,
                    'bank_account_id' => $bankAccount->id,
                    'journal_entry_id' => $entry->id,
                    'journal_line_id' => $journalLine->id,
                    'external_id' => $externalId,
                    'fit_id' => $transaction->fitId,
                    'posted_at' => $transaction->postedAt,
                    'description' => $transaction->description,
                    'amount_cents' => $transaction->amountCents,
                    'direction' => $transaction->direction,
                    'status' => 'imported',
                    'raw_payload' => $transaction->raw,
                ]);

                $imported++;

                if ($transaction->direction === 'in') {
                    $totalIn += $transaction->amountCents;
                } else {
                    $totalOut += $transaction->amountCents;
                }
            }

            $import->update([
                'imported_transactions' => $imported,
                'skipped_duplicates' => $skipped,
                'total_in_cents' => $totalIn,
                'total_out_cents' => $totalOut,
            ]);

            return $import->fresh(['bankAccount', 'transactions.journalEntry', 'transactions.journalLine']);
        });
    }

    private function externalId(BankAccount $bankAccount, string $fitId): string
    {
        return 'ofx:bank-account:'.$bankAccount->id.':'.$fitId;
    }
}
