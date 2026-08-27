<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('reversal_of_journal_entry_id')->nullable()->after('external_id')
                ->constrained('journal_entries')->restrictOnDelete();
        });

        Schema::table('accounts_payable', function (Blueprint $table) {
            $table->foreignId('cancellation_journal_entry_id')->nullable()->unique()->after('payment_journal_entry_id')
                ->constrained('journal_entries')->restrictOnDelete();
        });

        Schema::table('accounts_receivable', function (Blueprint $table) {
            $table->foreignId('cancellation_journal_entry_id')->nullable()->unique()->after('receipt_journal_entry_id')
                ->constrained('journal_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounts_receivable', fn (Blueprint $table) => $table->dropConstrainedForeignId('cancellation_journal_entry_id'));
        Schema::table('accounts_payable', fn (Blueprint $table) => $table->dropConstrainedForeignId('cancellation_journal_entry_id'));
        Schema::table('journal_entries', fn (Blueprint $table) => $table->dropConstrainedForeignId('reversal_of_journal_entry_id'));
    }
};
