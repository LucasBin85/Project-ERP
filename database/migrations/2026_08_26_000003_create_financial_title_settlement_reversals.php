<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_title_settlement_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_payable_id')->nullable()->constrained('accounts_payable')->cascadeOnDelete();
            $table->foreignId('account_receivable_id')->nullable()->constrained('accounts_receivable')->cascadeOnDelete();
            $table->foreignId('settlement_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->unsignedBigInteger('settlement_journal_entry_id_snapshot')->unique();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->date('settlement_entry_date');
            $table->bigInteger('settlement_amount_cents');
            $table->enum('mode', ['draft_void', 'posted_reversal']);
            $table->date('reversal_date')->nullable();
            $table->timestamp('reversed_at');
            $table->foreignId('reversed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->timestamps();
            $table->index(['wallet_id', 'reversed_at']);
            $table->index(['account_payable_id', 'reversed_at']);
            $table->index(['account_receivable_id', 'reversed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_title_settlement_reversals');
    }
};
