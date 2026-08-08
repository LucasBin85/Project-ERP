<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_card_invoices', function (Blueprint $table) {
            $table->boolean('is_projection')->default(false)->after('status')->index();
            $table->timestamp('imported_at')->nullable()->after('is_projection');
        });
    }

    public function down(): void
    {
        Schema::table('credit_card_invoices', function (Blueprint $table) {
            $table->dropColumn(['is_projection', 'imported_at']);
        });
    }
};
