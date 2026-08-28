<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_title_settlement_reversals', function (Blueprint $table) {
            $table->dropUnique(['settlement_journal_entry_id_snapshot']);
            $table->foreignId('bank_statement_import_transaction_id')->nullable()->after('bank_account_id')
                ->constrained('bank_statement_import_transactions')->restrictOnDelete();
            $table->unsignedBigInteger('bank_journal_line_id_snapshot')->nullable()->after('bank_statement_import_transaction_id');
            $table->unsignedBigInteger('classification_account_id_snapshot')->nullable()->after('bank_journal_line_id_snapshot');
            $table->unsignedBigInteger('suspense_account_id_snapshot')->nullable()->after('classification_account_id_snapshot');
            $table->foreignId('classification_adjustment_journal_entry_id')->nullable()->after('reversal_journal_entry_id')
                ->constrained('journal_entries')->restrictOnDelete();
            $table->index(['bank_statement_import_transaction_id', 'reversed_at'], 'settlement_reversals_bank_import_reversed_idx');
        });

        Schema::table('financial_title_settlement_reversals', function (Blueprint $table) {
            $table->enum('mode', [
                'draft_void',
                'posted_reversal',
                'bank_draft_unlink',
                'bank_posted_reclassification',
            ])->change();
        });
    }

    public function down(): void
    {
        Schema::table('financial_title_settlement_reversals', function (Blueprint $table) {
            $table->enum('mode', ['draft_void', 'posted_reversal'])->change();
        });

        Schema::table('financial_title_settlement_reversals', function (Blueprint $table) {
            $table->dropIndex('settlement_reversals_bank_import_reversed_idx');
            $table->dropConstrainedForeignId('classification_adjustment_journal_entry_id');
            $table->dropConstrainedForeignId('bank_statement_import_transaction_id');
            $table->dropColumn([
                'bank_journal_line_id_snapshot',
                'classification_account_id_snapshot',
                'suspense_account_id_snapshot',
            ]);
            $table->unique('settlement_journal_entry_id_snapshot');
        });
    }
};
