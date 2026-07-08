<script setup lang="ts">
defineProps<{
    form: Record<string, any>;
    bankAccounts: Array<Record<string, any>>;
}>();

const emit = defineEmits<{
    submit: [];
    clear: [];
}>();
</script>

<template>
    <form class="grid grid-cols-1 gap-4 p-6 lg:grid-cols-5" @submit.prevent="emit('submit')">
        <div class="lg:col-span-2">
            <label class="mb-1 block text-sm font-semibold text-gray-300">Conta bancária</label>
            <select
                v-model="form.bank_account_id"
                class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-white"
            >
                <option value="">Selecione uma conta</option>
                <option
                    v-for="account in bankAccounts"
                    :key="account.id"
                    :value="account.id"
                >
                    {{ account.label }}
                </option>
            </select>
        </div>

        <div>
            <label class="mb-1 block text-sm font-semibold text-gray-300">Data inicial</label>
            <input
                v-model="form.start_date"
                type="date"
                class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-white"
            />
        </div>

        <div>
            <label class="mb-1 block text-sm font-semibold text-gray-300">Data final</label>
            <input
                v-model="form.end_date"
                type="date"
                class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-white"
            />
        </div>

        <div>
            <label class="mb-1 block text-sm font-semibold text-gray-300">Buscar</label>
            <input
                v-model="form.search"
                class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-white"
                placeholder="Descrição"
            />
        </div>

        <div class="flex gap-3 lg:col-span-5 lg:justify-end">
            <button
                type="button"
                class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                @click="emit('clear')"
            >
                Limpar
            </button>

            <button
                type="submit"
                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500"
            >
                Aplicar filtros
            </button>
        </div>
    </form>
</template>
