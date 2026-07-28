<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_title_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['payable', 'receivable']);
            $table->enum('mode', ['single', 'installment'])->default('installment');
            $table->string('description');
            $table->string('counterparty');
            $table->bigInteger('total_amount_cents');
            $table->unsignedInteger('installment_count');
            $table->date('competence_date');
            $table->foreignId('provision_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->enum('status', ['pending', 'partially_settled', 'settled', 'cancelled'])->default('pending');
            $table->timestamps();
            $table->index(['wallet_id', 'type', 'status']);
        });

        Schema::table('accounts_payable', function (Blueprint $table) {
            $table->foreignId('series_id')->nullable()->after('wallet_id')->constrained('financial_title_series')->nullOnDelete();
            $table->unsignedInteger('installment_number')->nullable()->after('series_id');
            $table->unsignedInteger('installment_count')->default(1)->after('installment_number');
            $table->index(['series_id', 'installment_number']);
        });

        Schema::table('accounts_receivable', function (Blueprint $table) {
            $table->foreignId('series_id')->nullable()->after('wallet_id')->constrained('financial_title_series')->nullOnDelete();
            $table->unsignedInteger('installment_number')->nullable()->after('series_id');
            $table->unsignedInteger('installment_count')->default(1)->after('installment_number');
            $table->index(['series_id', 'installment_number']);
        });
    }

    public function down(): void
    {
        Schema::table('accounts_receivable', function (Blueprint $table) {
            $table->dropConstrainedForeignId('series_id');
            $table->dropColumn(['installment_number', 'installment_count']);
        });
        Schema::table('accounts_payable', function (Blueprint $table) {
            $table->dropConstrainedForeignId('series_id');
            $table->dropColumn(['installment_number', 'installment_count']);
        });
        Schema::dropIfExists('financial_title_series');
    }
};
