<script setup lang="ts">
import { formatCurrency, formatDate } from '@/lib/formatters';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { route } from 'ziggy-js';

const props = defineProps<{ creditCardId: number; preview?: Record<string, any> | null; expenseAccounts: Array<Record<string, any>> }>();
const file = ref<File | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const showPreview = ref(Boolean(props.preview));
const upload = useForm<{ statement_file: File | null }>({ statement_file: null });
const safePreview = computed(() => props.preview ? {
    ...props.preview,
    rows: Array.isArray(props.preview.rows) ? props.preview.rows : [],
    ignored_items: Array.isArray(props.preview.ignored_items) ? props.preview.ignored_items : [],
    warnings: Array.isArray(props.preview.warnings) ? props.preview.warnings : [],
    summary: {
        total_cents: Number(props.preview.summary?.total_cents ?? 0),
        new: Number(props.preview.summary?.new ?? 0),
        ignored: Number(props.preview.summary?.ignored ?? 0),
        installments_pending: Number(props.preview.summary?.installments_pending ?? 0),
        installments_matched: Number(props.preview.summary?.installments_matched ?? 0),
    },
} : null);

function makeDecisions() {
    return (safePreview.value?.rows ?? []).map((row: any) => ({
        row_key: row.row_key,
        action: row.default_action,
        plan_id: row.installment_plan_matches?.[0]?.id ?? null,
        description_base: row.description_base,
        recognized_from_installment: row.installment_number,
        recognized_to_installment: row.installments_total,
        recognized_total_cents: row.recognized_total_cents,
        original_total_cents: Number(row.amount_cents) * Number(row.installments_total),
        classification_account_id: null,
        recognition_date: row.date,
        notes: '',
        installments: Array.from({ length: Number(row.installments_total ?? 1) }, (_, index) => ({
            installment_number: index + 1, amount_cents: Number(row.amount_cents),
        })),
    }));
}
const decisions = ref(makeDecisions());
const confirmation = useForm<any>({
    preview_token: safePreview.value?.token ?? '', rows: decisions.value,
    target_year: safePreview.value?.target_invoice?.reference_year ?? new Date().getFullYear(),
    target_month: safePreview.value?.target_invoice?.reference_month ?? '',
});
watch(() => safePreview.value?.token, (token) => {
    decisions.value = makeDecisions();
    Object.assign(confirmation, {
        preview_token: token ?? '', rows: decisions.value,
        target_year: safePreview.value?.target_invoice?.reference_year ?? new Date().getFullYear(),
        target_month: safePreview.value?.target_invoice?.reference_month ?? '',
    });
    showPreview.value = Boolean(token);
});
const unresolved = computed(() => (safePreview.value?.rows ?? []).filter((row: any, index: number) =>
    Number(row.installments_total) > 1 && decisions.value[index]?.action === 'resolve'
).length);
const reviewingIndex = ref<number | null>(null);
const reviewingRow = computed(() => reviewingIndex.value === null ? null : safePreview.value?.rows[reviewingIndex.value]);
const reviewingDecision = computed(() => reviewingIndex.value === null ? null : decisions.value[reviewingIndex.value]);

function openInstallment(index: number) {
    reviewingIndex.value = index;
}

function setMoney(target: Record<string, any>, key: string, event: Event) {
    const digits = (event.target as HTMLInputElement).value.replace(/\D/g, '');
    target[key] = Number(digits || 0);
}

function confirmInstallment() {
    if (!reviewingDecision.value?.classification_account_id) return;
    reviewingDecision.value.action = 'confirm_plan';
    reviewingIndex.value = null;
}

function leavePending() {
    if (reviewingDecision.value) reviewingDecision.value.action = 'pending_plan';
    reviewingIndex.value = null;
}

function installmentStatus(row: any, index: number) {
    const action = decisions.value[index]?.action;
    if (row.situation === 'installment_matched') return 'Conciliada com parcelamento existente';
    if (row.situation === 'installment_divergent') return 'Valor divergente';
    if (row.situation === 'installment_plan_pending') return 'Possível parcela de plano pendente';
    if (row.situation === 'installment_ambiguous') return 'Correspondência ambígua';
    if (action === 'confirm_plan') return 'Confirmado';
    if (action === 'pending_plan') return 'Aguardando classificação';
    return 'Aguardando revisão';
}

