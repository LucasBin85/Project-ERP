<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_card_invoices', function (Blueprint $table) {
            $table->enum('status', ['predicted', 'open', 'closed', 'partial', 'paid', 'overdue', 'cancelled'])
                ->default('open')->change();
        });
    }

    public function down(): void
    {
        DB::table('credit_card_invoices')->where('status', 'predicted')->update(['status' => 'open']);
        Schema::table('credit_card_invoices', function (Blueprint $table) {
            $table->enum('status', ['open', 'closed', 'partial', 'paid', 'overdue', 'cancelled'])
                ->default('open')->change();
        });
    }
};
