<script setup lang="ts">
import { formatCurrency, formatDate } from '@/lib/formatters';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { route } from 'ziggy-js';

const props = defineProps<{ creditCardId: number; preview?: Record<string, any> | null }>();
const safePreview = computed(() => {
    if (!props.preview) return null;

    return {
        ...props.preview,
        file_name: props.preview.file_name ?? 'Arquivo selecionado',
        origin: props.preview.origin ?? 'Formato não informado',
        credit_card_name: props.preview.credit_card_name ?? 'Fatura principal',
        rows: Array.isArray(props.preview.rows) ? props.preview.rows : [],
        ignored_items: Array.isArray(props.preview.ignored_items) ? props.preview.ignored_items : [],
        warning: props.preview.warning ?? null,
        warnings: Array.isArray(props.preview.warnings) ? props.preview.warnings : [],
        target_invoice: props.preview.target_invoice ?? null,
        period_start: props.preview.period_start ?? null,
        period_end: props.preview.period_end ?? null,
        summary: {
            total_cents: Number(props.preview.summary?.total_cents ?? 0),
            new: Number(props.preview.summary?.new ?? 0),
            already_imported: Number(props.preview.summary?.already_imported ?? 0),
            possible_duplicate: Number(props.preview.summary?.possible_duplicate ?? 0),
            credits: Number(props.preview.summary?.credits ?? 0),
            ignored: Number(props.preview.summary?.ignored ?? 0),
        },
    };
});
const file = ref<File | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const showPreview = ref(Boolean(props.preview));
const upload = useForm<{ statement_file: File | null }>({ statement_file: null });
const decisions = computed(() => (safePreview.value?.rows ?? []).map((row: any) => ({
    row_key: row.row_key,
    action: row.default_action,
})));
const confirmation = useForm({
    preview_token: safePreview.value?.token ?? '',
    rows: decisions.value,
    target_year: safePreview.value?.target_invoice?.reference_year ?? new Date().getFullYear(),
    target_month: safePreview.value?.target_invoice?.reference_month ?? '',
});
watch(() => safePreview.value?.token, (token) => {
    confirmation.preview_token = token ?? '';
    confirmation.rows = decisions.value;
    confirmation.target_year = safePreview.value?.target_invoice?.reference_year ?? new Date().getFullYear();
    confirmation.target_month = safePreview.value?.target_invoice?.reference_month ?? '';
    showPreview.value = Boolean(token);
});

