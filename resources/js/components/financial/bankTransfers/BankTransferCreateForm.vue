<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

defineProps<{
    form: Record<string, any>;
    bankAccounts: Array<Record<string, any>>;
    canSubmit: boolean;
}>();

const emit = defineEmits<{
    submit: [];
    updateAmount: [event: Event];
}>();

function accountLabel(account: Record<string, any>): string {
    const details = [account.bank_code, account.agency, account.account_number]
        .filter(Boolean)
        .join(' / ');

    return details ? `${account.name} (${details})` : account.name;
}
</script>

<template>
    <form class="space-y-6 p-6" @submit.prevent="emit('submit')">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-semibold text-gray-300">Conta origem</label>
                <select
                    v-model="form.from_bank_account_id"
                    class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-white"
                >
                    <option value="">Selecione a origem</option>
                    <option
                        v-for="account in bankAccounts"
                        :key="account.id"
                        :value="account.id"
                    >
                        {{ accountLabel(account) }}
                    </option>
                </select>
                <p class="mt-1 text-sm text-red-400">{{ form.errors.from_bank_account_id }}</p>
            </div>

            <div>
                <label class="mb-1 block text-sm font-semibold text-gray-300">Conta destino</label>
                <select
                    v-model="form.to_bank_account_id"
                    class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-white"
                >
                    <option value="">Selecione o destino</option>
                    <option
                        v-for="account in bankAccounts"
                        :key="account.id"
                        :disabled="String(account.id) === String(form.from_bank_account_id)"
                        :value="account.id"
                    >
                        {{ accountLabel(account) }}
                    </option>
                </select>
                <p class="mt-1 text-sm text-red-400">{{ form.errors.to_bank_account_id }}</p>
            </div>

            <div>
                <label class="mb-1 block text-sm font-semibold text-gray-300">Valor</label>
                <input
                    :value="form.amount"
                    class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-white"
                    placeholder="R$ 0,00"
                    inputmode="numeric"
                    @input="emit('updateAmount', $event)"
                />
                <p class="mt-1 text-sm text-red-400">{{ form.errors.amount_cents }}</p>
            </div>

            <div>
                <label class="mb-1 block text-sm font-semibold text-gray-300">Data</label>
                <input
                    v-model="form.transfer_date"
                    type="date"
                    class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-white"
                />
                <p class="mt-1 text-sm text-red-400">{{ form.errors.transfer_date }}</p>
            </div>

            <div class="md:col-span-2">
                <label class="mb-1 block text-sm font-semibold text-gray-300">Descrição</label>
                <input
                    v-model="form.description"
                    class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-white"
                    placeholder="Ex: Transferência para conta investimento"
                />
                <p class="mt-1 text-sm text-red-400">{{ form.errors.description }}</p>
            </div>
        </div>

        <div class="rounded-lg border border-blue-900 bg-blue-950/30 p-4 text-sm text-blue-200">
            Ao salvar, o sistema irá gerar automaticamente um lançamento contábil postado:
            <strong>débito na conta destino</strong> e <strong>crédito na conta origem</strong>.
        </div>

        <div class="flex justify-end gap-3">
            <Link
                :href="route('bank-transfers.index')"
                class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
            >
                Cancelar
            </Link>

            <button
                type="submit"
                :disabled="!canSubmit || form.processing"
                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
            >
                Salvar transferência
            </button>
        </div>
    </form>
</template>
