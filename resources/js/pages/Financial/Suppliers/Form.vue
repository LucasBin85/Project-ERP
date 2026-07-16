<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps<{ supplier?: any; controlAccounts: any[]; resultAccounts: any[] }>();
const form = useForm({
    name: props.supplier?.name ?? '',
    document: props.supplier?.document ?? '',
    payable_account_id: props.supplier?.payable_account_id ?? '',
    default_expense_account_id: props.supplier?.default_expense_account_id ?? '',
    default_expense_name: '',
    active: props.supplier?.active ?? true,
});

function submit() {
    props.supplier ? form.put(route('suppliers.update', props.supplier.id)) : form.post(route('suppliers.store'));
}
</script>

<template>
    <AppLayout :title="supplier ? 'Editar fornecedor' : 'Nova conta a pagar'">
        <form class="mx-auto max-w-2xl space-y-4 p-6" @submit.prevent="submit">
            <h1 class="text-2xl font-bold text-white">{{ supplier ? 'Editar fornecedor' : 'Nova conta a pagar / fornecedor' }}</h1>
            <input v-model="form.name" placeholder="Nome do fornecedor ou beneficiário" class="w-full rounded bg-black p-3 text-white">
            <p class="text-sm text-red-400">{{ form.errors.name }}</p>
            <input v-model="form.document" placeholder="Documento (opcional)" class="w-full rounded bg-black p-3 text-white">

            <label class="block text-sm font-semibold text-gray-300">Conta de controle</label>
            <select v-model="form.payable_account_id" class="w-full rounded bg-black p-3 text-white">
                <option value="">Criar automaticamente em 2.1 Contas a Pagar</option>
                <option v-for="account in controlAccounts" :key="account.id" :value="account.id">{{ account.code }} - {{ account.name }}</option>
            </select>
            <p class="text-sm text-red-400">{{ form.errors.payable_account_id }}</p>

            <label class="block text-sm font-semibold text-gray-300">Despesa padrão</label>
            <select v-model="form.default_expense_account_id" class="w-full rounded bg-black p-3 text-white">
                <option value="">Criar automaticamente em 5.1 Despesas Operacionais</option>
                <option v-for="account in resultAccounts" :key="account.id" :value="account.id">{{ account.code }} - {{ account.name }}</option>
            </select>
            <input v-if="!form.default_expense_account_id && !supplier" v-model="form.default_expense_name" :placeholder="form.name || 'Nome da nova despesa'" class="w-full rounded bg-black p-3 text-white">
            <p class="text-sm text-gray-400">Se o nome ficar vazio, será usado o nome do fornecedor.</p>
            <p class="text-sm text-red-400">{{ form.errors.default_expense_account_id || form.errors.default_expense_name }}</p>

            <button class="rounded bg-indigo-600 px-4 py-2 text-white" :disabled="form.processing">Salvar conta a pagar</button>
        </form>
    </AppLayout>
</template>
