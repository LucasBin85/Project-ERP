<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_account_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journal_entry_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('from_bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('to_bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('from_journal_line_id')->unique()->constrained('journal_lines')->restrictOnDelete();
            $table->foreignId('to_journal_line_id')->unique()->constrained('journal_lines')->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->date('transfer_date');
            $table->string('validation_status')->default('pending_counterpart_ofx');
            $table->foreignId('from_import_transaction_id')->nullable()->unique()->constrained('bank_statement_import_transactions')->nullOnDelete();
            $table->foreignId('to_import_transaction_id')->nullable()->unique()->constrained('bank_statement_import_transactions')->nullOnDelete();
            $table->timestamps();
            $table->index(['wallet_id', 'transfer_date', 'amount_cents'], 'bank_transfer_matching_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_account_transfers');
    }
};
