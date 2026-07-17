<script setup lang="ts">
import axios from 'axios';
import { reactive, ref, watch } from 'vue';
import { computed } from 'vue';
import { route } from 'ziggy-js';
import { isDuplicateName, normalizeName } from '@/lib/normalizedName';

const props = withDefaults(defineProps<{ show: boolean; suggestedName?: string; controlAccounts?: any[]; expenseAccounts?: any[]; existingNames?: string[] }>(), {
    suggestedName: '', controlAccounts: () => [], expenseAccounts: () => [], existingNames: () => [],
});
const emit = defineEmits<{ close: []; created: [supplier: any] }>();
const form = reactive({ name: '', payable_account_id: '', default_expense_account_id: '', default_expense_name: '' });
const errors = ref<Record<string, string[]>>({});
const processing = ref(false);
const duplicateName = computed(() => isDuplicateName(form.name, props.existingNames));
const canSubmit = computed(() => normalizeName(form.name) !== '' && !duplicateName.value && !processing.value);

watch(() => props.show, (show) => {
    if (!show) return;
    Object.assign(form, { name: props.suggestedName, payable_account_id: '', default_expense_account_id: '', default_expense_name: '' });
    errors.value = {};
});

async function submit() {
    if (!canSubmit.value) return;
    processing.value = true;
    errors.value = {};
    try {
        const response = await axios.post(route('suppliers.quick-store'), { ...form, active: true }, { headers: { Accept: 'application/json' } });
        emit('created', response.data.supplier);
        emit('close');
    } catch (error: any) {
        errors.value = error.response?.data?.errors ?? { name: ['Não foi possível cadastrar o fornecedor.'] };
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" @click.self="emit('close')">
            <form class="w-full max-w-lg space-y-4 rounded-xl border border-gray-700 bg-gray-950 p-6 shadow-xl" @submit.prevent="submit">
                <h2 class="text-xl font-bold text-white">Cadastrar fornecedor / conta a pagar</h2>
                <div><label class="mb-1 block text-sm text-gray-300">Nome</label><input v-model="form.name" autofocus class="w-full rounded bg-black p-3 text-white"><p v-if="duplicateName" class="text-sm text-red-400">Já existe um fornecedor com este nome.</p><p v-else class="text-sm text-red-400">{{ errors.name?.[0] }}</p></div>
                <div><label class="mb-1 block text-sm text-gray-300">Conta de controle</label><select v-model="form.payable_account_id" class="w-full rounded bg-black p-3 text-white"><option value="">Criar automaticamente em 2.1</option><option v-for="account in controlAccounts" :key="account.id" :value="account.id">{{ account.code }} - {{ account.name }}</option></select><p class="text-sm text-red-400">{{ errors.payable_account_id?.[0] }}</p></div>
                <div><label class="mb-1 block text-sm text-gray-300">Despesa padrão</label><select v-model="form.default_expense_account_id" class="w-full rounded bg-black p-3 text-white"><option value="">Criar automaticamente em 5.1</option><option v-for="account in expenseAccounts" :key="account.id" :value="account.id">{{ account.code }} - {{ account.name }}</option></select><input v-if="!form.default_expense_account_id" v-model="form.default_expense_name" :placeholder="form.name || 'Nome da nova despesa'" class="mt-2 w-full rounded bg-black p-3 text-white"><p class="text-sm text-red-400">{{ errors.default_expense_account_id?.[0] || errors.default_expense_name?.[0] }}</p></div>
                <div class="flex justify-end gap-3"><button type="button" class="rounded border border-gray-600 px-4 py-2 text-gray-300" @click="emit('close')">Cancelar</button><button :disabled="!canSubmit" class="rounded bg-indigo-600 px-4 py-2 text-white disabled:cursor-not-allowed disabled:opacity-50">Cadastrar fornecedor</button></div>
            </form>
        </div>
    </Teleport>
</template>
