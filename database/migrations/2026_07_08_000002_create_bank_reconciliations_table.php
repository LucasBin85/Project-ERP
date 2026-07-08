<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('bank_account_id')
                ->constrained('bank_accounts')
                ->restrictOnDelete();

            $table->date('period_start');
            $table->date('period_end');

            $table->bigInteger('opening_balance_cents')->default(0);
            $table->bigInteger('statement_balance_cents')->default(0);
            $table->bigInteger('book_balance_cents')->default(0);
            $table->bigInteger('reconciled_balance_cents')->default(0);
            $table->bigInteger('difference_cents')->default(0);

            $table->enum('status', ['draft', 'completed'])->default('draft');
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['wallet_id', 'period_start', 'period_end']);
            $table->index(['bank_account_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
    }
};
