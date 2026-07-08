<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliation_statement_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bank_reconciliation_id')
                ->constrained('bank_reconciliations')
                ->cascadeOnDelete();

            $table->foreignId('journal_line_id')
                ->nullable()
                ->constrained('journal_lines')
                ->nullOnDelete();

            $table->date('transaction_date');
            $table->string('description');
            $table->bigInteger('amount_cents');
            $table->enum('status', ['pending', 'reconciled'])->default('pending');
            $table->timestamps();

            $table->index(['bank_reconciliation_id', 'status']);
            $table->index(['journal_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_statement_items');
    }
};
