<script setup lang="ts">
import type { BankStatementClassificationAccount } from '@/types/financial/bankStatement';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { route } from 'ziggy-js';

const props = defineProps<{ show: boolean }>();
const emit = defineEmits<{ close: []; created: [account: BankStatementClassificationAccount] }>();
const name = ref('');
const error = ref<string | null>(null);
const processing = ref(false);
const canSubmit = computed(() => name.value.trim().length > 0 && !processing.value);

watch(() => props.show, (show) => {
    if (!show) return;
    name.value = '';
    error.value = null;
});

async function submit() {
    if (!canSubmit.value) return;
    processing.value = true;
    error.value = null;
    try {
        const response = await axios.post(route('investment-accounts.quick-store'), { name: name.value }, { headers: { Accept: 'application/json' } });
        emit('created', response.data.account as BankStatementClassificationAccount);
        emit('close');
    } catch (exception: any) {
        error.value = exception.response?.data?.errors?.name?.[0] ?? 'Não foi possível cadastrar a conta de investimento.';
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" @click.self="emit('close')">
            <form class="w-full max-w-lg space-y-4 rounded-xl border border-gray-700 bg-gray-950 p-6 shadow-xl" @submit.prevent="submit">
                <div>
                    <h2 class="text-xl font-bold text-white">Cadastrar investimento</h2>
                    <p class="mt-1 text-sm text-gray-400">Será criada uma conta contábil analítica dentro de 1.3 Investimentos.</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm text-gray-300">Nome do investimento</label>
                    <input v-model="name" autofocus maxlength="255" placeholder="Ex: Tesouro Selic ou IVVB11" class="w-full rounded bg-black p-3 text-white" />
                    <p v-if="error" class="mt-1 text-sm text-red-400">{{ error }}</p>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" class="rounded border border-gray-600 px-4 py-2 text-gray-300" @click="emit('close')">Cancelar</button>
                    <button :disabled="!canSubmit" class="rounded bg-indigo-600 px-4 py-2 text-white disabled:cursor-not-allowed disabled:opacity-50">
                        {{ processing ? 'Cadastrando...' : 'Cadastrar investimento' }}
                    </button>
                </div>
            </form>
        </div>
    </Teleport>
</template>
