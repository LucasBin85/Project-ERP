<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\ChartOfAccount;
//use App\Services\ChartOfAccountService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReclassifyDraftEntry
{
    /**
     * Troca a(s) linha(s) que estão em "A classificar" para outra conta,
     * mantendo o lançamento original e o balanceamento.
     *
     * Suporta split: você pode mandar 1 ou várias contas com valores.
     *
     * $splits = [
     *   ['chart_of_account_id' => 123, 'amount_cents' => 7000, 'memo' => 'Mercado'],
     *   ['chart_of_account_id' => 456, 'amount_cents' => 5050, 'memo' => 'Padaria'],
     * ]
     */


    public function handle(JournalEntry $entry, array $splits): JournalEntry
    {
        if ($entry->status === 'posted') {
            throw new RuntimeException(
                'Lançamentos postados não podem ser reclassificados.'
            );
        }
        return DB::transaction(function () use ($entry, $splits) {

            $entry->load('wallet', 'lines');

            if ($entry->status !== 'draft') {
                throw new RuntimeException('Só é permitido reclassificar por edição quando o entry está em draft.');
            }

            $wallet = $entry->wallet;

            if (! $wallet->suspense_account_id) {
                throw new RuntimeException('Wallet sem suspense_account_id definido.');
            }

            $suspenseLines = $entry->lines->where('chart_of_account_id', $wallet->suspense_account_id);

            if ($suspenseLines->isEmpty()) {
                throw new RuntimeException('Este lançamento não possui linha em "A classificar".');
            }

            /** @var JournalLine $suspenseLine */
            $suspenseLine = $suspenseLines->first();

            $totalSplit = array_sum(array_map(fn ($s) => (int) $s['amount_cents'], $splits));

            if ($totalSplit !== (int) $suspenseLine->amount_cents) {
                throw new RuntimeException('A soma dos splits deve ser igual ao valor da linha "A classificar".');
            }

            //$accountService = app(ChartOfAccountService::class);

            // 🔥 NOVA VALIDAÇÃO AQUI
            foreach ($splits as $split) {

                $account = ChartOfAccount::where('wallet_id', $wallet->id)
                    ->findOrFail((int) $split['chart_of_account_id']);

                //$accountService->validateForPosting($account);
                if (! $account->allows_posting) {
                    throw new RuntimeException(
                        "A conta {$account->code} - {$account->name} não permite lançamentos."
                    );
                }
            }

            // Remove suspense
            $suspenseLine->delete();

            // Cria novas linhas
            foreach ($splits as $split) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'chart_of_account_id' => (int) $split['chart_of_account_id'],
                    'type' => $suspenseLine->type,
                    'amount_cents' => (int) $split['amount_cents'],
                    'memo' => $split['memo'] ?? 'Reclassificação',
                ]);
            }

            $entry->recalcBalance();
            $entry->save();

            return $entry->fresh(['lines']);
        });
    }
}