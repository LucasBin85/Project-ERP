<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_cards', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('liability_account_id')
                ->constrained('chart_of_accounts')
                ->restrictOnDelete();

            $table->foreignId('parent_card_id')
                ->nullable()
                ->constrained('credit_cards')
                ->nullOnDelete();

            $table->string('name');
            $table->string('issuer_name');
            $table->enum('network', ['visa', 'mastercard', 'elo', 'amex', 'hipercard', 'other'])->default('other');
            $table->enum('card_type', ['main', 'additional', 'virtual'])->default('main');
            $table->string('holder_name')->nullable();
            $table->string('last_four', 4)->nullable();
            $table->unsignedTinyInteger('closing_day');
            $table->unsignedTinyInteger('due_day');
            $table->unsignedTinyInteger('best_purchase_day');
            $table->bigInteger('credit_limit_cents')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['wallet_id', 'name']);
            $table->index(['wallet_id', 'is_active']);
            $table->index(['parent_card_id', 'card_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_cards');
    }
};
