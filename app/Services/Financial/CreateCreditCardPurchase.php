<?php

namespace App\Services\Financial;

use App\DTOs\Financial\CreditCardTransactionDTO;
use App\Models\CreditCardTransaction;
use App\Models\Wallet;

class CreateCreditCardPurchase
{
    public function __construct(private readonly CreateCreditCardTransaction $transactions) {}

    public function execute(Wallet $wallet, CreditCardTransactionDTO $dto): CreditCardTransaction
    {
        return $this->transactions->execute($wallet, $dto);
    }
}
