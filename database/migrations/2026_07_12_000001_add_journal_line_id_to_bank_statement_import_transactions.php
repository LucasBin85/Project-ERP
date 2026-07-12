<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_import_transactions', function (Blueprint $table) {
            $table->foreignId('journal_line_id')
                ->nullable()
                ->after('journal_entry_id')
                ->index()
                ->constrained('journal_lines')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_import_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_line_id');
        });
    }
};
