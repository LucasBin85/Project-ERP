<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_import_transactions', function (Blueprint $table) {
            $table->foreignId('classification_account_id')
                ->nullable()
                ->after('journal_line_id');

            $table->foreign('classification_account_id', 'bs_import_tx_classification_account_fk')
                ->references('id')
                ->on('chart_of_accounts')
                ->nullOnDelete();

            $table->string('transaction_hash', 64)
                ->nullable()
                ->after('external_id')
                ->index('bank_statement_import_transaction_hash_idx');

            $table->string('operation_type')
                ->nullable()
                ->after('direction');

            $table->string('resolution')
                ->nullable()
                ->after('status')
                ->index('bank_statement_import_transaction_resolution_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_import_transactions', function (Blueprint $table) {
            $table->dropForeign('bs_import_tx_classification_account_fk');
            $table->dropColumn('classification_account_id');
            $table->dropIndex('bank_statement_import_transaction_hash_idx');
            $table->dropIndex('bank_statement_import_transaction_resolution_idx');
            $table->dropColumn([
                'transaction_hash',
                'operation_type',
                'resolution',
            ]);
        });
    }
};
