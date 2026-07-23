<?php

namespace App\Services\Financial;

class CreateCreditCardInstallments
{
    /**
     * @return list<int>
     */
    public function split(int $totalCents, int $installments): array
    {
        $installments = max(1, $installments);
        $base = intdiv($totalCents, $installments);
        $remainder = $totalCents % $installments;

        return array_map(
            fn (int $index) => $base + ($index < $remainder ? 1 : 0),
            range(0, $installments - 1),
        );
    }
}
