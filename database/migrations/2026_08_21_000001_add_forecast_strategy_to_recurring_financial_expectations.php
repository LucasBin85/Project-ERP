<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_financial_expectations', function (Blueprint $table) {
            $table->string('forecast_strategy')->nullable();
        });

        DB::table('recurring_financial_expectations')
            ->where('amount_mode', 'variable')
            ->update(['forecast_strategy' => 'mean_last_3']);
    }

    public function down(): void
    {
        Schema::table('recurring_financial_expectations', function (Blueprint $table) {
            $table->dropColumn('forecast_strategy');
        });
    }
};
