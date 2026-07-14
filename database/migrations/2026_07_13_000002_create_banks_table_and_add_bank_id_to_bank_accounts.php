<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique();
            $table->string('name');
            $table->string('short_name');
            $table->string('ispb', 8)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->foreignId('bank_id')
                ->nullable()
                ->after('chart_of_account_id')
                ->constrained('banks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_id');
        });

        Schema::dropIfExists('banks');
    }
};
