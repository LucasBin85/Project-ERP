<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('credit_card_id')
                ->constrained('credit_cards')
                ->restrictOnDelete();

            $table->foreignId('expense_account_id')
                ->constrained('chart_of_accounts')
                ->restrictOnDelete();

            $table->foreignId('journal_entry_id')
                ->constrained('journal_entries')
                ->restrictOnDelete();

            $table->date('purchase_date');
            $table->string('merchant_name');
            $table->string('description');
            $table->bigInteger('amount_cents');
            $table->unsignedSmallInteger('installments_total')->default(1);
            $table->unsignedSmallInteger('installment_number')->default(1);
            $table->enum('status', ['posted', 'cancelled'])->default('posted');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['wallet_id', 'purchase_date']);
            $table->index(['credit_card_id', 'purchase_date']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_transactions');
    }
};
