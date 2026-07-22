<?php

namespace App\Services\Financial;

use App\DTOs\Financial\BulkPostOfxEntriesResultDTO;
use App\Models\BankAccount;
use App\Models\BankStatementImportTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Wallet;
use App\Services\Accounting\PostJournalEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class BulkPostOfxDraftEntries
{
    /**
     * Income and payment movements stay in draft until the future AP/AR linking
     * flow can attach them without creating a duplicate journal entry.
     */
    private const RESERVED_FOR_AP_AR = [OfxOperationTypePolicy::PAYMENT];

    public function __construct(
        private readonly PostJournalEntry $postJournalEntry,
        private readonly FindMatchingOfxJournalLine $matchingJournalLines,
        private readonly OfxOperationTypePolicy $operationTypes,
    ) {}

    public function execute(
        Wallet $wallet,
        BankAccount $bankAccount,
        string $startDate,
        string $endDate,
    ): BulkPostOfxEntriesResultDTO {
        $this->validateContext($wallet, $bankAccount, $startDate, $endDate);

        $entryIds = JournalEntry::query()
            ->where('wallet_id', $wallet->id)
            ->whereIn('source', OfxOperationTypePolicy::STATEMENT_IMPORT_SOURCES)
            ->where('status', 'draft')
            ->whereDate('entry_date', '>=', $startDate)
            ->whereDate('entry_date', '<=', $endDate)
            ->whereHas('lines', fn ($query) => $query
                ->where('chart_of_account_id', $bankAccount->chart_of_account_id))
            ->orderBy('entry_date')
            ->orderBy('id')
            ->pluck('id');

        $posted = 0;
        $skippedItems = [];
        $errorItems = [];

        foreach ($entryIds as $entryId) {
            try {
                $outcome = DB::transaction(fn () => $this->processEntry(
                    wallet: $wallet,
                    bankAccount: $bankAccount,
                    entryId: (int) $entryId,
                ));

                if ($outcome['status'] === 'posted') {
                    $posted++;

                    continue;
                }

                $skippedItems[] = [
                    'journal_entry_id' => (int) $entryId,
                    'reason' => $outcome['reason'],
                ];
            } catch (Throwable $exception) {
                if (! $exception instanceof RuntimeException
                    && ! $exception instanceof InvalidArgumentException) {
                    report($exception);
                }

                $errorItems[] = [
                    'journal_entry_id' => (int) $entryId,
                    'message' => $exception instanceof RuntimeException
                        || $exception instanceof InvalidArgumentException
                            ? $exception->getMessage()
                            : 'Não foi possível postar este lançamento importado.',
                ];
            }
        }

        return new BulkPostOfxEntriesResultDTO(
            posted: $posted,
            skipped: count($skippedItems),
            errors: count($errorItems),
            skippedItems: $skippedItems,
            errorItems: $errorItems,
        );
    }

    /** @return array{status: 'posted'|'skipped', reason?: string} */
    private function processEntry(
        Wallet $wallet,
        BankAccount $bankAccount,
        int $entryId,
    ): array {
        $entry = JournalEntry::query()
            ->whereKey($entryId)
            ->where('wallet_id', $wallet->id)
            ->lockForUpdate()
            ->first();

        if (! $entry || ! in_array($entry->source, OfxOperationTypePolicy::STATEMENT_IMPORT_SOURCES, true) || $entry->status !== 'draft') {
            return $this->skipped('O lançamento não está mais disponível como rascunho importado.');
        }

        $lines = $entry->lines()
            ->with('chartOfAccount')
            ->lockForUpdate()
            ->get();
        $bankLines = $lines->where('chart_of_account_id', $bankAccount->chart_of_account_id);

        if ($bankLines->count() !== 1) {
            return $this->skipped('A linha bancária do lançamento não pôde ser identificada de forma única.');
        }

        /** @var JournalLine $bankLine */
        $bankLine = $bankLines->first();
        $auditTransaction = $this->auditTransaction($wallet, $bankAccount, $entry);

        if (! $auditTransaction) {
            return $this->skipped('O lançamento não possui uma transação importada de auditoria válida.');
        }

        if (! $this->auditMatchesBankLine($auditTransaction, $entry, $bankLine)) {
            return $this->skipped('A transação importada não corresponde à linha bancária do lançamento.');
        }

        $operationType = trim((string) $auditTransaction->operation_type);

        if ($operationType === '') {
            return $this->skipped('Selecione o tipo da operação antes da postagem em massa.');
        }

        if (! in_array($operationType, $this->operationTypes->codes(), true)) {
            return $this->skipped('O tipo selecionado para a operação importada não é válido.');
        }

        if (! in_array($auditTransaction->direction, [
            OfxOperationTypePolicy::DIRECTION_IN,
            OfxOperationTypePolicy::DIRECTION_OUT,
        ], true) || ! $this->operationTypes->isOperationTypeAllowedForDirection(
            $operationType,
            $auditTransaction->direction,
        )) {
            return $this->skipped('O tipo selecionado não é compatível com a direção do movimento bancário.');
        }

        if (in_array($operationType, self::RESERVED_FOR_AP_AR, true)) {
            return $this->skipped(
                'Pagamentos ficam pendentes até a vinculação ou criação da conta a pagar.',
            );
        }

        if (! $this->operationTypes->supportsClassification($operationType)) {
            return $this->skipped('O tipo selecionado ainda não possui classificação contábil postável.');
        }

        if ($wallet->suspense_account_id && $lines->contains(
            fn (JournalLine $line) => (int) $line->chart_of_account_id === (int) $wallet->suspense_account_id,
        )) {
            return $this->skipped('O lançamento ainda possui valor em "A classificar".');
        }

        $counterpartLines = $lines
            ->reject(fn (JournalLine $line) => (int) $line->id === (int) $bankLine->id)
            ->values();

        if (! $auditTransaction->classification_account_id || $counterpartLines->count() !== 1) {
            return $this->skipped('O lançamento não possui uma classificação contábil única e explícita.');
        }

        /** @var JournalLine $counterpartLine */
        $counterpartLine = $counterpartLines->first();

        if ((int) $counterpartLine->chart_of_account_id
            !== (int) $auditTransaction->classification_account_id
            || ! $counterpartLine->chartOfAccount
            || ! $this->operationTypes->isAccountAllowed(
                wallet: $wallet,
                bankAccount: $bankAccount,
                operationType: $operationType,
                account: $counterpartLine->chartOfAccount,
            )) {
            return $this->skipped('A classificação selecionada não é válida para o tipo da operação importada.');
        }

        $invalidLine = $lines->first(function (JournalLine $line) use ($wallet) {
            $account = $line->chartOfAccount;

            return (int) $line->amount_cents <= 0
                || ! $account
                || (int) $account->wallet_id !== (int) $wallet->id
                || ! $account->isPostingAllowed()
                || $account->children()->exists();
        });

        if ($invalidLine) {
            return $this->skipped('Todas as linhas devem usar contas analíticas e lançáveis da wallet ativa.');
        }

        $debits = (int) $lines->where('type', 'debit')->sum('amount_cents');
        $credits = (int) $lines->where('type', 'credit')->sum('amount_cents');

        if ($debits <= 0 || $credits <= 0 || $debits !== $credits) {
            return $this->skipped('O lançamento não está balanceado.');
        }

        if (! in_array($auditTransaction->resolution, [null, 'created', 'kept'], true)) {
            return $this->skipped('O vínculo desta transação importada ainda não permite postagem em massa.');
        }

        if ($auditTransaction->resolution !== 'kept') {
            $matches = $this->matchingJournalLines->candidates(
                wallet: $wallet,
                bankAccount: $bankAccount,
                entryDate: $entry->entry_date->toDateString(),
                amountCents: (int) $bankLine->amount_cents,
                direction: $bankLine->type === 'debit' ? 'in' : 'out',
                lockForUpdate: true,
            );

            if ($matches->isNotEmpty()) {
                return $this->skipped(
                    $matches->count() === 1
                        ? 'Existe um possível vínculo manual pendente de decisão.'
                        : 'Existem vínculos manuais ambíguos pendentes de decisão.',
                );
            }
        }

        $this->postJournalEntry->handle($entry);

        return ['status' => 'posted'];
    }

    private function auditTransaction(
        Wallet $wallet,
        BankAccount $bankAccount,
        JournalEntry $entry,
    ): ?BankStatementImportTransaction {
        $baseQuery = BankStatementImportTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('bank_account_id', $bankAccount->id)
            ->where('journal_entry_id', $entry->id);

        $transaction = (clone $baseQuery)
            ->where('status', 'imported')
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if ($transaction) {
            return $transaction;
        }

        return $baseQuery
            ->where('status', 'skipped_duplicate')
            ->whereNull('resolution')
            ->latest('id')
            ->lockForUpdate()
            ->first();
    }

    private function auditMatchesBankLine(
        BankStatementImportTransaction $transaction,
        JournalEntry $entry,
        JournalLine $bankLine,
    ): bool {
        $expectedDirection = $bankLine->type === 'debit' ? 'in' : 'out';

        return (! $transaction->journal_line_id
                || (int) $transaction->journal_line_id === (int) $bankLine->id)
            && (int) $transaction->amount_cents === (int) $bankLine->amount_cents
            && $transaction->direction === $expectedDirection
            && $transaction->posted_at?->toDateString() === $entry->entry_date?->toDateString();
    }

    /** @return array{status: 'skipped', reason: string} */
    private function skipped(string $reason): array
    {
        return [
            'status' => 'skipped',
            'reason' => $reason,
        ];
    }

    private function validateContext(
        Wallet $wallet,
        BankAccount $bankAccount,
        string $startDate,
        string $endDate,
    ): void {
        if ((int) $bankAccount->wallet_id !== (int) $wallet->id || ! $bankAccount->is_active) {
            throw new InvalidArgumentException('A conta bancária deve estar ativa e pertencer à wallet atual.');
        }

        $start = CarbonImmutable::parse($startDate)->startOfDay();
        $end = CarbonImmutable::parse($endDate)->startOfDay();

        if ($end->lt($start)) {
            throw new InvalidArgumentException('A data final deve ser posterior ou igual à data inicial.');
        }
    }
}