function selectFile(event: Event) {
    file.value = (event.target as HTMLInputElement).files?.[0] ?? null;
    upload.statement_file = file.value;
    showPreview.value = false;
    upload.clearErrors();
    confirmation.clearErrors();
}
function previewFile() {
    upload.post(route('credit-cards.statement.preview', props.creditCardId), { forceFormData: true, preserveScroll: true });
}
function confirm() {
    confirmation.rows = decisions.value;
    confirmation.post(route('credit-cards.statement.confirm', props.creditCardId), { preserveScroll: true });
}
function situation(row: any) {
    return ({
        new: 'Compra normal · A classificar', already_imported: 'Já importada',
        credit: 'Crédito/estorno/pagamento ignorado', possible_duplicate: 'Possível duplicada',
        installment_detected: 'Parcelamento detectado', installment_matched: 'Conciliada com parcelamento existente',
        installment_ambiguous: 'Parcelamento ambíguo', installment_divergent: 'Parcela esperada com valor divergente',
        installment_plan_pending: 'Possível parcela de plano pendente',
    } as Record<string, string>)[row.situation] ?? row.situation;
}
</script>

<template>
    <section class="rounded-xl border border-gray-700 bg-gray-950 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div><h3 class="font-bold text-white">Importar arquivo da fatura</h3><p class="text-xs text-gray-400">OFX, CSV, PDF textual ou PDF/OCR. A prévia não cria lançamentos.</p></div>
            <form class="flex flex-wrap items-center justify-end gap-2" @submit.prevent="previewFile">
                <input ref="fileInput" type="file" accept=".ofx,.csv,.pdf" class="sr-only" @change="selectFile">
                <button type="button" class="rounded border border-indigo-400 px-3 py-2 text-xs font-semibold text-indigo-100" @click="fileInput?.click()">Selecionar arquivo</button>
                <span class="max-w-64 truncate text-xs text-gray-300">{{ file?.name ?? 'Nenhum arquivo selecionado' }}</span>
                <button :disabled="!file || upload.processing" class="rounded bg-indigo-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Pré-visualizar</button>
            </form>
        </div>
        <p v-if="upload.errors.statement_file" class="mt-2 text-xs text-red-300">{{ upload.errors.statement_file }}</p>

        <div v-if="safePreview && showPreview" class="mt-4 space-y-3">
            <ol class="grid grid-cols-5 gap-2 text-center text-xs font-semibold">
                <li v-for="step in ['1. Arquivo', '2. Pré-visualização', '3. Resolver parcelamentos', '4. Confirmação', '5. Resultado']" :key="step" class="rounded border border-indigo-500/30 px-2 py-1.5 text-indigo-200">{{ step }}</li>
            </ol>
            <div class="grid gap-2 text-xs sm:grid-cols-4">
                <span class="rounded bg-gray-900 p-2">{{ safePreview.file_name }} · {{ safePreview.origin }}</span>
                <span class="rounded bg-indigo-950/50 p-2">Fatura: {{ confirmation.target_month ? String(confirmation.target_month).padStart(2, '0') + '/' + confirmation.target_year : 'Selecione' }}</span>
                <span class="rounded bg-gray-900 p-2">Total: {{ formatCurrency(safePreview.summary.total_cents) }}</span>
                <span class="rounded bg-blue-950/40 p-2">{{ safePreview.summary.new }} compras normais</span>
            </div>
            <p v-if="safePreview.summary.installments_pending" class="rounded border border-amber-500/40 bg-amber-950/30 p-3 text-sm font-semibold text-amber-200">{{ safePreview.summary.installments_pending }} parcelamento(s) precisam de confirmação.</p>
            <p v-for="warning in safePreview.warnings" :key="warning" class="rounded bg-amber-950/30 p-3 text-sm text-amber-200">{{ warning }}</p>
            <div class="grid gap-3 rounded border border-gray-700 bg-gray-900/50 p-3 sm:grid-cols-2">
                <label class="text-xs text-gray-300">Mês da fatura<select v-model="confirmation.target_month" class="mt-1 w-full rounded border border-gray-600 bg-black px-3 py-2 text-white"><option value="">Selecione</option><option v-for="month in 12" :key="month" :value="month">{{ String(month).padStart(2, '0') }}</option></select></label>
                <label class="text-xs text-gray-300">Ano da fatura<input v-model="confirmation.target_year" type="number" min="2000" max="2100" class="mt-1 w-full rounded border border-gray-600 bg-black px-3 py-2 text-white"></label>
            </div>
            <div class="overflow-auto rounded border border-gray-700">
                <table class="w-full min-w-[850px] text-sm"><thead class="bg-gray-900 text-gray-400"><tr><th class="p-3 text-left">Data</th><th class="p-3 text-left">Descrição</th><th class="p-3 text-right">Valor</th><th class="p-3">Parcela</th><th class="p-3">Situação</th></tr></thead>
                    <tbody class="divide-y divide-gray-800"><tr v-for="row in safePreview.rows" :key="row.row_key"><td class="p-3">{{ formatDate(row.date) }}</td><td class="p-3 text-white">{{ row.description }}</td><td class="p-3 text-right">{{ formatCurrency(row.amount_cents) }}</td><td class="p-3 text-center">{{ row.installment_number }}/{{ row.installments_total }}</td><td class="p-3 text-center">{{ situation(row) }}</td></tr></tbody>
                </table>
            </div>
            <div v-for="(row, index) in safePreview.rows" v-show="Number(row.installments_total) > 1 && row.situation.startsWith('installment_')" :key="'plan-'+row.row_key" class="space-y-3 rounded-xl border border-amber-500/30 bg-amber-950/10 p-4">
                <div class="flex flex-wrap justify-between gap-2">
                    <div><p class="font-bold text-white">{{ row.description_base }}</p><p class="text-xs text-amber-200">Parcela {{ row.installment_number }}/{{ row.installments_total }} · {{ formatCurrency(row.amount_cents) }}</p><p v-if="row.started_before_erp" class="text-xs text-gray-400">Parcelamento já estava em andamento quando entrou no sistema.</p></div>
                    <div class="flex items-center gap-2">
                        <span class="rounded-full border border-amber-500/40 bg-amber-950/40 px-2.5 py-1 text-xs font-semibold text-amber-200">{{ installmentStatus(row, index) }}</span>
                        <button v-if="['installment_detected', 'installment_ambiguous'].includes(row.situation)" type="button" class="rounded bg-indigo-600 px-3 py-2 text-sm font-semibold text-white" @click="openInstallment(index)">Revisar parcelamento</button>
                    </div>
                </div>
                <p v-if="row.situation === 'installment_ambiguous'" class="text-xs text-amber-300">Possível parcelamento existente encontrado. Confirme o vínculo manualmente.</p>
                <div v-if="row.situation === 'installment_matched'" class="space-y-1 text-xs text-green-300">
                    <p>Plano: {{ row.installment_plan_matches?.[0]?.description_base }}</p>
                    <p>Reconhecimento contábil já realizado no plano.</p>
                </div>
                <div v-if="row.situation === 'installment_divergent'" class="space-y-1 text-xs text-amber-300">
                    <p>Plano: {{ row.installment_plan_matches?.[0]?.description_base }}</p>
                    <p>Valor esperado: {{ formatCurrency(row.installment_plan_matches?.[0]?.expected_amount_cents) }} · valor importado: {{ formatCurrency(row.amount_cents) }}.</p>
                    <p>Nenhum novo lançamento contábil será criado; o ajuste deverá ser revisado.</p>
                </div>
                <div v-if="row.situation === 'installment_plan_pending'" class="space-y-1 text-xs text-amber-300">
                    <p>Plano: {{ row.installment_plan_matches?.[0]?.description_base }}</p>
                    <p>O vínculo será mantido pendente até a confirmação e classificação do plano.</p>
                </div>
            </div>
            <p v-if="safePreview.rows.length === 0" class="rounded bg-amber-950/30 p-3 text-sm text-amber-200">{{ String(safePreview.origin).includes('PDF') ? 'O PDF foi lido, mas nenhuma compra da fatura foi reconhecida.' : 'Nenhuma compra reconhecida.' }}</p>
            <div class="flex justify-end"><button :disabled="confirmation.processing || unresolved > 0 || !confirmation.target_month || !confirmation.target_year" class="rounded bg-green-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" @click="confirm">Confirmar importação</button></div>
            <p v-if="confirmation.errors.statement_import || confirmation.errors.installments" class="text-xs text-red-300">{{ confirmation.errors.statement_import ?? confirmation.errors.installments }}</p>
        </div>

        <div v-if="reviewingRow && reviewingDecision" class="fixed inset-0 z-50 grid place-items-center bg-black/80 p-4" @click.self="reviewingIndex = null">
            <div class="max-h-[92vh] w-full max-w-5xl overflow-y-auto rounded-xl border border-gray-700 bg-gray-950 p-5 shadow-2xl">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-xl font-bold text-white">Revisar parcelamento</h3>
                        <p class="text-sm text-gray-400">{{ reviewingRow.description }} · parcela {{ reviewingRow.installment_number }}/{{ reviewingRow.installments_total }}</p>
                    </div>
                    <button type="button" class="text-2xl text-gray-400" aria-label="Fechar" @click="reviewingIndex = null">×</button>
                </div>

                <div class="mt-5 grid gap-3 md:grid-cols-3">
                    <label class="text-xs text-gray-300">Descrição base<input v-model="reviewingDecision.description_base" class="field"></label>
                    <label class="text-xs text-gray-300">Parcela atual<input v-model.number="reviewingDecision.recognized_from_installment" type="number" min="1" :max="reviewingRow.installments_total" class="field"></label>
                    <label class="text-xs text-gray-300">Total de parcelas<input v-model.number="reviewingDecision.recognized_to_installment" type="number" :min="reviewingRow.installment_number" :max="reviewingRow.installments_total" class="field"></label>
                    <label class="text-xs text-gray-300">Valor total original
                        <input :value="formatCurrency(reviewingDecision.original_total_cents)" inputmode="decimal" class="field" @input="setMoney(reviewingDecision, 'original_total_cents', $event)">
                    </label>
                    <label class="text-xs text-gray-300">Valor total reconhecido
                        <input :value="formatCurrency(reviewingDecision.recognized_total_cents)" inputmode="decimal" class="field" @input="setMoney(reviewingDecision, 'recognized_total_cents', $event)">
                    </label>
                    <label class="text-xs text-gray-300">Competência<input v-model="reviewingDecision.recognition_date" type="date" class="field"></label>
                    <label class="text-xs text-gray-300 md:col-span-2">Classificação contábil
                        <select v-model="reviewingDecision.classification_account_id" class="field">
                            <option :value="null">Selecione uma conta analítica</option>
                            <option v-for="account in expenseAccounts" :key="account.id" :value="account.id">{{ account.label }}</option>
                        </select>
                    </label>
                    <label class="text-xs text-gray-300">Observações<input v-model="reviewingDecision.notes" class="field"></label>
                </div>

                <div class="mt-5 overflow-x-auto rounded border border-gray-700">
                    <table class="w-full min-w-[760px] text-sm">
                        <thead class="bg-gray-900 text-gray-400"><tr><th class="p-3 text-left">Parcela</th><th class="p-3 text-left">Status</th><th class="p-3 text-left">Fatura prevista</th><th class="p-3 text-left">Fatura vinculada</th><th class="p-3 text-right">Valor</th><th class="p-3 text-left">Observação</th></tr></thead>
                        <tbody class="divide-y divide-gray-800">
                            <tr v-for="item in reviewingDecision.installments" :key="item.installment_number">
                                <td class="p-3">{{ item.installment_number }}/{{ reviewingRow.installments_total }}</td>
                                <td class="p-3">{{ item.installment_number < reviewingRow.installment_number ? 'Anterior ao ERP' : item.installment_number === reviewingRow.installment_number ? 'Conciliada' : 'Prevista' }}</td>
                                <td class="p-3">{{ item.installment_number < reviewingRow.installment_number ? '—' : item.installment_number === reviewingRow.installment_number ? reviewingRow.invoice_reference : 'Após a fatura atual' }}</td>
                                <td class="p-3">{{ item.installment_number === reviewingRow.installment_number ? reviewingRow.invoice_reference : '—' }}</td>
                                <td class="p-3 text-right"><input :value="formatCurrency(item.amount_cents)" inputmode="decimal" class="field min-w-32 text-right" @input="setMoney(item, 'amount_cents', $event)"></td>
                                <td class="p-3">{{ item.installment_number < reviewingRow.installment_number ? 'Não gera contabilidade' : item.installment_number === reviewingRow.installment_number ? 'Fatura atual' : 'Aguardando fatura' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-if="!reviewingDecision.classification_account_id" class="mt-3 text-sm text-amber-300">Selecione a classificação para criar o lançamento contábil único. Você também pode importar o plano como pendente.</p>
                <div class="mt-5 flex flex-wrap justify-between gap-3">
                    <div class="flex gap-2">
                        <button type="button" class="rounded border border-gray-600 px-3 py-2 text-sm" @click="reviewingDecision.action = 'normal'; reviewingIndex = null">Marcar como compra normal</button>
                        <button type="button" class="rounded border border-red-700 px-3 py-2 text-sm text-red-300" @click="reviewingDecision.action = 'ignore'; reviewingIndex = null">Ignorar item</button>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" class="rounded border border-amber-600 px-3 py-2 text-sm text-amber-200" @click="leavePending">Importar como pendente</button>
                        <button type="button" :disabled="!reviewingDecision.classification_account_id" class="rounded bg-green-700 px-4 py-2 font-semibold text-white disabled:opacity-50" @click="confirmInstallment">Confirmar parcelamento</button>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.field { margin-top: .25rem; width: 100%; border-radius: .375rem; border: 1px solid rgb(75 85 99); background: #000; padding: .5rem .75rem; color: #fff; }
</style>
