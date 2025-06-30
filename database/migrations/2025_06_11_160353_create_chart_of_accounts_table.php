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

            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('chart_of_accounts')
                ->cascadeOnDelete();

            $table->string('code');
            $table->string('name');
            $table->enum('type', ['ativo', 'passivo', 'receita', 'despesa', 'patrimonio'])->default('ativo');
            $table->boolean('is_protected')->default(false);
            
            $table->timestamps();
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
