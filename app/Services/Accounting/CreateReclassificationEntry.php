<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreateReclassificationEntry
{
    /**
     * Cria um novo journal_entry movendo saldo de "A classificar" -> conta destino,
     * SEM alterar o lançamento original (auditável).
     *
     * $splits = [
     *   ['chart_of_account_id' => 321, 'amount_cents' => 12050, 'memo' => 'Mercado'],
     *   ['chart_of_account_id' => 654, 'amount_cents' =>  5000, 'memo' => 'Padaria'],
     * ]
     *
     * Regra automática de tipo:
     * - Linha destino usa normal_balance da conta destino (debit|credit)
     * - Contrapartida na suspense é o oposto
     */
    public function handle(JournalEntry $originalEntry, array $splits, ?string $entryDate = null): JournalEntry
    {
        return DB::transaction(function () use ($originalEntry, $splits, $entryDate) {
            $originalEntry->load('wallet');

            if ($originalEntry->status !== 'posted') {
                throw new RuntimeException('Reclassificação auditável só para lançamentos POSTED.');
            }

            $wallet = $originalEntry->wallet;

            if (! $wallet->suspense_account_id) {
                throw new RuntimeException('Wallet sem suspense_account_id definido.');
            }

            $total = array_sum(array_map(fn ($s) => (int) ($s['amount_cents'] ?? 0), $splits));
            if ($total <= 0) {
                throw new RuntimeException('Splits inválidos (soma deve ser > 0).');
            }

            // Cria entry de reclassificação
            $reclassEntry = JournalEntry::create([
                'wallet_id' => $wallet->id,
                'source' => 'manual',
                'external_id' => null,
                'entry_date' => $entryDate ?? $originalEntry->entry_date->format('Y-m-d'),
                'description' => 'Reclassificação (origem entry #' . $originalEntry->id . ')',
                'status' => 'draft',
            ]);

            // Pré-carrega as contas destino para deduzir normal_balance
            $accountIds = array_values(array_unique(array_map(
                fn ($s) => (int) $s['chart_of_account_id'],
                $splits
            )));

            $accounts = ChartOfAccount::query()
                ->where('wallet_id', $wallet->id)
                ->whereIn('id', $accountIds)
                ->get()
                ->keyBy('id');

            foreach ($splits as $split) {
                $accountId = (int) ($split['chart_of_account_id'] ?? 0);
                $amount = (int) ($split['amount_cents'] ?? 0);

                if ($accountId <= 0 || $amount <= 0) {
                    throw new RuntimeException('Split inválido (chart_of_account_id e amount_cents são obrigatórios e > 0).');
                }

                /** @var ChartOfAccount|null $dest */
                $dest = $accounts->get($accountId);
                if (! $dest) {
                    throw new RuntimeException("Conta destino {$accountId} não pertence à wallet ou não existe.");
                }

                $destType = $dest->normal_balance; // debit|credit
                if (! in_array($destType, ['debit', 'credit'], true)) {
                    throw new RuntimeException("Conta destino {$accountId} sem normal_balance válido.");
                }

                $suspenseType = $destType === 'debit' ? 'credit' : 'debit';

                // Linha destino
                JournalLine::create([
                    'journal_entry_id' => $reclassEntry->id,
                    'chart_of_account_id' => $dest->id,
                    'type' => $destType,
                    'amount_cents' => $amount,
                    'memo' => $split['memo'] ?? 'Reclassificação',
                ]);

                // Contrapartida na suspense
                JournalLine::create([
                    'journal_entry_id' => $reclassEntry->id,
                    'chart_of_account_id' => $wallet->suspense_account_id,
                    'type' => $suspenseType,
                    'amount_cents' => $amount,
                    'memo' => 'Contrapartida - A classificar',
                ]);
            }

            $reclassEntry->recalcBalance();
            $reclassEntry->save();

            return $reclassEntry->fresh(['lines']);
        });
    }
}