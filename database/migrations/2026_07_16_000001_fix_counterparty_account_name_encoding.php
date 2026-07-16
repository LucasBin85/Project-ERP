<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $names = [
            'Energia elÃ©trica' => 'Energia elétrica',
            'Energia elÃ©trica a pagar' => 'Energia elétrica a pagar',
            'Ãgua e saneamento' => 'Água e saneamento',
            'Ãgua e saneamento a pagar' => 'Água e saneamento a pagar',
        ];

        foreach ($names as $incorrect => $correct) {
            DB::table('chart_of_accounts')->where('name', $incorrect)->update(['name' => $correct]);
            DB::table('suppliers')->where('name', $incorrect)->update(['name' => $correct]);
        }
    }

    public function down(): void
    {
        // Encoding corrections are intentionally irreversible.
    }
};
