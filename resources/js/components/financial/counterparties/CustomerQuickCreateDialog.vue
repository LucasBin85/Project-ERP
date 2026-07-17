<script setup lang="ts">
import axios from 'axios';
import { reactive, ref, watch } from 'vue';
import { route } from 'ziggy-js';

const props = withDefaults(defineProps<{ show: boolean; suggestedName?: string; controlAccounts?: any[]; revenueAccounts?: any[] }>(), {
    suggestedName: '', controlAccounts: () => [], revenueAccounts: () => [],
});
const emit = defineEmits<{ close: []; created: [customer: any] }>();
const form = reactive({ name: '', receivable_account_id: '', default_revenue_account_id: '', default_revenue_name: '' });
const errors = ref<Record<string, string[]>>({});
const processing = ref(false);

watch(() => props.show, (show) => {
    if (!show) return;
    Object.assign(form, { name: props.suggestedName, receivable_account_id: '', default_revenue_account_id: '', default_revenue_name: '' });
    errors.value = {};
});

async function submit() {
    processing.value = true;
    errors.value = {};
    try {
        const response = await axios.post(route('customers.quick-store'), { ...form, active: true }, { headers: { Accept: 'application/json' } });
        emit('created', response.data.customer);
        emit('close');
    } catch (error: any) {
        errors.value = error.response?.data?.errors ?? { name: ['Não foi possível cadastrar o cliente.'] };
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" @click.self="emit('close')">
            <form class="w-full max-w-lg space-y-4 rounded-xl border border-gray-700 bg-gray-950 p-6 shadow-xl" @submit.prevent="submit">
                <h2 class="text-xl font-bold text-white">Cadastrar cliente / conta a receber</h2>
                <div><label class="mb-1 block text-sm text-gray-300">Nome</label><input v-model="form.name" autofocus class="w-full rounded bg-black p-3 text-white"><p class="text-sm text-red-400">{{ errors.name?.[0] }}</p></div>
                <div><label class="mb-1 block text-sm text-gray-300">Conta de controle</label><select v-model="form.receivable_account_id" class="w-full rounded bg-black p-3 text-white"><option value="">Criar automaticamente em 1.2</option><option v-for="account in controlAccounts" :key="account.id" :value="account.id">{{ account.code }} - {{ account.name }}</option></select><p class="text-sm text-red-400">{{ errors.receivable_account_id?.[0] }}</p></div>
                <div><label class="mb-1 block text-sm text-gray-300">Receita padrão</label><select v-model="form.default_revenue_account_id" class="w-full rounded bg-black p-3 text-white"><option value="">Criar automaticamente em 4.1</option><option v-for="account in revenueAccounts" :key="account.id" :value="account.id">{{ account.code }} - {{ account.name }}</option></select><input v-if="!form.default_revenue_account_id" v-model="form.default_revenue_name" :placeholder="form.name || 'Nome da nova receita'" class="mt-2 w-full rounded bg-black p-3 text-white"><p class="text-sm text-red-400">{{ errors.default_revenue_account_id?.[0] || errors.default_revenue_name?.[0] }}</p></div>
                <div class="flex justify-end gap-3"><button type="button" class="rounded border border-gray-600 px-4 py-2 text-gray-300" @click="emit('close')">Cancelar</button><button :disabled="processing" class="rounded bg-indigo-600 px-4 py-2 text-white disabled:opacity-50">Cadastrar cliente</button></div>
            </form>
        </div>
    </Teleport>
</template>
