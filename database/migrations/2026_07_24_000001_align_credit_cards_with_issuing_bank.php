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
            $table->foreignId('issuer_bank_id')->nullable()->after('wallet_id')
                ->constrained('banks')->nullOnDelete();
            $table->index(['wallet_id', 'issuer_bank_id', 'is_active'], 'credit_cards_wallet_issuer_active_idx');
        });

        $links = DB::table('credit_cards')
            ->join('bank_accounts', 'bank_accounts.id', '=', 'credit_cards.bank_account_id')
            ->whereNull('credit_cards.issuer_bank_id')
            ->whereNotNull('bank_accounts.bank_id')
            ->get(['credit_cards.id', 'bank_accounts.bank_id']);

        foreach ($links as $link) {
            DB::table('credit_cards')->where('id', $link->id)->update([
                'issuer_bank_id' => $link->bank_id,
                'bank_account_id' => null,
            ]);
        }

        $banks = DB::table('banks')->get(['id', 'name', 'short_name']);
        $unlinkedCards = DB::table('credit_cards')->whereNull('issuer_bank_id')->get(['id', 'parent_card_id', 'issuer_name']);
        foreach ($unlinkedCards->whereNull('parent_card_id') as $card) {
            $issuer = mb_strtolower(trim((string) $card->issuer_name));
            $bank = $banks->first(fn ($bank) => in_array($issuer, [
                mb_strtolower(trim($bank->name)),
                mb_strtolower(trim($bank->short_name)),
            ], true));
            if ($bank) {
                DB::table('credit_cards')->where('id', $card->id)->update(['issuer_bank_id' => $bank->id]);
            }
        }
        foreach ($unlinkedCards->whereNotNull('parent_card_id') as $card) {
            $parentBankId = DB::table('credit_cards')->where('id', $card->parent_card_id)->value('issuer_bank_id');
            if ($parentBankId) {
                DB::table('credit_cards')->where('id', $card->id)->update(['issuer_bank_id' => $parentBankId]);
            }
        }

        DB::table('credit_cards')->whereNotNull('issuer_bank_id')->update(['bank_account_id' => null]);
    }

    public function down(): void
    {
        Schema::table('credit_cards', function (Blueprint $table) {
            $table->dropIndex('credit_cards_wallet_issuer_active_idx');
            $table->dropConstrainedForeignId('issuer_bank_id');
        });
    }
};
