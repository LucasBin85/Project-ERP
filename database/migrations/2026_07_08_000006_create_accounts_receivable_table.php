<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts_receivable', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('revenue_account_id')
                ->constrained('chart_of_accounts')
                ->restrictOnDelete();

            $table->foreignId('bank_account_id')
                ->nullable()
                ->constrained('bank_accounts')
                ->nullOnDelete();

            $table->foreignId('receipt_journal_entry_id')
                ->nullable()
                ->constrained('journal_entries')
                ->nullOnDelete();

            $table->string('customer_name');
            $table->string('description');
            $table->date('due_date');
            $table->date('received_at')->nullable();
            $table->bigInteger('amount_cents');
            $table->enum('status', ['pending', 'received', 'cancelled'])->default('pending');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['wallet_id', 'status', 'due_date']);
            $table->index(['wallet_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts_receivable');
    }
};
