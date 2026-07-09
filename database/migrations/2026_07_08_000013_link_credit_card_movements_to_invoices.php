<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_card_transactions', function (Blueprint $table) {
            $table->foreignId('credit_card_invoice_id')
                ->nullable()
                ->after('credit_card_id')
                ->constrained('credit_card_invoices')
                ->nullOnDelete();

            $table->index(['credit_card_invoice_id', 'purchase_date'], 'cc_transactions_invoice_purchase_idx');
        });

        Schema::table('credit_card_payments', function (Blueprint $table) {
            $table->foreignId('credit_card_invoice_id')
                ->nullable()
                ->after('credit_card_id')
                ->constrained('credit_card_invoices')
                ->nullOnDelete();

            $table->index(['credit_card_invoice_id', 'payment_date'], 'cc_payments_invoice_payment_idx');
        });
    }

    public function down(): void
    {
        Schema::table('credit_card_payments', function (Blueprint $table) {
            $table->dropIndex('cc_payments_invoice_payment_idx');
            $table->dropConstrainedForeignId('credit_card_invoice_id');
        });

        Schema::table('credit_card_transactions', function (Blueprint $table) {
            $table->dropIndex('cc_transactions_invoice_purchase_idx');
            $table->dropConstrainedForeignId('credit_card_invoice_id');
        });
    }
};
