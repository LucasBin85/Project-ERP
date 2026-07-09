<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_invoices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('credit_card_id')
                ->constrained('credit_cards')
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('reference_year');
            $table->unsignedTinyInteger('reference_month');
            $table->date('starts_at');
            $table->date('closes_at');
            $table->date('due_at');
            $table->bigInteger('total_cents')->default(0);
            $table->bigInteger('paid_cents')->default(0);
            $table->bigInteger('balance_cents')->default(0);
            $table->enum('status', ['open', 'closed', 'partial', 'paid', 'overdue', 'cancelled'])->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['credit_card_id', 'reference_year', 'reference_month'], 'credit_card_invoice_unique_reference');
            $table->index(['wallet_id', 'status', 'due_at']);
            $table->index(['credit_card_id', 'closes_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_invoices');
    }
};
