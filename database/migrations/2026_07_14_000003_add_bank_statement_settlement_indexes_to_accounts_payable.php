<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts_payable', function (Blueprint $table) {
            $table->unique(
                'payment_journal_entry_id',
                'accounts_payable_payment_journal_entry_unique',
            );
            $table->index(
                ['wallet_id', 'status', 'amount_cents', 'due_date'],
                'accounts_payable_settlement_candidates_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('accounts_payable', function (Blueprint $table) {
            $table->dropUnique('accounts_payable_payment_journal_entry_unique');
            $table->dropIndex('accounts_payable_settlement_candidates_idx');
        });
    }
};
