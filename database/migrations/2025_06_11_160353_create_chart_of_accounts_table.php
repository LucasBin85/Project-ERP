<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('chart_of_accounts')
                ->cascadeOnDelete();

            $table->string('code');
            $table->string('name');

            $table->enum('type', [
                'ativo',
                'passivo',
                'receita',
                'despesa',
                'patrimonio',
            ])->default('ativo');

            $table->enum('normal_balance', [
                'debit',
                'credit',
            ])->default('debit');

            // Conta estrutural criada automaticamente pelo sistema
            $table->boolean('is_system')->default(false);

            // Define se a conta pode receber lançamentos diretamente
            $table->boolean('allows_posting')->default(false);

            // Grupo usado na posição financeira
            $table->enum('financial_group', [
                'available',
                'investments',
                'accounts_receivable',
                'accounts_payable',
            ])->nullable();

            $table->timestamps();

            $table->unique(['wallet_id', 'code']);

            $table->index(['wallet_id', 'parent_id']);
            $table->index(['wallet_id', 'type']);
            $table->index(['wallet_id', 'normal_balance']);
            $table->index(['wallet_id', 'is_system']);
            $table->index(['wallet_id', 'allows_posting']);
            $table->index(['wallet_id', 'financial_group']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};