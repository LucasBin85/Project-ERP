<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_card_transactions', function (Blueprint $table) {
            $table->foreignId('expense_account_id')->nullable()->change();
            $table->string('source', 20)->default('manual')->after('journal_entry_id');
            $table->string('external_id')->nullable()->after('source');
            $table->string('import_hash', 64)->nullable()->after('external_id');
            $table->unique(['credit_card_id', 'import_hash'], 'cc_transactions_import_unique');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE credit_card_transactions MODIFY status ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft'");
            DB::statement("ALTER TABLE credit_card_payments MODIFY status ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft'");
        }

        DB::table('chart_of_accounts')->where('code', '2.1')->whereIn('name', ['Contas a Pagar', 'Fornecedores'])
            ->update(['name' => 'Fornecedores e Contas a Pagar']);
        DB::table('chart_of_accounts')->where('code', '2.2')->where('name', 'Cartões de Crédito')
            ->update(['name' => 'Cartões de Crédito a Pagar']);
    }

    public function down(): void
    {
        Schema::table('credit_card_transactions', function (Blueprint $table) {
            $table->dropUnique('cc_transactions_import_unique');
            $table->dropColumn(['source', 'external_id', 'import_hash']);
            $table->foreignId('expense_account_id')->nullable(false)->change();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE credit_card_transactions MODIFY status ENUM('posted','cancelled') NOT NULL DEFAULT 'posted'");
            DB::statement("ALTER TABLE credit_card_payments MODIFY status ENUM('posted','cancelled') NOT NULL DEFAULT 'posted'");
        }
    }
};
