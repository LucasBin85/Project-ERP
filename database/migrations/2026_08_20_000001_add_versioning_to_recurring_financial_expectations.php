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
            $table->foreignId('replaces_expectation_id')->nullable()->unique()
                ->after('id')->constrained('recurring_financial_expectations')->nullOnDelete();
            $table->date('schedule_anchor_date')->nullable()->after('starts_on');
        });

        DB::table('recurring_financial_expectations')
            ->whereNull('schedule_anchor_date')
            ->update(['schedule_anchor_date' => DB::raw('starts_on')]);
    }

    public function down(): void
    {
        Schema::table('recurring_financial_expectations', function (Blueprint $table) {
            $table->dropForeign(['replaces_expectation_id']);
            $table->dropUnique(['replaces_expectation_id']);
            $table->dropColumn(['replaces_expectation_id', 'schedule_anchor_date']);
        });
    }
};
