<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps<{ customer?: any; controlAccounts: any[]; resultAccounts: any[] }>();
const form = useForm({
    name: props.customer?.name ?? '',
    document: props.customer?.document ?? '',
    receivable_account_id: props.customer?.receivable_account_id ?? '',
    default_revenue_account_id: props.customer?.default_revenue_account_id ?? '',
    default_revenue_name: '',
    active: props.customer?.active ?? true,
});

function submit() {
    props.customer ? form.put(route('customers.update', props.customer.id)) : form.post(route('customers.store'));
}
</script>

<template>
    <AppLayout :title="customer ? 'Editar cliente' : 'Nova conta a receber'">
        <form class="mx-auto max-w-2xl space-y-4 p-6" @submit.prevent="submit">
            <h1 class="text-2xl font-bold text-white">{{ customer ? 'Editar cliente' : 'Nova conta a receber / cliente' }}</h1>
            <input v-model="form.name" placeholder="Nome do cliente ou devedor" class="w-full rounded bg-black p-3 text-white">
            <p class="text-sm text-red-400">{{ form.errors.name }}</p>
            <input v-model="form.document" placeholder="Documento (opcional)" class="w-full rounded bg-black p-3 text-white">

            <label class="block text-sm font-semibold text-gray-300">Conta de controle</label>
            <select v-model="form.receivable_account_id" class="w-full rounded bg-black p-3 text-white">
                <option value="">Criar automaticamente em 1.2 Contas a Receber</option>
                <option v-for="account in controlAccounts" :key="account.id" :value="account.id">{{ account.code }} - {{ account.name }}</option>
            </select>

            <label class="block text-sm font-semibold text-gray-300">Receita padrão</label>
            <select v-model="form.default_revenue_account_id" class="w-full rounded bg-black p-3 text-white">
                <option value="">Criar automaticamente em 4.1 Receitas Operacionais</option>
                <option v-for="account in resultAccounts" :key="account.id" :value="account.id">{{ account.code }} - {{ account.name }}</option>
            </select>
            <input v-if="!form.default_revenue_account_id && !customer" v-model="form.default_revenue_name" :placeholder="form.name || 'Nome da nova receita'" class="w-full rounded bg-black p-3 text-white">
            <p class="text-sm text-gray-400">Se o nome ficar vazio, será usado o nome do cliente.</p>

            <button class="rounded bg-indigo-600 px-4 py-2 text-white" :disabled="form.processing">Salvar conta a receber</button>
        </form>
    </AppLayout>
</template>
