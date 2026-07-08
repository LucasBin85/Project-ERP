<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transfers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('from_bank_account_id')
                ->constrained('bank_accounts')
                ->restrictOnDelete();

            $table->foreignId('to_bank_account_id')
                ->constrained('bank_accounts')
                ->restrictOnDelete();

            $table->foreignId('journal_entry_id')
                ->constrained('journal_entries')
                ->restrictOnDelete();

            $table->date('transfer_date');
            $table->bigInteger('amount_cents');
            $table->string('description');
            $table->enum('status', ['posted', 'cancelled'])->default('posted');

            $table->timestamps();

            $table->index(['wallet_id', 'transfer_date']);
            $table->index(['from_bank_account_id', 'transfer_date']);
            $table->index(['to_bank_account_id', 'transfer_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transfers');
    }
};
