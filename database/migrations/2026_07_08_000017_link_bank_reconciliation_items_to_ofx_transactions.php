<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_reconciliation_statement_items', function (Blueprint $table) {
            $table->foreignId('bank_statement_import_transaction_id')
                ->nullable()
                ->after('bank_reconciliation_id')
                ->constrained('bank_statement_import_transactions')
                ->nullOnDelete();

            $table->unique('bank_statement_import_transaction_id', 'bank_reconciliation_statement_ofx_unique');
        });
    }

    public function down(): void
    {
        Schema::table('bank_reconciliation_statement_items', function (Blueprint $table) {
            $table->dropUnique('bank_reconciliation_statement_ofx_unique');
            $table->dropConstrainedForeignId('bank_statement_import_transaction_id');
        });
    }
};
