<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliation_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bank_reconciliation_id')
                ->constrained('bank_reconciliations')
                ->cascadeOnDelete();

            $table->foreignId('journal_line_id')
                ->constrained('journal_lines')
                ->restrictOnDelete();

            $table->bigInteger('amount_cents');
            $table->timestamps();

            $table->unique(['bank_reconciliation_id', 'journal_line_id']);
            $table->index('journal_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_items');
    }
};
