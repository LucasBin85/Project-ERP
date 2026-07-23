<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_wallet_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('status', ['open', 'closed', 'reopened'])->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('close_note')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->json('snapshot_json')->nullable();
            $table->timestamps();
            $table->unique(['wallet_id', 'year', 'month']);
            $table->index(['wallet_id', 'status', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_wallet_closings');
    }
};
