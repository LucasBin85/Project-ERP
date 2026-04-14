<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();

            // origem do lançamento
            $table->enum('source', ['manual', 'ofx', 'open_finance'])->default('manual');

            // id externo para evitar duplicar import (ex.: id da transação no OFX/OpenFinance)
            $table->string('external_id')->nullable();

            $table->date('entry_date');
            $table->string('description')->nullable();

            // draft = pode existir pendente de classificação; posted = "fechado" (validado/balanceado)
            $table->enum('status', ['draft', 'posted'])->default('draft');
            $table->timestamp('posted_at')->nullable();

            // campos úteis para dashboard/checagem rápida
            $table->boolean('is_balanced')->default(false);

            // debit - credit (em centavos). 0 => ok
            $table->bigInteger('balance_diff_cents')->default(0);

            $table->timestamps();

            $table->index(['wallet_id', 'entry_date']);
            $table->index(['wallet_id', 'status']);

            // Se external_id existir, evita duplicatas por origem
            $table->unique(['wallet_id', 'source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};