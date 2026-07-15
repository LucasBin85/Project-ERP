<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts_receivable', function (Blueprint $table) {
            $table->unique('receipt_journal_entry_id', 'accounts_receivable_receipt_journal_entry_unique');
            $table->index(['wallet_id', 'status', 'amount_cents', 'due_date'], 'accounts_receivable_settlement_candidates_idx');
        });
    }

    public function down(): void
    {
        Schema::table('accounts_receivable', function (Blueprint $table) {
            $table->dropUnique('accounts_receivable_receipt_journal_entry_unique');
            $table->dropIndex('accounts_receivable_settlement_candidates_idx');
        });
    }
};
