<?php

namespace App\Services\Financial;

use App\DTOs\Financial\OfxClassificationDTO;
use App\Exceptions\OfxClassificationException;
use App\Exceptions\OfxOperationTypeDirectionException;
use App\Models\BankAccount;
use App\Models\BankStatementImportTransaction;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Wallet;
use App\Services\Accounting\EnsureAccountingPeriodIsOpen;
use App\Services\Accounting\PostJournalEntry;
use Illuminate\Support\Facades\DB;
use Throwable;

class ClassifyOfxDraftEntry
{
    public function __construct(
        private readonly PostJournalEntry $postJournalEntry,
        private readonly OfxOperationTypePolicy $operationTypes,
        private readonly FindMatchingOfxJournalLine $matchingJournalLines,
        private readonly ClassifyBankStatementTransfer $classifyTransfer,
        private readonly EnsureAccountingPeriodIsOpen $periodGuard,
    ) {}

    public function execute(
        Wallet $wallet,
        BankAccount $bankAccount,
        JournalEntry $entry,
        OfxClassificationDTO $dto,
    ): JournalEntry {
        $this->periodGuard->handle($wallet, $entry->entry_date);

        return DB::transaction(function () use ($wallet, $bankAccount, $entry, $dto) {
            $entry = JournalEntry::query()
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->validateEntryContext($wallet, $bankAccount, $entry, $dto);

            $lines = $entry->lines()
                ->lockForUpdate()
                ->get();

            $bankLines = $lines->filter(
                fn (JournalLine $line) => (int) $line->chart_of_account_id === (int) $bankAccount->chart_of_account_id,
            );

            if ($bankLines->count() !== 1) {
                throw new OfxClassificationException(
                    'O lançamento deve possuir exatamente uma linha da conta bancária informada.',
                );
            }

            /** @var JournalLine $bankLine */
            $bankLine = $bankLines->first();
            $direction = $bankLine->type === 'debit'
                ? OfxOperationTypePolicy::DIRECTION_IN
                : OfxOperationTypePolicy::DIRECTION_OUT;

            try {
                $this->operationTypes->validateOperationTypeForDirection(
                    $dto->operationType,
                    $direction,
                );
            } catch (Throwable $exception) {
                throw new OfxOperationTypeDirectionException(
                    $exception->getMessage(),
                    previous: $exception,
                );
            }

            $auditTransaction = BankStatementImportTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->where('bank_account_id', $bankAccount->id)
                ->where('journal_entry_id', $entry->id)
                ->where(function ($query) use ($bankLine, $entry) {
                    $query->where('journal_line_id', $bankLine->id)
                        ->orWhere(function ($query) use ($bankLine, $entry) {
                            $query->whereNull('journal_line_id')
                                ->whereDate('posted_at', $entry->entry_date->toDateString())
                                ->where('amount_cents', $bankLine->amount_cents)
                                ->where('direction', $bankLine->type === 'debit' ? 'in' : 'out');
                        });
                })
                ->where(function ($query) {
                    $query->where('status', 'imported')
                        ->orWhere(function ($query) {
                            $query->where('status', 'skipped_duplicate')
                                ->whereNull('resolution');
                        });
                })
                ->where(function ($query) {
                    $query->whereNull('resolution')
                        ->orWhereIn('resolution', ['created', 'kept']);
                })
                ->orderByRaw("CASE WHEN status = 'imported' THEN 0 ELSE 1 END")
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $auditTransaction) {
                throw new OfxClassificationException(
                    'A linha bancária informada não é a linha de origem desta importação de extrato.',
                );
            }

            if (! $auditTransaction->journal_line_id) {
                $auditTransaction->journal_line_id = $bankLine->id;
            }

            if ($auditTransaction->resolution !== 'kept') {
                $candidates = $this->matchingJournalLines->candidates(
                    wallet: $wallet,
                    bankAccount: $bankAccount,
                    entryDate: $entry->entry_date->toDateString(),
                    amountCents: (int) $bankLine->amount_cents,
                    direction: $bankLine->type === 'debit' ? 'in' : 'out',
                    lockForUpdate: true,
                );

                if ($candidates->isNotEmpty()) {
                    throw new OfxClassificationException(
                        $candidates->count() === 1
                            ? 'Existe um lançamento manual compatível. Resolva o possível vínculo antes de classificar.'
                            : 'Existem vários lançamentos manuais compatíveis. Resolva o vínculo ambíguo antes de classificar.',
                    );
                }
            }

            $classificationLines = $lines->reject(
                fn (JournalLine $line) => (int) $line->id === (int) $bankLine->id,
            );

            if ($classificationLines->count() !== 1) {
                throw new OfxClassificationException(
                    'O lançamento deve possuir exatamente uma linha de classificação editável.',
                );
            }

            /** @var JournalLine $classificationLine */
            $classificationLine = $classificationLines->first();
            $auditTransaction->operation_type = $dto->operationType;

            if ($dto->destinationAccountId === null) {
                if ($dto->shouldPost) {
                    throw new OfxClassificationException('Selecione uma classificação antes de postar o lançamento.');
                }

                $this->keepOrResetCurrentClassification(
                    wallet: $wallet,
                    bankAccount: $bankAccount,
                    operationType: $dto->operationType,
                    classificationLine: $classificationLine,
                    auditTransaction: $auditTransaction,
                );

                $auditTransaction->save();

                return $entry->fresh(['lines.chartOfAccount']);
            }

            if ($dto->operationType === OfxOperationTypePolicy::TRANSFER) {
                if ($dto->shouldPost) {
                    throw new OfxClassificationException('Transferências devem ser postadas em Pendências Contábeis.');
                }

                return $this->classifyTransfer->execute($wallet, $bankAccount, $entry, $dto->destinationAccountId);
            }

            $destinationAccount = ChartOfAccount::query()->find($dto->destinationAccountId);

            if (! $destinationAccount) {
                throw new OfxClassificationException('A conta de classificação selecionada não existe.');
            }

            try {
                $this->operationTypes->validateAccount(
                    $wallet,
                    $bankAccount,
                    $dto->operationType,
                    $destinationAccount,
                );
            } catch (Throwable $exception) {
                throw new OfxClassificationException($exception->getMessage(), previous: $exception);
            }

            $classificationLine->update([
                'chart_of_account_id' => $destinationAccount->id,
                'memo' => 'Classificação do extrato: '.$destinationAccount->name,
            ]);

            $auditTransaction->classification_account_id = $destinationAccount->id;
            $auditTransaction->save();

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

    private function validateEntryContext(
        Wallet $wallet,
        BankAccount $bankAccount,
        JournalEntry $entry,
        OfxClassificationDTO $dto,
    ): void {
        if ((int) $bankAccount->wallet_id !== (int) $wallet->id
            || (int) $entry->wallet_id !== (int) $wallet->id) {
            throw new OfxClassificationException(
                'A conta bancária e o lançamento devem pertencer à wallet ativa.',
            );
        }

        if (! in_array($entry->source, OfxOperationTypePolicy::STATEMENT_IMPORT_SOURCES, true)) {
            throw new OfxClassificationException(
                'Somente lançamentos originados de arquivo de extrato podem ser classificados por esta ação.',
            );
        }

        if ($entry->status !== 'draft') {
            throw new OfxClassificationException('Lançamentos postados não podem ser classificados.');
        }

        if ($entry->settledAccountPayable()->exists()) {
            throw new OfxClassificationException(
                'O lançamento já está vinculado a uma conta a pagar e não pode ser reclassificado.',
            );
        }

        if ($entry->settledAccountReceivable()->exists()) {
            throw new OfxClassificationException(
                'O lançamento já está vinculado a uma conta a receber e não pode ser reclassificado.',
            );
        }

        if (! $wallet->suspense_account_id) {
            throw new OfxClassificationException(
                'A wallet ativa não possui conta "A classificar" definida.',
            );
        }

        if (! in_array($dto->operationType, $this->operationTypes->codes(), true)) {
            throw new OfxClassificationException('Selecione um tipo de operação válido.');
        }
    }

    private function keepOrResetCurrentClassification(
        Wallet $wallet,
        BankAccount $bankAccount,
        string $operationType,
        JournalLine $classificationLine,
        BankStatementImportTransaction $auditTransaction,
    ): void {
        if ((int) $classificationLine->chart_of_account_id === (int) $wallet->suspense_account_id) {
            $auditTransaction->classification_account_id = null;

            return;
        }

        $currentAccount = ChartOfAccount::query()->find($classificationLine->chart_of_account_id);

        if ($currentAccount && $this->operationTypes->isAccountAllowed(
            $wallet,
            $bankAccount,
            $operationType,
            $currentAccount,
        )) {
            $auditTransaction->classification_account_id = $currentAccount->id;

            return;
        }

        $classificationLine->update([
            'chart_of_account_id' => $wallet->suspense_account_id,
            'memo' => 'A classificar',
        ]);
        $auditTransaction->classification_account_id = null;
    }
}