function selectFile(event: Event) {
    file.value = (event.target as HTMLInputElement).files?.[0] ?? null;
    upload.statement_file = file.value;
    showPreview.value = false;
    upload.clearErrors();
    confirmation.clearErrors();
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
            <form class="flex flex-wrap items-center justify-end gap-2" @submit.prevent="preview">
                <label for="credit-card-statement-file" class="sr-only">Selecionar arquivo da fatura</label>
                <input id="credit-card-statement-file" ref="fileInput" type="file" accept=".ofx,.csv,.pdf" class="sr-only" @change="selectFile">
                <button type="button" class="cursor-pointer rounded border border-indigo-400 px-3 py-2 text-xs font-semibold text-indigo-100 outline-none transition hover:bg-indigo-950 focus-visible:ring-2 focus-visible:ring-indigo-400" @click="fileInput?.click()">
                    Selecionar arquivo
                </button>
                <span class="max-w-64 truncate text-xs text-gray-300">{{ file?.name ?? 'Nenhum arquivo selecionado' }}</span>
                <button :disabled="!file || upload.processing" class="rounded bg-indigo-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Pré-visualizar</button>
            </form>
        </div>
        <p v-if="upload.errors.statement_file" class="mt-2 text-xs text-red-300">{{ upload.errors.statement_file }}</p>

        <div v-if="safePreview && showPreview" class="mt-4 space-y-3">
            <ol class="grid grid-cols-4 gap-2 text-center text-xs font-semibold"><li v-for="step in ['1. Arquivo','2. Pré-visualização','3. Confirmação','4. Resultado']" :key="step" class="rounded border border-indigo-500/30 px-2 py-1.5 text-indigo-200">{{ step }}</li></ol>
            <div class="grid gap-2 text-xs sm:grid-cols-4">
                <span class="rounded bg-gray-900 p-2">{{ safePreview.file_name }} · {{ safePreview.origin }}</span>
                <span class="rounded bg-indigo-950/50 p-2">Fatura alvo: {{ confirmation.target_month ? String(confirmation.target_month).padStart(2, '0') + '/' + confirmation.target_year : 'Selecione abaixo' }}</span>
                <span class="rounded bg-gray-900 p-2">Vencimento: {{ safePreview.target_invoice?.due_at ? formatDate(safePreview.target_invoice.due_at) : 'Não detectado' }}</span>
                <span class="rounded bg-gray-900 p-2">Período: {{ safePreview.period_start && safePreview.period_end ? formatDate(safePreview.period_start) + ' a ' + formatDate(safePreview.period_end) : 'Não detectado' }}</span>
                <span class="rounded bg-gray-900 p-2">Total de compras: {{ formatCurrency(safePreview.summary.total_cents) }}</span>
                <span class="rounded bg-blue-950/40 p-2">{{ safePreview.summary.new }} novas</span>
                <span class="rounded bg-gray-900 p-2">{{ safePreview.summary.ignored }} ignorados</span>
                <span class="rounded bg-gray-900 p-2">Cartão: {{ safePreview.credit_card_name }}</span>
            </div>
            <p v-for="warning in safePreview.warnings" :key="warning" class="rounded bg-amber-950/30 p-3 text-sm text-amber-200">{{ warning }}</p>
            <div class="grid gap-3 rounded border border-gray-700 bg-gray-900/50 p-3 sm:grid-cols-2">
                <label class="text-xs font-semibold text-gray-300">Mês da fatura
                    <select v-model="confirmation.target_month" class="mt-1 w-full rounded border border-gray-600 bg-black px-3 py-2 text-white">
                        <option value="">Selecione</option>
                        <option v-for="month in 12" :key="month" :value="month">{{ String(month).padStart(2, '0') }}</option>
                    </select>
                </label>
                <label class="text-xs font-semibold text-gray-300">Ano da fatura
                    <input v-model="confirmation.target_year" type="number" min="2000" max="2100" class="mt-1 w-full rounded border border-gray-600 bg-black px-3 py-2 text-white">
                </label>
                <p v-if="confirmation.errors.target_invoice || confirmation.errors.target_month || confirmation.errors.target_year" class="text-xs text-red-300 sm:col-span-2">{{ confirmation.errors.target_invoice ?? confirmation.errors.target_month ?? confirmation.errors.target_year }}</p>
            </div>
            <div class="max-h-[52vh] overflow-auto rounded border border-gray-700">
                <table class="w-full min-w-[950px] table-fixed text-sm">
                    <thead class="sticky top-0 bg-gray-900 text-gray-400"><tr><th class="w-28 p-3 text-left">Data</th><th class="w-[42%] p-3 text-left">Descrição</th><th class="w-32 p-3 text-right">Valor</th><th class="w-24 p-3">Parcela</th><th class="w-24 p-3">Fatura</th><th class="w-44 p-3">Situação</th></tr></thead>
                    <tbody class="divide-y divide-gray-800"><tr v-for="row in safePreview.rows" :key="row.row_key"><td class="p-3">{{ formatDate(row.date) }}</td><td class="p-3 text-white">{{ row.description }}</td><td class="p-3 text-right">{{ formatCurrency(row.amount_cents ?? 0) }}</td><td class="p-3 text-center">{{ row.installment_number ?? 1 }}/{{ row.installments_total ?? 1 }}</td><td class="p-3 text-center">{{ row.invoice_reference ?? (confirmation.target_month ? String(confirmation.target_month).padStart(2, '0') + '/' + confirmation.target_year : '—') }}</td><td class="p-3 text-center">{{ row.situation === 'new' ? 'Nova · A classificar' : row.situation === 'already_imported' ? 'Já importada' : row.situation === 'credit' ? 'Crédito/estorno (ignorado)' : 'Possível duplicada' }}</td></tr></tbody>
                </table>
            </div>
            <p v-if="safePreview.rows.length === 0" class="rounded bg-amber-950/30 p-3 text-sm text-amber-200">{{ String(safePreview.origin).includes('PDF') ? 'O PDF foi lido, mas nenhuma compra da fatura foi reconhecida.' : 'Nenhuma compra reconhecida neste arquivo. Confira se o layout pertence a uma fatura suportada.' }}</p>
            <div class="flex justify-end"><button :disabled="confirmation.processing || !safePreview.summary.new || !confirmation.target_month || !confirmation.target_year" class="rounded bg-green-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" @click="confirm">Confirmar importação</button></div>
            <p v-if="confirmation.errors.statement_import" class="text-xs text-red-300">{{ confirmation.errors.statement_import }}</p>
        </div>
    </section>
</template>
