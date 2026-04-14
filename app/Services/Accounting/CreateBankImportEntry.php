<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Wallet;
use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class CreateBankImportEntry
{
    /**
     * Cria um lançamento balanceado usando:
     * - Conta do Banco (chart_of_account_id = $bankAccountId)
     * - Conta transitória da wallet (wallet->suspense_account_id)
     *
     * Regras:
     * - amount_cents deve ser positivo
     * - direction: 'in' (entrada) ou 'out' (saída)
     *   * entrada: Banco DEBIT, Suspense CREDIT
     *   * saída:   Banco CREDIT, Suspense DEBIT
     */
    public function handle(
        Wallet $wallet,
        int $bankAccountId,
        int $amountCents,
        string $direction,              // 'in' | 'out'
        string $entryDate,              // 'YYYY-MM-DD'
        ?string $description = null,
        string $source = 'ofx',          // manual|ofx|open_finance
        ?string $externalId = null,
        bool $autoPostIfBalanced = false
    ): JournalEntry {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('amount_cents deve ser > 0');
        }

        if (!in_array($direction, ['in', 'out'], true)) {
            throw new InvalidArgumentException("direction inválido: use 'in' ou 'out'");
        }

        if (!$wallet->suspense_account_id) {
            throw new RuntimeException('Wallet não possui suspense_account_id definido (conta "A classificar")');
        }

        // Valida a conta bancária
        $bankAccount = ChartOfAccount::query()->findOrFail($bankAccountId);

        if ((int) $bankAccount->wallet_id !== (int) $wallet->id) {
            throw new RuntimeException('A conta bancária informada não pertence a esta wallet.');
        }

        if ((int) $bankAccount->id === (int) $wallet->suspense_account_id) {
            throw new RuntimeException('A conta bancária não pode ser a conta "A classificar".');
        }

        // Opcional: impor que conta de banco seja do tipo "ativo"
        if ($bankAccount->type !== 'ativo') {
            throw new RuntimeException('A conta bancária deve ser do tipo "ativo".');
        }

        // Determina tipos das linhas
        $bankType = $direction === 'in' ? 'debit' : 'credit';
        $suspenseType = $direction === 'in' ? 'credit' : 'debit';

        return DB::transaction(function () use (
            $wallet,
            $bankAccountId,
            $amountCents,
            $entryDate,
            $description,
            $source,
            $externalId,
            $bankType,
            $suspenseType,
            $autoPostIfBalanced
        ) {
            $entry = JournalEntry::create([
                'wallet_id' => $wallet->id,
                'source' => $source,
                'external_id' => $externalId,
                'entry_date' => $entryDate,
                'description' => $description,
                'status' => 'draft',
            ]);

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'chart_of_account_id' => $bankAccountId,
                'type' => $bankType,
                'amount_cents' => $amountCents,
                'memo' => 'Conta bancária',
            ]);

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'chart_of_account_id' => $wallet->suspense_account_id,
                'type' => $suspenseType,
                'amount_cents' => $amountCents,
                'memo' => 'A classificar',
            ]);

            // Atualiza flags de balanceamento
            $entry->recalcBalance();
            $entry->save();

            // Opcional: postar automaticamente se estiver balanceado
            if ($autoPostIfBalanced && $entry->is_balanced) {
                $entry->status = 'posted';
                $entry->posted_at = now();
                $entry->save();
            }

            return $entry->fresh(['lines']);
        });
    }
}