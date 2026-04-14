<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('journal_entry_id')
                ->constrained('journal_entries')
                ->cascadeOnDelete();

            // usa seu plano de contas
            $table->foreignId('chart_of_account_id')
                ->constrained('chart_of_accounts')
                ->restrictOnDelete();

            $table->enum('type', ['debit', 'credit']);

            // sempre positivo, em centavos (evita float)
            $table->bigInteger('amount_cents');

            $table->string('memo')->nullable();

            $table->timestamps();

            $table->index(['journal_entry_id', 'chart_of_account_id']);
            $table->index(['chart_of_account_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};