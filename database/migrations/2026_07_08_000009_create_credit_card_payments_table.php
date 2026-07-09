<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('credit_card_id')
                ->constrained('credit_cards')
                ->restrictOnDelete();

            $table->foreignId('bank_account_id')
                ->constrained('bank_accounts')
                ->restrictOnDelete();

            $table->foreignId('journal_entry_id')
                ->constrained('journal_entries')
                ->restrictOnDelete();

            $table->date('payment_date');
            $table->bigInteger('amount_cents');
            $table->string('description');
            $table->enum('status', ['posted', 'cancelled'])->default('posted');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['wallet_id', 'payment_date']);
            $table->index(['credit_card_id', 'payment_date']);
            $table->index(['bank_account_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_payments');
    }
};
