<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('document')->nullable();
            $table->foreignId('payable_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('default_expense_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['wallet_id', 'name']);
        });
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('document')->nullable();
            $table->foreignId('receivable_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('default_revenue_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['wallet_id', 'name']);
        });
        Schema::table('accounts_payable', fn (Blueprint $table) => $table->foreignId('supplier_id')->nullable()->after('wallet_id')->constrained()->nullOnDelete());
        Schema::table('accounts_receivable', fn (Blueprint $table) => $table->foreignId('customer_id')->nullable()->after('wallet_id')->constrained()->nullOnDelete());

        DB::table('accounts_payable')->whereNotNull('payable_account_id')->orderBy('id')->each(function ($title) {
            $id = DB::table('suppliers')->where('wallet_id', $title->wallet_id)->where('name', $title->payee_name)->value('id');
            if (! $id) {
                $id = DB::table('suppliers')->insertGetId(['wallet_id' => $title->wallet_id, 'name' => $title->payee_name, 'payable_account_id' => $title->payable_account_id, 'default_expense_account_id' => $title->expense_account_id, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('accounts_payable')->where('id', $title->id)->update(['supplier_id' => $id]);
        });
        DB::table('accounts_receivable')->whereNotNull('receivable_account_id')->orderBy('id')->each(function ($title) {
            $id = DB::table('customers')->where('wallet_id', $title->wallet_id)->where('name', $title->customer_name)->value('id');
            if (! $id) {
                $id = DB::table('customers')->insertGetId(['wallet_id' => $title->wallet_id, 'name' => $title->customer_name, 'receivable_account_id' => $title->receivable_account_id, 'default_revenue_account_id' => $title->revenue_account_id, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('accounts_receivable')->where('id', $title->id)->update(['customer_id' => $id]);
        });
    }

    public function down(): void
    {
        Schema::table('accounts_payable', fn (Blueprint $table) => $table->dropConstrainedForeignId('supplier_id'));
        Schema::table('accounts_receivable', fn (Blueprint $table) => $table->dropConstrainedForeignId('customer_id'));
        Schema::dropIfExists('customers');
        Schema::dropIfExists('suppliers');
    }
};
