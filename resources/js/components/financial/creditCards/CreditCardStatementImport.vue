<script setup lang="ts">
import { formatCurrency, formatDate } from '@/lib/formatters';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { route } from 'ziggy-js';

const props = defineProps<{ creditCardId: number; preview?: Record<string, any> | null }>();
const file = ref<File | null>(null);
const upload = useForm<{ statement_file: File | null }>({ statement_file: null });
const decisions = computed(() => (props.preview?.rows ?? []).map((row: any) => ({
    row_key: row.row_key,
    action: row.default_action,
})));
const confirmation = useForm({ preview_token: props.preview?.token ?? '', rows: decisions.value });

function selectFile(event: Event) {
    file.value = (event.target as HTMLInputElement).files?.[0] ?? null;
    upload.statement_file = file.value;
}
function preview() {
    upload.post(route('credit-cards.statement.preview', props.creditCardId), { forceFormData: true, preserveScroll: true });
}
function confirm() {
    confirmation.rows = decisions.value;
    confirmation.post(route('credit-cards.statement.confirm', props.creditCardId), { preserveScroll: true });
}
</script>

<template>
    <section class="rounded-xl border border-gray-700 bg-gray-950 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div><h3 class="font-bold text-white">Importar arquivo da fatura</h3><p class="text-xs text-gray-400">OFX, CSV, PDF textual ou PDF/OCR. A prévia não cria lançamentos.</p></div>
            <form class="flex items-center gap-2" @submit.prevent="preview">
                <input type="file" accept=".ofx,.csv,.pdf" class="max-w-64 text-xs text-gray-300" @change="selectFile">
                <button :disabled="!file || upload.processing" class="rounded bg-indigo-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Pré-visualizar</button>
            </form>
        </div>
        <p v-if="upload.errors.statement_file" class="mt-2 text-xs text-red-300">{{ upload.errors.statement_file }}</p>

        <div v-if="preview" class="mt-4 space-y-3">
            <ol class="grid grid-cols-4 gap-2 text-center text-xs font-semibold"><li v-for="step in ['1. Arquivo','2. Pré-visualização','3. Confirmação','4. Resultado']" :key="step" class="rounded border border-indigo-500/30 px-2 py-1.5 text-indigo-200">{{ step }}</li></ol>
            <div class="grid gap-2 text-xs sm:grid-cols-5">
                <span class="rounded bg-gray-900 p-2">{{ preview.file_name }} · {{ preview.origin }}</span>
                <span class="rounded bg-gray-900 p-2">Cartão: {{ preview.credit_card_name }}</span>
                <span class="rounded bg-gray-900 p-2">Total: {{ formatCurrency(preview.summary.total_cents) }}</span>
                <span class="rounded bg-blue-950/40 p-2">{{ preview.summary.new }} novas</span>
                <span class="rounded bg-gray-900 p-2">{{ preview.summary.already_imported }} já importadas · {{ preview.summary.possible_duplicate }} duplicadas</span>
            </div>
            <div class="max-h-[52vh] overflow-auto rounded border border-gray-700">
                <table class="w-full min-w-[950px] table-fixed text-sm">
                    <thead class="sticky top-0 bg-gray-900 text-gray-400"><tr><th class="w-28 p-3 text-left">Data</th><th class="w-[42%] p-3 text-left">Descrição</th><th class="w-32 p-3 text-right">Valor</th><th class="w-24 p-3">Parcela</th><th class="w-24 p-3">Fatura</th><th class="w-44 p-3">Situação</th></tr></thead>
                    <tbody class="divide-y divide-gray-800"><tr v-for="row in preview.rows" :key="row.row_key"><td class="p-3">{{ formatDate(row.date) }}</td><td class="p-3 text-white">{{ row.description }}</td><td class="p-3 text-right">{{ formatCurrency(row.amount_cents) }}</td><td class="p-3 text-center">{{ row.installment_number }}/{{ row.installments_total }}</td><td class="p-3 text-center">{{ row.invoice_reference }}</td><td class="p-3 text-center">{{ row.situation === 'new' ? 'Nova · A classificar' : row.situation === 'already_imported' ? 'Já importada' : row.situation === 'credit' ? 'Crédito/estorno (ignorado)' : 'Possível duplicada' }}</td></tr></tbody>
                </table>
            </div>
            <div class="flex justify-end"><button :disabled="confirmation.processing || !preview.summary.new" class="rounded bg-green-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" @click="confirm">Confirmar importação</button></div>
            <p v-if="confirmation.errors.statement_import" class="text-xs text-red-300">{{ confirmation.errors.statement_import }}</p>
        </div>
    </section>
</template>
