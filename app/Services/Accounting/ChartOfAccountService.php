<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use Illuminate\Validation\ValidationException;

class ChartOfAccountService
{
    public function create(array $data): ChartOfAccount
    {
        $parent = null;

        if (!empty($data['parent_id'])) {
            $parent = ChartOfAccount::findOrFail($data['parent_id']);

            // ❌ Pai com lançamentos não pode ter filhos
            if ($parent->journalLines()->exists()) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Conta com lançamentos não pode receber subcontas.'
                ]);
            }

            // ⚠️ Se criar filho, pai deixa de ser lançável
            if ($parent->allows_posting) {
                $parent->update([
                    'allows_posting' => false
                ]);
            }
        }

        return ChartOfAccount::create($data);
    }

    public function update(ChartOfAccount $account, array $data): ChartOfAccount
    {
        // 🔒 Conta do sistema
        if ($account->is_system) {

            if (isset($data['parent_id']) && $data['parent_id'] != $account->parent_id) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Conta do sistema não pode ser movida.'
                ]);
            }

            if (isset($data['type']) && $data['type'] != $account->type) {
                throw ValidationException::withMessages([
                    'type' => 'Conta do sistema não pode alterar o tipo.'
                ]);
            }
        }

        // 🔒 Conta com filhos não pode ser lançável
        if ($account->children()->exists()) {
            if (isset($data['allows_posting']) && $data['allows_posting']) {
                throw ValidationException::withMessages([
                    'allows_posting' => 'Conta com subcontas não pode ser lançável.'
                ]);
            }
        }

        // 🔒 Conta com lançamentos não pode receber filhos (indiretamente via mudança)
        if ($account->journalLines()->exists()) {

            if (isset($data['parent_id']) && $data['parent_id'] != $account->parent_id) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Conta com lançamentos não pode ser movida.'
                ]);
            }
        }

        $account->update($data);

        return $account;
    }

    public function delete(ChartOfAccount $account): void
    {
        // 🔒 Conta do sistema
        if ($account->is_system) {
            throw ValidationException::withMessages([
                'account' => 'Contas do sistema não podem ser excluídas.'
            ]);
        }

        // 🔒 Conta com lançamentos
        if ($account->journalLines()->exists()) {
            throw ValidationException::withMessages([
                'account' => 'Conta com lançamentos não pode ser excluída.'
            ]);
        }

        // 🔒 Conta com filhos
        if ($account->children()->exists()) {
            throw ValidationException::withMessages([
                'account' => 'Conta com subcontas não pode ser excluída.'
            ]);
        }

        $account->delete();
    }

    public function validateForPosting(ChartOfAccount $account): void
    {
        if (!$account->allows_posting) {
            throw ValidationException::withMessages([
                'account_id' => 'Conta não permite lançamentos.'
            ]);
        }

        if ($account->children()->exists()) {
            throw ValidationException::withMessages([
                'account_id' => 'Conta com subcontas não pode receber lançamentos.'
            ]);
        }
    }
}