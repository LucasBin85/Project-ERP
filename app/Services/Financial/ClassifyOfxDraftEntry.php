<?php

namespace App\Services\Financial;

use App\DTOs\Financial\OfxClassificationDTO;
use App\Exceptions\OfxClassificationException;
use App\Models\BankAccount;
use App\Models\BankStatementImportTransaction;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Wallet;
use App\Services\Accounting\PostJournalEntry;
use Illuminate\Support\Facades\DB;

class ClassifyOfxDraftEntry
{
    public function __construct(
        private readonly PostJournalEntry $postJournalEntry,
    ) {}

    public function execute(
        Wallet $wallet,
        BankAccount $bankAccount,
        JournalEntry $entry,
        OfxClassificationDTO $dto,
    ): JournalEntry {
        return DB::transaction(function () use ($wallet, $bankAccount, $entry, $dto) {
            $entry = JournalEntry::query()
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $bankAccount->wallet_id !== (int) $wallet->id
                || (int) $entry->wallet_id !== (int) $wallet->id) {
                throw new OfxClassificationException('A conta bancária e o lançamento devem pertencer à wallet ativa.');
            }

            if ($entry->source !== 'ofx') {
                throw new OfxClassificationException('Somente lançamentos originados de OFX podem ser classificados por esta ação.');
            }

            if ($entry->status !== 'draft') {
                throw new OfxClassificationException('Lançamentos postados não podem ser classificados.');
            }

            if (! $wallet->suspense_account_id) {
                throw new OfxClassificationException('A wallet ativa não possui conta "A classificar" definida.');
            }

            $lines = $entry->lines()
                ->lockForUpdate()
                ->get();

            $bankLines = $lines->filter(
                fn (JournalLine $line) => (int) $line->chart_of_account_id === (int) $bankAccount->chart_of_account_id,
            );

            if ($bankLines->count() !== 1) {
                throw new OfxClassificationException('O lançamento deve possuir exatamente uma linha da conta bancária informada.');
            }

            /** @var JournalLine $bankLine */
            $bankLine = $bankLines->first();

            $isOfxOriginLine = BankStatementImportTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->where('bank_account_id', $bankAccount->id)
                ->where('journal_entry_id', $entry->id)
                ->where('journal_line_id', $bankLine->id)
                ->whereIn('status', ['imported', 'skipped_duplicate'])
                ->exists();

            if (! $isOfxOriginLine) {
                throw new OfxClassificationException('A linha bancária informada não é a linha de origem desta importação OFX.');
            }

            $classificationLines = $lines->reject(
                fn (JournalLine $line) => (int) $line->id === (int) $bankLine->id,
            );

            if ($classificationLines->count() !== 1) {
                throw new OfxClassificationException('O lançamento deve possuir exatamente uma linha de classificação editável.');
            }

            $destinationAccount = ChartOfAccount::query()
                ->where('wallet_id', $wallet->id)
                ->find($dto->destinationAccountId);

            if (! $destinationAccount
                || (int) $destinationAccount->id === (int) $wallet->suspense_account_id
                || (int) $destinationAccount->id === (int) $bankAccount->chart_of_account_id
                || ! $destinationAccount->isPostingAllowed()
                || $destinationAccount->isSynthetic()
                || $destinationAccount->children()->exists()) {
                throw new OfxClassificationException('Selecione uma conta analítica válida da wallet ativa.');
            }

            /** @var JournalLine $classificationLine */
            $classificationLine = $classificationLines->first();
            $classificationLine->update([
                'chart_of_account_id' => $destinationAccount->id,
            ]);

            $entry->recalcBalance();

            if (! $entry->is_balanced) {
                throw new OfxClassificationException('A classificação deixou o lançamento desbalanceado.');
            }

            $entry->save();

            if ($dto->shouldPost) {
                return $this->postJournalEntry->handle($entry);
            }

            return $entry->fresh(['lines.chartOfAccount']);
        });
    }
}
