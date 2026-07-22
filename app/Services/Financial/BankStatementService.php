<?php

namespace App\Services\Financial;

use App\DTOs\Financial\BankStatementDTO;
use App\DTOs\Financial\BankStatementFiltersDTO;
use App\Models\BankAccount;
use App\Models\BankAccountTransfer;
use App\Models\BankReconciliationItem;
use App\Models\BankReconciliationStatementItem;
use App\Models\BankStatementImportTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Wallet;
use App\Services\Accounting\AssessJournalEntryPostingReadiness;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BankStatementService
{
    public function __construct(
        private readonly FindMatchingOfxJournalLine $matchingJournalLines,
        private readonly FindMatchingOfxTransferEntries $matchingTransfers,
        private readonly OfxOperationTypePolicy $operationTypes,
        private readonly AssessJournalEntryPostingReadiness $postingReadiness,
        private readonly SuggestBankStatementClassification $classificationSuggestions,
    ) {}

    public function build(Wallet $wallet, BankStatementFiltersDTO $filters): BankStatementDTO
    {
        if (! $filters->isReady()) {
            return new BankStatementDTO(
                filters: $filters,
                ready: false,
                bankAccount: null,
                openingBalanceCents: 0,
                totalInflowsCents: 0,
                totalOutflowsCents: 0,
                closingBalanceCents: 0,
                transactions: collect(),
            );
        }

        $bankAccount = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->with('chartOfAccount')
            ->findOrFail($filters->bankAccountId);

        $openingBalanceCents = $this->calculateOpeningBalance($wallet, $bankAccount, $filters);
        $periodLines = $this->periodLines($wallet, $bankAccount, $filters);
        $ofxTransactionsByLineId = $this->ofxTransactionsByLineId($wallet, $bankAccount, $periodLines);
        $ofxOriginLineIds = $ofxTransactionsByLineId->keys();
        $reconciledLineIds = $this->reconciledLineIds($periodLines->pluck('id'));
        $ofxValidatedLineIds = $this->ofxValidatedLineIds($wallet, $bankAccount, $periodLines);
        $transfersByEntryId = BankAccountTransfer::query()
            ->with(['fromBankAccount:id,name', 'toBankAccount:id,name'])
            ->where('wallet_id', $wallet->id)->whereIn('journal_entry_id', $periodLines->pluck('journal_entry_id'))
            ->get()->keyBy('journal_entry_id');

        $runningBalance = $openingBalanceCents;
        $totalInflowsCents = 0;
        $totalOutflowsCents = 0;

        $transactions = $periodLines
            ->map(function (JournalLine $line) use (
                &$runningBalance,
                &$totalInflowsCents,
                &$totalOutflowsCents,
                $ofxOriginLineIds,
                $ofxValidatedLineIds,
                $reconciledLineIds,
                $wallet,
                $bankAccount,
                $ofxTransactionsByLineId,
                $transfersByEntryId,
            ) {
                $entry = $line->journalEntry;
                $amountCents = (int) $line->amount_cents;
                $inflowCents = $line->type === 'debit' ? $amountCents : 0;
                $outflowCents = $line->type === 'credit' ? $amountCents : 0;

                $totalInflowsCents += $inflowCents;
                $totalOutflowsCents += $outflowCents;
                $runningBalance += $inflowCents;
                $runningBalance -= $outflowCents;

                $classification = $this->classification($wallet, $line);
                $auditTransaction = $ofxTransactionsByLineId->get((int) $line->id);
                $transfer = $transfersByEntryId->get((int) $entry?->id);
                $transferCandidates = $transfer
                    ? $this->matchingTransfers->candidates($transfer, $bankAccount)
                    : collect();
                $linkedAccountPayable = $entry?->settledAccountPayable;
                $linkedAccountReceivable = $entry?->settledAccountReceivable;
                $canEditOfx = in_array($entry?->source, ['ofx', 'csv', 'pdf'], true)
                    && $entry?->status === 'draft'
                    && ! $transfer
                    && $ofxOriginLineIds->contains((int) $line->id)
                    && $classification['is_editable']
                    && ! $linkedAccountPayable
                    && ! $linkedAccountReceivable;
                $match = $this->ofxMatch(
                    wallet: $wallet,
                    bankAccount: $bankAccount,
                    bankLine: $line,
                    auditTransaction: $auditTransaction,
                    canEdit: $canEditOfx,
                );
                $operationType = $transfer ? OfxOperationTypePolicy::TRANSFER : $auditTransaction?->operation_type;
                $direction = $line->type === 'debit'
                    ? OfxOperationTypePolicy::DIRECTION_IN
                    : OfxOperationTypePolicy::DIRECTION_OUT;
                $workflowStatus = $this->workflowStatus(
                    wallet: $wallet,
                    entry: $entry,
                    classificationStatus: $classification['status'],
                    operationType: $operationType,
                    direction: $direction,
                    matchStatus: $match['status'],
                    hasLinkedPayable: (bool) $linkedAccountPayable,
                    hasLinkedReceivable: (bool) $linkedAccountReceivable,
                );
                $operationSupportsClassification = $operationType
                    && in_array($operationType, $this->operationTypes->codes(), true)
                    && $this->operationTypes->supportsClassification($operationType);
                $suggestion = $classification['status'] === 'unclassified' && $canEditOfx && $match['status'] === 'none'
                    ? $this->classificationSuggestions->execute($wallet, $bankAccount, $line)
                    : null;

                return [
                    'id' => $line->id,
                    'date' => $entry?->entry_date,
                    'journal_entry_id' => $entry?->id,
                    'description' => $entry?->description ?: $line->memo,
                    'accounting_status' => $entry?->status,
                    'workflow_status' => $workflowStatus,
                    'source' => $entry?->source,
                    'source_label' => $transfer && ! $auditTransaction ? 'Transferência' : $this->sourceLabel($entry?->source),
                    'reconciliation_status' => $transfer && ! $auditTransaction
                        ? 'awaiting_counterpart_ofx'
                        : $this->reconciliationStatus(
                        $line,
                        $ofxValidatedLineIds,
                        $reconciledLineIds,
                    ),
                    'classification_status' => $classification['status'],
                    'classification_label' => $classification['label'],
                    'classification_account_id' => $classification['account_id'],
                    'classification_suggestion' => $suggestion,
                    'operation_type' => $operationType,
                    'allowed_operation_types' => $this->operationTypes
                        ->allowedOperationTypesForDirection($direction),
                    'can_edit_operation_type' => $canEditOfx && $match['status'] === 'none',
                    'can_classify' => $canEditOfx
                        && $match['status'] === 'none'
                        && $operationSupportsClassification,
                    'can_link_account_payable' => $canEditOfx
                        && $direction === OfxOperationTypePolicy::DIRECTION_OUT
                        && $operationType === OfxOperationTypePolicy::PAYMENT
                        && $classification['status'] === 'unclassified'
                        && $match['status'] === 'none'
                        && ! $linkedAccountPayable,
                    'linked_account_payable' => $linkedAccountPayable ? [
                        'id' => $linkedAccountPayable->id,
                        'description' => $linkedAccountPayable->description,
                        'payee_name' => $linkedAccountPayable->payee_name,
                        'status' => $linkedAccountPayable->status,
                        'show_url' => route('accounts-payable.show', $linkedAccountPayable),
                    ] : null,
                    'can_link_account_receivable' => $canEditOfx
                        && $direction === OfxOperationTypePolicy::DIRECTION_IN
                        && $operationType === OfxOperationTypePolicy::INCOME
                        && $classification['status'] === 'unclassified'
                        && $match['status'] === 'none'
                        && ! $linkedAccountReceivable,
                    'linked_account_receivable' => $linkedAccountReceivable ? [
                        'id' => $linkedAccountReceivable->id,
                        'description' => $linkedAccountReceivable->description,
                        'customer_name' => $linkedAccountReceivable->customer_name,
                        'status' => $linkedAccountReceivable->status,
                        'show_url' => route('accounts-receivable.show', $linkedAccountReceivable),
                    ] : null,
                    'match_status' => $match['status'],
                    'match_candidates' => $match['candidates'],
                    'match_resolution' => $auditTransaction?->resolution,
                    'transfer' => $transfer ? [
                        'id' => $transfer->id,
                        'status' => $transfer->validation_status,
                        'counterpart_name' => (int) $bankAccount->id === (int) $transfer->from_bank_account_id
                            ? $transfer->toBankAccount?->name : $transfer->fromBankAccount?->name,
                        'counterpart_statement_url' => route('bank-accounts.statement',
                            (int) $bankAccount->id === (int) $transfer->from_bank_account_id
                                ? $transfer->to_bank_account_id : $transfer->from_bank_account_id),
                        'match_status' => $transferCandidates->isEmpty()
                            ? 'none' : ($transferCandidates->count() === 1 ? 'unique' : 'ambiguous'),
                        'match_candidates' => $transferCandidates->map(fn ($candidate) => [
                            'audit_id' => $candidate->id,
                            'journal_entry_id' => $candidate->journal_entry_id,
                            'description' => $candidate->description,
                            'counterpart_name' => $candidate->bankAccount?->name,
                        ])->values()->all(),
                    ] : null,
                    'type' => $inflowCents > 0 ? 'inflow' : 'outflow',
                    'inflow_cents' => $inflowCents ?: null,
                    'outflow_cents' => $outflowCents ?: null,
                    'amount_cents' => $inflowCents > 0 ? $inflowCents : -$outflowCents,
                    'running_balance_cents' => $runningBalance,
                ];
            })
            ->when(
                $filters->search !== '',
                fn (Collection $transactions) => $transactions->filter(
                    fn (array $transaction) => Str::contains(
                        (string) ($transaction['description'] ?? ''),
                        $filters->search,
                        true,
                    ),
                ),
            )
            ->reverse()
            ->values();

        return new BankStatementDTO(
            filters: $filters,
            ready: true,
            bankAccount: $bankAccount,
            openingBalanceCents: $openingBalanceCents,
            totalInflowsCents: $totalInflowsCents,
            totalOutflowsCents: $totalOutflowsCents,
            closingBalanceCents: $runningBalance,
            transactions: $transactions,
        );
    }

    private function calculateOpeningBalance(Wallet $wallet, BankAccount $bankAccount, BankStatementFiltersDTO $filters): int
    {
        $lines = JournalLine::query()
            ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
            ->whereHas('journalEntry', function ($query) use ($wallet, $filters) {
                $query->where('wallet_id', $wallet->id)
                    ->whereDate('entry_date', '<', $filters->startDate);
            })
            ->get(['type', 'amount_cents']);

        return $this->calculateDebitBalance($lines);
    }

    private function periodLines(Wallet $wallet, BankAccount $bankAccount, BankStatementFiltersDTO $filters): Collection
    {
        return JournalLine::query()
            ->with([
                'journalEntry:id,wallet_id,entry_date,description,status,source',
                'journalEntry.settledAccountPayable:id,payment_journal_entry_id,payee_name,description,status',
                'journalEntry.settledAccountReceivable:id,receipt_journal_entry_id,customer_name,description,status',
                'journalEntry.lines:id,journal_entry_id,chart_of_account_id,type,amount_cents,memo',
                'journalEntry.lines.chartOfAccount:id,wallet_id,parent_id,code,name,type,financial_group,allows_posting',
                'journalEntry.lines.chartOfAccount.children:id,parent_id',
            ])
            ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
            ->whereHas('journalEntry', function ($query) use ($wallet, $filters) {
                $query->where('wallet_id', $wallet->id)
                    ->whereDate('entry_date', '>=', $filters->startDate)
                    ->whereDate('entry_date', '<=', $filters->endDate);
            })
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_lines.id')
            ->select('journal_lines.*')
            ->get();
    }

    private function reconciledLineIds(Collection $lineIds): Collection
    {
        $lineIds = $lineIds
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();

        if ($lineIds->isEmpty()) {
            return collect();
        }

        $statementItemIds = BankReconciliationStatementItem::query()
            ->whereIn('journal_line_id', $lineIds)
            ->pluck('journal_line_id');

        $reconciliationItemIds = BankReconciliationItem::query()
            ->whereIn('journal_line_id', $lineIds)
            ->pluck('journal_line_id');

        return $statementItemIds
            ->merge($reconciliationItemIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function ofxTransactionsByLineId(
        Wallet $wallet,
        BankAccount $bankAccount,
        Collection $lines,
    ): Collection {
        $linesById = $lines->keyBy(fn (JournalLine $line) => (int) $line->id);
        $linesByEntryId = $lines->groupBy(fn (JournalLine $line) => (int) $line->journal_entry_id);

        if ($linesById->isEmpty()) {
            return collect();
        }

        $lineIds = $linesById->keys();
        $entryIds = $linesByEntryId->keys();

        return BankStatementImportTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('bank_account_id', $bankAccount->id)
            ->where(function ($query) use ($lineIds, $entryIds) {
                $query->whereIn('journal_line_id', $lineIds)
                    ->orWhere(function ($query) use ($entryIds) {
                        $query->whereNull('journal_line_id')
                            ->whereIn('journal_entry_id', $entryIds);
                    });
            })
            ->where(function ($query) {
                $query->where('status', 'imported')
                    ->orWhere(function ($query) {
                        $query->where('status', 'skipped_duplicate')
                            ->whereNull('resolution');
                    });
            })
            ->orderByRaw("CASE WHEN status = 'imported' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->get([
                'id',
                'journal_entry_id',
                'journal_line_id',
                'posted_at',
                'amount_cents',
                'direction',
                'operation_type',
                'status',
                'resolution',
            ])
            ->map(fn (BankStatementImportTransaction $transaction) => [
                'line_id' => $this->auditBankLineId($transaction, $linesById, $linesByEntryId),
                'transaction' => $transaction,
            ])
            ->filter(fn (array $match) => $match['line_id'] !== null)
            ->uniqueStrict('line_id')
            ->mapWithKeys(fn (array $match) => [
                $match['line_id'] => $match['transaction'],
            ]);
    }

    private function auditBankLineId(
        BankStatementImportTransaction $transaction,
        Collection $linesById,
        Collection $linesByEntryId,
    ): ?int {
        if ($transaction->journal_line_id) {
            /** @var JournalLine|null $line */
            $line = $linesById->get((int) $transaction->journal_line_id);

            return $line && (int) $line->journal_entry_id === (int) $transaction->journal_entry_id
                ? (int) $line->id
                : null;
        }

        $expectedLineType = match ($transaction->direction) {
            'in' => 'debit',
            'out' => 'credit',
            default => null,
        };

        if (! $expectedLineType || ! $transaction->posted_at) {
            return null;
        }

        $candidates = $linesByEntryId
            ->get((int) $transaction->journal_entry_id, collect())
            ->filter(fn (JournalLine $line) => $line->type === $expectedLineType
                && (int) $line->amount_cents === (int) $transaction->amount_cents
                && $line->journalEntry?->entry_date?->toDateString() === $transaction->posted_at->toDateString());

        return $candidates->count() === 1
            ? (int) $candidates->first()->id
            : null;
    }

    private function ofxMatch(
        Wallet $wallet,
        BankAccount $bankAccount,
        JournalLine $bankLine,
        ?BankStatementImportTransaction $auditTransaction,
        bool $canEdit,
    ): array {
        if (! $canEdit || ! $auditTransaction || $auditTransaction->resolution === 'kept') {
            return ['status' => 'none', 'candidates' => []];
        }

        $entry = $bankLine->journalEntry;
        $candidates = $this->matchingJournalLines->candidates(
            wallet: $wallet,
            bankAccount: $bankAccount,
            entryDate: $entry->entry_date->toDateString(),
            amountCents: (int) $bankLine->amount_cents,
            direction: $bankLine->type === 'debit' ? 'in' : 'out',
        );

        if ($candidates->isEmpty()) {
            return ['status' => 'none', 'candidates' => []];
        }

        return [
            'status' => $candidates->count() === 1 ? 'unique' : 'ambiguous',
            'candidates' => $candidates
                ->map(fn (JournalLine $candidate) => [
                    'journal_entry_id' => $candidate->journal_entry_id,
                    'journal_line_id' => $candidate->id,
                    'date' => $candidate->journalEntry?->entry_date,
                    'description' => $candidate->journalEntry?->description,
                    'status' => $candidate->journalEntry?->status,
                ])
                ->values()
                ->all(),
        ];
    }

    private function ofxValidatedLineIds(Wallet $wallet, BankAccount $bankAccount, Collection $lines): Collection
    {
        $lineIds = $lines->pluck('id')->map(fn ($id) => (int) $id)->values();
        $entryIds = $lines->pluck('journal_entry_id')->map(fn ($id) => (int) $id)->unique()->values();

        if ($lineIds->isEmpty()) {
            return collect();
        }

        $transactions = BankStatementImportTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('bank_account_id', $bankAccount->id)
            ->where('status', 'imported')
            ->where(function ($query) use ($entryIds, $lineIds) {
                $query->whereIn('journal_line_id', $lineIds)
                    ->orWhere(function ($query) use ($entryIds) {
                        $query->whereNull('journal_line_id')
                            ->whereIn('journal_entry_id', $entryIds);
                    });
            })
            ->get([
                'journal_entry_id',
                'journal_line_id',
                'posted_at',
                'amount_cents',
                'direction',
            ]);

        return $transactions
            ->map(function (BankStatementImportTransaction $transaction) use ($lines) {
                $matchingLines = $lines->filter(function (JournalLine $line) use ($transaction) {
                    $expectedType = $transaction->direction === 'in' ? 'debit' : 'credit';
                    $isLinkedLine = $transaction->journal_line_id
                        ? (int) $line->id === (int) $transaction->journal_line_id
                        : (int) $line->journal_entry_id === (int) $transaction->journal_entry_id;

                    return $isLinkedLine
                        && $line->type === $expectedType
                        && (int) $line->amount_cents === (int) $transaction->amount_cents
                        && $line->journalEntry?->entry_date?->toDateString() === $transaction->posted_at?->toDateString();
                });

                return $matchingLines->count() === 1
                    ? (int) $matchingLines->first()->id
                    : null;
            })
            ->filter()
            ->unique()
            ->values();
    }

    private function reconciliationStatus(JournalLine $line, Collection $ofxValidatedLineIds, Collection $reconciledLineIds): string
    {
        if (in_array($line->journalEntry?->source, ['ofx', 'csv', 'pdf'], true)) {
            return 'reconciled_via_ofx';
        }

        if ($ofxValidatedLineIds->contains((int) $line->id) || $reconciledLineIds->contains((int) $line->id)) {
            return 'reconciled';
        }

        return 'pending';
    }

    private function classification(Wallet $wallet, JournalLine $bankLine): array
    {
        $entryLines = $bankLine->journalEntry?->lines ?? collect();
        $counterpartLines = $entryLines
            ->reject(fn (JournalLine $line) => (int) $line->id === (int) $bankLine->id)
            ->values();
        $isEditable = $entryLines
            ->where('chart_of_account_id', $bankLine->chart_of_account_id)
            ->count() === 1
            && $counterpartLines->count() === 1;

        $usesSuspenseAccount = $wallet->suspense_account_id
            && $counterpartLines->contains(
                fn (JournalLine $line) => (int) $line->chart_of_account_id === (int) $wallet->suspense_account_id,
            );

        if ($usesSuspenseAccount) {
            return [
                'status' => 'unclassified',
                'label' => 'A classificar',
                'account_id' => null,
                'is_editable' => $isEditable,
            ];
        }

        $labels = $counterpartLines
            ->map(fn (JournalLine $line) => $line->chartOfAccount?->name)
            ->filter()
            ->unique()
            ->values();

        return [
            'status' => $labels->isNotEmpty() ? 'classified' : 'unclassified',
            'label' => $labels->isNotEmpty() ? $labels->join(', ') : 'Não identificada',
            'account_id' => $isEditable
                ? (int) $counterpartLines->first()->chart_of_account_id
                : null,
            'is_editable' => $isEditable,
        ];
    }

    private function sourceLabel(?string $source): string
    {
        return match ($source) {
            'ofx' => 'OFX',
            'csv' => 'CSV',
            'pdf' => 'PDF',
            'open_finance' => 'Open Finance',
            'manual' => 'Manual',
            default => $source ? str($source)->headline()->toString() : 'Manual',
        };
    }

    private function workflowStatus(
        Wallet $wallet,
        ?JournalEntry $entry,
        string $classificationStatus,
        ?string $operationType,
        string $direction,
        string $matchStatus,
        bool $hasLinkedPayable,
        bool $hasLinkedReceivable,
    ): string {
        if ($entry?->status === 'posted') {
            return 'posted';
        }

        if ($operationType === OfxOperationTypePolicy::PAYMENT
            && $direction === OfxOperationTypePolicy::DIRECTION_OUT
            && ! $hasLinkedPayable) {
            return 'pending_link';
        }

        if ($operationType === OfxOperationTypePolicy::INCOME
            && $direction === OfxOperationTypePolicy::DIRECTION_IN
            && $classificationStatus === 'unclassified'
            && ! $hasLinkedReceivable) {
            return 'pending_link';
        }

        if ($matchStatus !== 'none') {
            return 'pending_link';
        }

        if ($entry && $this->postingReadiness->handle($wallet, $entry)->ready) {
            return 'ready_for_accounting';
        }

        return $classificationStatus === 'unclassified'
            ? 'pending_classification'
            : 'classified';
    }

    private function calculateDebitBalance(Collection $lines): int
    {
        return $lines->reduce(function (int $balance, JournalLine $line) {
            $amountCents = (int) $line->amount_cents;

            return $line->type === 'debit'
                ? $balance + $amountCents
                : $balance - $amountCents;
        }, 0);
    }
}
