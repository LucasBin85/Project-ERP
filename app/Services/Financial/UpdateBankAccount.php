<?php

namespace App\Services\Financial;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpdateBankAccount
{
    public function execute(Wallet $wallet, BankAccount $account, array $data): BankAccount
    {
        return DB::transaction(function () use ($wallet, $account, $data) {
            $account = BankAccount::query()->whereKey($account->id)->where('wallet_id', $wallet->id)->lockForUpdate()->firstOrFail();
            $bank = Bank::query()->whereKey($data['bank_id'])->where('active', true)->firstOrFail();
            $normalized = fn (?string $value) => ltrim(strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $value ?? '')), '0') ?: '0';
            $duplicate = BankAccount::query()->where('wallet_id', $wallet->id)->whereKeyNot($account->id)->get()
                ->contains(fn (BankAccount $other) => (int) $other->bank_id === (int) $bank->id
                    && $normalized($other->agency) === $normalized($data['agency'])
                    && $normalized($other->account_number) === $normalized($data['account_number']));
            if ($duplicate) throw new InvalidArgumentException('Já existe uma conta deste banco com a mesma agência e número na wallet ativa.');
            $chartAccountId = $account->chart_of_account_id;
            $account->update([
                'bank_id' => $bank->id, 'name' => $data['name'], 'bank_name' => $bank->short_name,
                'bank_code' => $bank->code, 'agency' => $data['agency'], 'account_number' => $data['account_number'],
                'account_type' => $data['account_type'], 'is_active' => $data['is_active'],
            ]);
            if ((int) $account->chart_of_account_id !== (int) $chartAccountId) throw new InvalidArgumentException('A conta contábil vinculada não pode ser alterada.');
            return $account->fresh(['bank', 'chartOfAccount']);
        });
    }
}
