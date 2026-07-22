<script setup lang="ts">
import { formatCurrency, formatDate } from '@/lib/formatters';
import type { BankStatementAccount, BankStatementTransaction } from '@/types/financial/bankStatement';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { route } from 'ziggy-js';

const props = defineProps<{ transactions: BankStatementTransaction[]; bankAccount: BankStatementAccount }>();
const open = ref(false);
const processing = ref(false);
const applicable = computed(() => props.transactions.filter((item) => item.classification_suggestion?.status === 'suggested' && item.classification_suggestion.can_apply && item.accounting_status === 'draft' && item.classification_status === 'unclassified'));
function confirm() {
    processing.value = true;
    router.post(route('bank-accounts.statement.bulk-apply-suggestions', props.bankAccount.id), {
        items: applicable.value.map((item) => ({ journal_entry_id: item.journal_entry_id, rule_id: item.classification_suggestion?.rule_id })),
    }, { preserveScroll: true, onSuccess: () => open.value = false, onFinish: () => processing.value = false });
}
</script>

<template>
    <div v-if="applicable.length" class="flex flex-wrap items-center gap-3">
        <span class="text-sm font-semibold text-indigo-200">{{ applicable.length }} {{ applicable.length === 1 ? 'sugestão disponível' : 'sugestões disponíveis' }}</span>
        <button type="button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500" @click="open=true">Aplicar sugestões</button>
    </div>
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" @click.self="open=false">
        <div role="dialog" aria-modal="true" aria-labelledby="bulk-suggestions-title" class="max-h-[85vh] w-full max-w-4xl overflow-auto rounded-xl border border-gray-700 bg-gray-950 p-6">
            <h3 id="bulk-suggestions-title" class="text-lg font-bold text-white">Confirmar aplicação de sugestões</h3>
            <p class="mt-1 text-sm text-gray-400">O backend recalculará e validará cada sugestão antes de aplicá-la. Os lançamentos continuarão em rascunho.</p>
            <div class="mt-4 overflow-x-auto rounded-lg border border-gray-700">
                <table class="w-full text-left text-sm"><thead class="bg-gray-900 text-gray-400"><tr><th class="p-3">Data</th><th class="p-3">Descrição</th><th class="p-3 text-right">Valor</th><th class="p-3">Tipo</th><th class="p-3">Classificação</th><th class="p-3">Regra</th></tr></thead>
                    <tbody class="divide-y divide-gray-800"><tr v-for="item in applicable" :key="item.id"><td class="p-3 whitespace-nowrap">{{ formatDate(item.date) }}</td><td class="p-3 text-white">{{ item.description }}</td><td class="p-3 text-right whitespace-nowrap">{{ formatCurrency(item.amount_cents) }}</td><td class="p-3">{{ item.classification_suggestion?.operation_type }}</td><td class="p-3">{{ item.classification_suggestion?.target_label }}</td><td class="p-3">{{ item.classification_suggestion?.rule_name }}</td></tr></tbody>
                </table>
            </div>
            <div class="mt-5 flex justify-end gap-3"><button type="button" class="rounded border border-gray-600 px-4 py-2" :disabled="processing" @click="open=false">Cancelar</button><button type="button" class="rounded bg-indigo-600 px-4 py-2 font-semibold text-white disabled:opacity-50" :disabled="processing" @click="confirm">{{ processing ? 'Aplicando...' : `Aplicar ${applicable.length} sugestões` }}</button></div>
        </div>
    </div>
</template>
