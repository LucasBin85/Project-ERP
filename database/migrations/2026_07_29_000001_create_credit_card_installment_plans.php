<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('credit_card_transactions')) {
            Schema::table('credit_card_transactions', function (Blueprint $table) {
                $table->foreignId('journal_entry_id')->nullable()->change();
            });
        }

        if (! Schema::hasTable('credit_card_installment_plans')) {
            Schema::create('credit_card_installment_plans', function (Blueprint $table) {
                $table->id();
                $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
                $table->foreignId('main_credit_card_id')->constrained('credit_cards')->restrictOnDelete();
                $table->foreignId('child_credit_card_id')->nullable()->constrained('credit_cards')->nullOnDelete();
                $table->string('description_base');
                $table->string('normalized_description');
                $table->unsignedSmallInteger('total_installments');
                $table->unsignedSmallInteger('first_known_installment');
                $table->unsignedSmallInteger('recognized_from_installment');
                $table->unsignedSmallInteger('recognized_to_installment');
                $table->bigInteger('original_total_cents')->nullable();
                $table->bigInteger('recognized_total_cents');
                $table->bigInteger('installment_amount_cents')->nullable();
                $table->foreignId('classification_account_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();
                $table->foreignId('recognition_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
                $table->date('recognition_date')->nullable();
                $table->boolean('started_before_erp')->default(false);
                $table->string('status', 32)->default('pending_confirmation');
                $table->string('source', 32)->default('statement');
                $table->json('metadata_json')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['wallet_id', 'main_credit_card_id', 'status'], 'cc_installment_plans_scope_idx');
                $table->index(['wallet_id', 'normalized_description', 'total_installments'], 'cc_installment_plans_match_idx');
            });
        }

        if (! Schema::hasTable('credit_card_installment_plan_items')) {
            Schema::create('credit_card_installment_plan_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('plan_id')->constrained('credit_card_installment_plans')->cascadeOnDelete();
                $table->unsignedSmallInteger('installment_number');
                $table->bigInteger('amount_cents');
                $table->unsignedSmallInteger('expected_invoice_year')->nullable();
                $table->unsignedTinyInteger('expected_invoice_month')->nullable();
                $table->date('expected_due_at')->nullable();
                $table->foreignId('credit_card_invoice_id')->nullable()->constrained('credit_card_invoices')->nullOnDelete();
                $table->foreignId('credit_card_purchase_id')->nullable()->constrained('credit_card_transactions')->nullOnDelete();
                $table->string('status', 32)->default('expected');
                $table->string('source', 32)->default('plan');
                $table->timestamp('matched_at')->nullable();
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->unique(['plan_id', 'installment_number'], 'cc_installment_plan_item_unique');
                $table->index(['expected_invoice_year', 'expected_invoice_month', 'status'], 'cc_installment_expected_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_installment_plan_items');
        Schema::dropIfExists('credit_card_installment_plans');
    }
};
