<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bank_statement_imports', fn (Blueprint $table) => $table->enum('source', ['ofx', 'csv', 'pdf'])->default('ofx')->change());
        Schema::table('journal_entries', fn (Blueprint $table) => $table->enum('source', ['manual', 'ofx', 'csv', 'pdf', 'open_finance'])->default('manual')->change());
    }
    public function down(): void
    {
        Schema::table('bank_statement_imports', fn (Blueprint $table) => $table->enum('source', ['ofx'])->default('ofx')->change());
        Schema::table('journal_entries', fn (Blueprint $table) => $table->enum('source', ['manual', 'ofx', 'open_finance'])->default('manual')->change());
    }
};
