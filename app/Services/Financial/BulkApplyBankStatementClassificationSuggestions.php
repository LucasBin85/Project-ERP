<?php

namespace App\Services\Financial;

use App\DTOs\Financial\OfxClassificationDTO;
use App\Models\BankAccount;
use App\Models\JournalEntry;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class BulkApplyBankStatementClassificationSuggestions
{
    public function __construct(
        private readonly SuggestBankStatementClassification $suggestions,
        private readonly ClassifyOfxDraftEntry $classifier,
    ) {}

    /** @param list<array{journal_entry_id:int, rule_id?:int|null}> $items */
    public function execute(Wallet $wallet, BankAccount $bankAccount, array $items): array
    {
        abort_unless((int) $bankAccount->wallet_id === (int) $wallet->id, 404);
        $result = ['applied' => 0, 'ignored' => 0, 'failed' => 0, 'items' => []];

        foreach ($items as $requested) {
            $entryId = (int) $requested['journal_entry_id'];
            try {
                $outcome = DB::transaction(function () use ($wallet, $bankAccount, $requested, $entryId) {
                    $entry = JournalEntry::query()->where('wallet_id', $wallet->id)->lockForUpdate()->find($entryId);
                    if (! $entry) return $this->ignored($entryId, 'Lançamento não encontrado na wallet ativa.');
                    if ($entry->status !== 'draft') return $this->ignored($entryId, 'Lançamento já postado.');
                    if (! in_array($entry->source, ['ofx', 'csv', 'pdf'], true)) return $this->ignored($entryId, 'Origem não elegível para classificação em lote.');

                    $bankLine = $entry->lines()->where('chart_of_account_id', $bankAccount->chart_of_account_id)->first();
                    if (! $bankLine) return $this->ignored($entryId, 'Lançamento não pertence a esta conta bancária.');
                    $hasSuspense = $wallet->suspense_account_id && $entry->lines()->where('chart_of_account_id', $wallet->suspense_account_id)->exists();
                    if (! $hasSuspense) return $this->ignored($entryId, 'Lançamento já classificado.');

                    $suggestion = $this->suggestions->execute($wallet, $bankAccount, $bankLine);
                    $expectedRule = filled($requested['rule_id'] ?? null) ? (int) $requested['rule_id'] : null;
                    if (! $suggestion || $suggestion['status'] !== 'suggested') {
                        return $expectedRule ? $this->failed($entryId, 'A sugestão mudou ou a regra não está mais válida.') : $this->ignored($entryId, 'Sem sugestão única e válida.');
                    }
                    if ($expectedRule && (int) $suggestion['rule_id'] !== $expectedRule) return $this->failed($entryId, 'A regra sugerida mudou desde a abertura do Extrato.');
                    if (! $suggestion['can_apply']) return $this->ignored($entryId, 'A sugestão exige seleção ou criação manual de título.');

                    $this->classifier->execute($wallet, $bankAccount, $entry, new OfxClassificationDTO(
                        $suggestion['operation_type'], $suggestion['chart_of_account_id'], false,
                    ));
                    return ['status' => 'applied', 'journal_entry_id' => $entryId, 'message' => 'Sugestão aplicada.'];
                }, 3);
            } catch (\Throwable $exception) {
                report($exception);
                $outcome = $this->failed($entryId, 'Não foi possível aplicar a sugestão; revise o lançamento e a regra.');
            }
            $result[$outcome['status']]++;
            $result['items'][] = $outcome;
        }

        return $result;
    }

    private function ignored(int $entryId, string $message): array { return ['status' => 'ignored', 'journal_entry_id' => $entryId, 'message' => $message]; }
    private function failed(int $entryId, string $message): array { return ['status' => 'failed', 'journal_entry_id' => $entryId, 'message' => $message]; }
}
