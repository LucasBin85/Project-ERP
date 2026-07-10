<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_imports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('bank_account_id')
                ->constrained('bank_accounts')
                ->restrictOnDelete();

            $table->enum('source', ['ofx'])->default('ofx');
            $table->string('original_filename');
            $table->string('file_hash');
            $table->date('statement_started_at')->nullable();
            $table->date('statement_ended_at')->nullable();
            $table->unsignedInteger('total_transactions')->default(0);
            $table->unsignedInteger('imported_transactions')->default(0);
            $table->unsignedInteger('skipped_duplicates')->default(0);
            $table->bigInteger('total_in_cents')->default(0);
            $table->bigInteger('total_out_cents')->default(0);
            $table->enum('status', ['completed', 'failed'])->default('completed');
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['wallet_id', 'source', 'created_at']);
            $table->index(['bank_account_id', 'created_at']);
            $table->unique(['wallet_id', 'bank_account_id', 'source', 'file_hash'], 'bank_statement_import_unique_file');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_imports');
    }
};
