<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_cards', function (Blueprint $table) {
            $table->foreignId('bank_account_id')
                ->nullable()
                ->after('liability_account_id')
                ->constrained('bank_accounts')
                ->nullOnDelete();

            $table->index(['wallet_id', 'bank_account_id']);
        });
    }

    public function down(): void
    {
        Schema::table('credit_cards', function (Blueprint $table) {
            $table->dropIndex(['wallet_id', 'bank_account_id']);
            $table->dropConstrainedForeignId('bank_account_id');
        });
    }
};
