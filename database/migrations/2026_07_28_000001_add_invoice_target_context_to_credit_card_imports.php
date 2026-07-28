<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_card_invoices', function (Blueprint $table) {
            $table->date('nominal_due_at')->nullable()->after('closes_at');
        });
        DB::table('credit_card_invoices')->whereNull('nominal_due_at')->update([
            'nominal_due_at' => DB::raw('due_at'),
        ]);
        foreach (DB::table('credit_card_invoices')->get(['id', 'nominal_due_at']) as $invoice) {
            $effective = CarbonImmutable::parse($invoice->nominal_due_at);
            while ($effective->isWeekend()) {
                $effective = $effective->addDay();
            }
            DB::table('credit_card_invoices')->where('id', $invoice->id)->update([
                'due_at' => $effective->toDateString(),
            ]);
        }

        Schema::table('credit_card_transactions', function (Blueprint $table) {
            $table->string('statement_file_hash', 64)->nullable()->after('import_hash');
            $table->index(['credit_card_id', 'statement_file_hash'], 'cc_transactions_statement_file_idx');
        });

        $groups = DB::table('credit_card_transactions')
            ->where('source', '!=', 'manual')
            ->whereNotNull('credit_card_invoice_id')
            ->selectRaw('wallet_id, source, created_at, COUNT(DISTINCT credit_card_invoice_id) invoice_count')
            ->groupBy('wallet_id', 'source', 'created_at')
            ->havingRaw('COUNT(DISTINCT credit_card_invoice_id) > 1')
            ->get();

        foreach ($groups as $group) {
            $transactions = DB::table('credit_card_transactions')
                ->where('wallet_id', $group->wallet_id)
                ->where('source', $group->source)
                ->where('created_at', $group->created_at)
                ->get();
            $invoices = DB::table('credit_card_invoices')
                ->whereIn('id', $transactions->pluck('credit_card_invoice_id')->unique())
                ->orderBy('reference_year')
                ->orderBy('reference_month')
                ->get();
            $references = $invoices->map(fn ($invoice) => ((int) $invoice->reference_year * 12) + (int) $invoice->reference_month);
            $adjacent = $references->values()->every(
                fn (int $reference, int $index) => $index === 0 || $reference === $references[$index - 1] + 1
            );

            if ($invoices->count() < 2
                || ! $adjacent
                || $invoices->pluck('credit_card_id')->unique()->count() !== 1
                || DB::table('credit_card_payments')->whereIn('credit_card_invoice_id', $invoices->pluck('id'))->exists()) {
                continue;
            }

            $target = $invoices->last();
            DB::table('credit_card_transactions')
                ->whereIn('id', $transactions->pluck('id'))
                ->update(['credit_card_invoice_id' => $target->id]);

            $this->refreshInvoice($target->id);
            foreach ($invoices->where('id', '!=', $target->id) as $invoice) {
                $this->refreshInvoice($invoice->id);
                if (! DB::table('credit_card_transactions')->where('credit_card_invoice_id', $invoice->id)->exists()) {
                    DB::table('credit_card_invoices')->where('id', $invoice->id)->delete();
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('credit_card_transactions', function (Blueprint $table) {
            $table->dropIndex('cc_transactions_statement_file_idx');
            $table->dropColumn('statement_file_hash');
        });
        Schema::table('credit_card_invoices', function (Blueprint $table) {
            $table->dropColumn('nominal_due_at');
        });
    }

    private function refreshInvoice(int $invoiceId): void
    {
        $total = (int) DB::table('credit_card_transactions')
            ->where('credit_card_invoice_id', $invoiceId)
            ->whereIn('status', ['draft', 'posted'])
            ->sum('amount_cents');
        $paid = (int) DB::table('credit_card_payments')
            ->where('credit_card_invoice_id', $invoiceId)
            ->whereIn('status', ['draft', 'posted'])
            ->sum('amount_cents');
        DB::table('credit_card_invoices')->where('id', $invoiceId)->update([
            'total_cents' => $total,
            'paid_cents' => $paid,
            'balance_cents' => $total - $paid,
        ]);
    }
};
