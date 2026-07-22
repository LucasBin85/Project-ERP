<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bank_statement_classification_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('match_text');
            $table->string('match_mode', 20)->default('contains');
            $table->string('direction', 10)->default('any');
            $table->string('operation_type', 30);
            $table->foreignId('chart_of_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('investment_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->integer('priority')->default(0);
            $table->timestamps();
            $table->index(['wallet_id', 'active', 'priority'], 'statement_rules_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_classification_rules');
    }
};
