<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_import_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bank_statement_import_id')
                ->constrained('bank_statement_imports')
                ->cascadeOnDelete();

            $table->foreignId('wallet_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('bank_account_id')
                ->constrained('bank_accounts')
                ->restrictOnDelete();

            $table->foreignId('journal_entry_id')
                ->nullable()
                ->constrained('journal_entries')
                ->nullOnDelete();

            $table->string('external_id');
            $table->string('fit_id')->nullable();
            $table->date('posted_at');
            $table->string('description');
            $table->bigInteger('amount_cents');
            $table->enum('direction', ['in', 'out']);
            $table->enum('status', ['imported', 'skipped_duplicate', 'failed'])->default('imported');
            $table->json('raw_payload')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['wallet_id', 'bank_account_id', 'external_id'], 'bank_statement_import_transaction_external_idx');
            $table->index(['bank_statement_import_id', 'status']);
            $table->index(['bank_account_id', 'posted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_import_transactions');
    }
};
