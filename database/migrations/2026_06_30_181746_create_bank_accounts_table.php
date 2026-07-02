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
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('chart_of_account_id')
                ->constrained('chart_of_accounts')
                ->restrictOnDelete();

            $table->string('name');
            $table->string('bank_name')->nullable();
            $table->string('bank_code')->nullable();
            $table->string('agency')->nullable();
            $table->string('account_number')->nullable();

            $table->enum('account_type', [
                'checking',
                'savings',
                'investment',
                'cash',
                'other',
            ])->default('checking');

            $table->integer('opening_balance_cents')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['wallet_id', 'name']);
            $table->unique(['wallet_id', 'chart_of_account_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
