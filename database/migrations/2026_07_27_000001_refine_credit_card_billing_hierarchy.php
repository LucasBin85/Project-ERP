<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_cards', function (Blueprint $table) {
            $table->enum('card_type', ['main', 'physical', 'additional', 'virtual'])
                ->default('main')
                ->change();
        });

        $children = DB::table('credit_cards')
            ->whereIn('card_type', ['physical', 'additional', 'virtual'])
            ->whereNull('parent_card_id')
            ->whereNotNull('issuer_bank_id')
            ->get();

        foreach ($children as $child) {
            $parents = DB::table('credit_cards')
                ->where('wallet_id', $child->wallet_id)
                ->where('issuer_bank_id', $child->issuer_bank_id)
                ->where('card_type', 'main')
                ->whereNull('parent_card_id')
                ->get();

            if ($parents->count() === 1) {
                $this->consolidateChild($child, $parents->first());
            }
        }

        $linkedChildren = DB::table('credit_cards')
            ->whereNotNull('parent_card_id')
            ->get();

        foreach ($linkedChildren as $child) {
            $parent = DB::table('credit_cards')->where('id', $child->parent_card_id)->first();
            if ($parent
                && (int) $parent->wallet_id === (int) $child->wallet_id
                && (int) $parent->issuer_bank_id === (int) $child->issuer_bank_id) {
                $this->consolidateChild($child, $parent);
            }
        }

        $mainCards = DB::table('credit_cards')
            ->join('banks', 'banks.id', '=', 'credit_cards.issuer_bank_id')
            ->where('credit_cards.card_type', 'main')
            ->whereNull('credit_cards.parent_card_id')
            ->get([
                'credit_cards.id',
                'credit_cards.liability_account_id',
                'banks.short_name',
            ]);

        foreach ($mainCards as $card) {
            DB::table('chart_of_accounts')
                ->where('id', $card->liability_account_id)
                ->update(['name' => $card->short_name.' — Fatura']);
        }
    }

    public function down(): void
    {
        DB::table('credit_cards')->where('card_type', 'physical')->update(['card_type' => 'additional']);

        Schema::table('credit_cards', function (Blueprint $table) {
            $table->enum('card_type', ['main', 'additional', 'virtual'])
                ->default('main')
                ->change();
        });
    }

    private function consolidateChild(object $child, object $parent): void
    {
        $oldAccountId = (int) $child->liability_account_id;
        $mainAccountId = (int) $parent->liability_account_id;

        DB::table('credit_cards')->where('id', $child->id)->update([
            'parent_card_id' => $parent->id,
            'issuer_bank_id' => $parent->issuer_bank_id,
            'liability_account_id' => $mainAccountId,
            'bank_account_id' => null,
            'closing_day' => $parent->closing_day,
            'due_day' => $parent->due_day,
            'best_purchase_day' => $parent->best_purchase_day,
            'credit_limit_cents' => $parent->credit_limit_cents,
        ]);

        if ($oldAccountId !== $mainAccountId) {
            DB::table('journal_lines')
                ->where('chart_of_account_id', $oldAccountId)
                ->whereIn('journal_entry_id', function ($query) use ($child) {
                    $query->select('journal_entry_id')
                        ->from('credit_card_transactions')
                        ->where('credit_card_id', $child->id);
                })
                ->update(['chart_of_account_id' => $mainAccountId]);

            DB::table('chart_of_accounts')
                ->where('id', $oldAccountId)
                ->whereNotExists(function ($query) use ($oldAccountId) {
                    $query->selectRaw('1')
                        ->from('journal_lines')
                        ->whereColumn('journal_lines.chart_of_account_id', 'chart_of_accounts.id')
                        ->where('journal_lines.chart_of_account_id', $oldAccountId);
                })
                ->update(['allows_posting' => false]);
        }
    }
};
