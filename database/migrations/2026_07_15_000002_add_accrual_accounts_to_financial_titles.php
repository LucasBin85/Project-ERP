<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts_payable', function (Blueprint $table) {
            $table->foreignId('payable_account_id')->nullable()->after('wallet_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('provision_journal_entry_id')->nullable()->after('expense_account_id')->constrained('journal_entries')->nullOnDelete();
        });
        Schema::table('accounts_receivable', function (Blueprint $table) {
            $table->foreignId('receivable_account_id')->nullable()->after('wallet_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('provision_journal_entry_id')->nullable()->after('revenue_account_id')->constrained('journal_entries')->nullOnDelete();
        });

        DB::table('accounts_payable')->orderBy('id')->each(function ($title) {
            $accountId = DB::table('chart_of_accounts')->where('wallet_id', $title->wallet_id)
                ->where('financial_group', 'accounts_payable')->where('allows_posting', true)->orderBy('code')->value('id');
            if ($accountId) {
                DB::table('accounts_payable')->where('id', $title->id)->update(['payable_account_id' => $accountId]);
            }
        });
        DB::table('accounts_receivable')->orderBy('id')->each(function ($title) {
            $accountId = DB::table('chart_of_accounts')->where('wallet_id', $title->wallet_id)
                ->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->orderBy('code')->value('id');
            if ($accountId) {
                DB::table('accounts_receivable')->where('id', $title->id)->update(['receivable_account_id' => $accountId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts_payable', function (Blueprint $table) {
            $table->dropConstrainedForeignId('provision_journal_entry_id');
            $table->dropConstrainedForeignId('payable_account_id');
        });
        Schema::table('accounts_receivable', function (Blueprint $table) {
            $table->dropConstrainedForeignId('provision_journal_entry_id');
            $table->dropConstrainedForeignId('receivable_account_id');
        });
    }
};
