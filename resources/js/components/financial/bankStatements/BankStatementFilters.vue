<script setup lang="ts">
defineProps<{
    bankAccountId: string;
    search: string;
    bankAccounts: Array<Record<string, any>>;
}>();

const emit = defineEmits<{
    'update:bankAccountId': [value: string];
    'update:search': [value: string];
    clear: [];
}>();
</script>

<template>
    <div class="grid w-full grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <label class="mb-1 block text-sm font-semibold text-gray-300">Conta bancária</label>
            <select
                :value="bankAccountId"
                class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                @change="emit('update:bankAccountId', ($event.target as HTMLSelectElement).value)"
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
            <label class="mb-1 block text-sm font-semibold text-gray-300">Busca</label>
            <div class="flex gap-2">
                <input
                    :value="search"
                    type="text"
                    class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                    placeholder="Descrição..."
                    @input="emit('update:search', ($event.target as HTMLInputElement).value)"
                />

                <button
                    type="button"
                    class="rounded-lg border border-gray-700 px-3 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                    title="Limpar filtros"
                    @click="emit('clear')"
                >
                    Limpar
                </button>
            </div>
        </div>
    </div>
</template>
