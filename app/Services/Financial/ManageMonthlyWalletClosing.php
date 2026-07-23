<?php

namespace App\Services\Financial;

use App\Models\MonthlyWalletClosing;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageMonthlyWalletClosing
{
    public function __construct(private readonly BuildMonthlyWalletClosingSummary $summary) {}

    public function close(Wallet $wallet, User $user, int $year, int $month, ?string $note): MonthlyWalletClosing
    {
        return DB::transaction(function () use ($wallet, $user, $year, $month, $note) {
            $summary = $this->summary->execute($wallet, $year, $month);
            $reasons = self::blockers($summary);
            if ($reasons !== []) {
                throw ValidationException::withMessages(['closing' => $reasons]);
            }

            $closing = MonthlyWalletClosing::query()->lockForUpdate()->firstOrNew([
                'wallet_id' => $wallet->id, 'year' => $year, 'month' => $month,
            ]);
            if ($closing->status === 'closed') {
                throw ValidationException::withMessages(['closing' => 'Este mês já está fechado.']);
            }
            $closing->fill(['period_start' => $summary['period']['start_date'], 'period_end' => $summary['period']['end_date'],
                'status' => 'closed', 'closed_at' => now(), 'closed_by' => $user->id, 'close_note' => $note,
                'snapshot_json' => ['period' => $summary['period'], 'summary' => $summary['summary'], 'banks' => $summary['banks'],
                    'payables' => $summary['payables'], 'receivables' => $summary['receivables'], 'accounting' => $summary['accounting']]]);
            $closing->save();

            return $closing;
        });
    }

    public function reopen(Wallet $wallet, User $user, int $year, int $month, string $reason): MonthlyWalletClosing
    {
        $closing = MonthlyWalletClosing::query()->where('wallet_id', $wallet->id)->where('year', $year)->where('month', $month)->firstOrFail();
        if ($closing->status !== 'closed') {
            throw ValidationException::withMessages(['reopen_reason' => 'Este mês não está fechado.']);
        }
        $closing->update(['status' => 'reopened', 'reopened_at' => now(), 'reopened_by' => $user->id, 'reopen_reason' => $reason]);

        return $closing;
    }

    public static function blockers(array $summary): array
    {
        $counts = collect($summary['banks'])->reduce(fn (array $total, array $bank) => [
            'classification' => $total['classification'] + ($bank['counts']['pending_classification'] ?? 0),
            'links' => $total['links'] + ($bank['counts']['pending_links'] ?? 0),
            'transfers' => $total['transfers'] + ($bank['counts']['pending_transfers'] ?? 0),
            'inconsistencies' => $total['inconsistencies'] + ($bank['counts']['inconsistencies'] ?? 0),
        ], ['classification' => 0, 'links' => 0, 'transfers' => 0, 'inconsistencies' => 0]);
        $reasons = [];
        if ($counts['classification'] || $summary['accounting']['unclassified']) {
            $reasons[] = 'Existem lançamentos pendentes de classificação.';
        }
        if ($counts['links']) {
            $reasons[] = 'Existem vínculos pendentes.';
        }
        if ($counts['transfers']) {
            $reasons[] = 'Existem transferências pendentes.';
        }
        if ($counts['inconsistencies']) {
            $reasons[] = 'Existem inconsistências bancárias.';
        }
        if ($summary['accounting']['draft_ready']) {
            $reasons[] = 'Existem drafts prontos para contabilizar.';
        }
        if ($summary['accounting']['draft_incomplete']) {
            $reasons[] = 'Existem drafts incompletos.';
        }
        if ($summary['accounting']['unbalanced']) {
            $reasons[] = 'Existem lançamentos desbalanceados.';
        }

        if (collect($summary['cards'] ?? [])->contains(
            fn (array $card) => in_array($card['status'], ['open', 'partial', 'divergent'], true)
        )) {
            $reasons[] = 'Existem faturas de cartao em aberto, vencidas ou parcialmente pagas.';
        }

        return array_values(array_unique($reasons));
    }
}
