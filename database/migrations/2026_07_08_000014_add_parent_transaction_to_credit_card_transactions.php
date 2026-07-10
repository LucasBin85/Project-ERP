<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_card_transactions', function (Blueprint $table) {
            $table->foreignId('parent_transaction_id')
                ->nullable()
                ->after('id')
                ->constrained('credit_card_transactions')
                ->nullOnDelete();

            $table->index(['parent_transaction_id', 'installment_number'], 'cc_transactions_parent_installment_idx');
        });
    }

    public function down(): void
    {
        Schema::table('credit_card_transactions', function (Blueprint $table) {
            $table->dropIndex('cc_transactions_parent_installment_idx');
            $table->dropConstrainedForeignId('parent_transaction_id');
        });
    }
};
