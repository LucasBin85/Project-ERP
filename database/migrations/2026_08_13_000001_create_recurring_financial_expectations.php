<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_financial_expectations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['payable', 'receivable']);
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description');
            $table->enum('frequency', ['monthly', 'quarterly', 'semiannual', 'annual']);
            $table->unsignedSmallInteger('interval_months')->default(1);
            $table->unsignedTinyInteger('due_day');
            $table->enum('amount_mode', ['fixed', 'variable']);
            $table->unsignedBigInteger('expected_amount_cents')->nullable();
            $table->foreignId('default_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->enum('status', ['active', 'inactive']);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'status']);
            $table->index(['wallet_id', 'type']);
            $table->index(['starts_on', 'ends_on']);
        });

        Schema::create('recurring_financial_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recurring_financial_expectation_id')
                ->constrained('recurring_financial_expectations')
                ->cascadeOnDelete();
            $table->date('period_date');
            $table->date('due_date');
            $table->unsignedBigInteger('expected_amount_cents')->nullable();
            $table->unsignedBigInteger('actual_amount_cents')->nullable();
            $table->enum('status', ['confirmed', 'skipped']);
            $table->foreignId('account_payable_id')->nullable()->constrained('accounts_payable')->nullOnDelete();
            $table->foreignId('account_receivable_id')->nullable()->constrained('accounts_receivable')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['recurring_financial_expectation_id', 'period_date'],
                'recurring_expectation_period_unique'
            );
            $table->index(['wallet_id', 'period_date']);
            $table->index(['wallet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_financial_occurrences');
        Schema::dropIfExists('recurring_financial_expectations');
    }
};
