<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PostJournalEntry
{
    public function __construct(private readonly AssessJournalEntryPostingReadiness $readiness) {}

    /**
     * Faz o "post" do lançamento:
     * - recalcula balanceamento
     * - impede post se não estiver balanceado
     * - opcionalmente impede post se ainda existir linha em "A classificar"
     */
    public function handle(JournalEntry $entry, bool $requireFullyClassified = true): JournalEntry
    {
        return DB::transaction(function () use ($entry, $requireFullyClassified) {
            $entry->load('wallet', 'lines');

            if ($entry->status === 'posted') {
                return $entry;
            }

            $wallet = $entry->wallet;

            if (! $wallet) {
                throw new RuntimeException('Lançamento sem wallet vinculada.');
            }

            if ($requireFullyClassified) {
                $readiness = $this->readiness->handle($wallet, $entry);
                if (! $readiness->ready) {
                    throw new RuntimeException('Não é possível postar: '.$readiness->reason);
                }
            }

            // Recalcula balanceamento
            $entry->recalcBalance();

            if (! $entry->is_balanced) {
                throw new RuntimeException(
                    'Não é possível postar: lançamento não está balanceado. Diferença: ' .
                    $entry->balance_diff_cents . ' centavos.'
                );
            }

            // Verifica se ainda existe linha em "A classificar"
            if ($requireFullyClassified && $wallet->suspense_account_id) {
                $hasSuspenseLine = $entry->lines()
                    ->where('chart_of_account_id', $wallet->suspense_account_id)
                    ->exists();

                if ($hasSuspenseLine) {
                    throw new RuntimeException(
                        'Não é possível postar: o lançamento ainda possui valor em "A classificar".'
                    );
                }
            }

            $entry->status = 'posted';
            $entry->posted_at = now();
            $entry->save();

            return $entry->fresh(['lines']);
        });
    }
}
