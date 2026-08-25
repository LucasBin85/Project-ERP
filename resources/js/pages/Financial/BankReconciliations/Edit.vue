<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { moneyToCents } from '@/lib/input';
import { Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';
import { route } from 'ziggy-js';

interface StatementItem {
    bank_statement_import_transaction_id: number | null;
    transaction_date: string;
    description: string;
    amount_cents: number;
    journal_line_id: number | null;
    amount_input?: string;
}

interface AvailableLine {
    id: number;
    date: string;
    description: string;
    signed_amount_cents: number;
}

interface Preview {
    opening_balance_cents: number;
    statement_balance_cents: number;
    calculated_statement_balance_cents: number;
    statement_items_difference_cents: number;
    book_balance_cents: number;
    reconciled_balance_cents: number;
    difference_cents: number;
    pending_count: number;
    status: 'draft' | 'completed';
    has_existing_overlap: boolean;
    existing_reconciliation_id: number | null;
}

const props = defineProps<{
    wallet: { id: number; name: string };
    reconciliation: Record<string, any>;
    availableLines: AvailableLine[];
}>();

function dateValue(value: string): string {
    return value.substring(0, 10);
}

function formatMoneyInput(cents: number): string {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(cents / 100);
}

function toCents(value: string): number {
    return (value.trim().startsWith('-') ? -1 : 1) * moneyToCents(value);
}

const form = useForm({
    statement_balance_cents: Number(props.reconciliation.statement_balance_cents),
    notes: props.reconciliation.notes ?? '',
    statement_items: (props.reconciliation.statement_items as any[]).map((item) => ({
        bank_statement_import_transaction_id: item.bank_statement_import_transaction_id,
        transaction_date: dateValue(item.transaction_date),
        description: item.description,
        amount_cents: Number(item.amount_cents),
        journal_line_id: item.journal_line_id,
        amount_input: formatMoneyInput(Number(item.amount_cents)),
    })) as StatementItem[],
});
const statementBalanceInput = ref(formatMoneyInput(form.statement_balance_cents));
const preview = ref<Preview | null>(null);
const previewSignature = ref<string | null>(null);
const previewProcessing = ref(false);
const previewError = ref<string | null>(null);

function updatePayload() {
    return {
        statement_balance_cents: form.statement_balance_cents,
        notes: form.notes,
        statement_items: form.statement_items.map(({ amount_input: _, ...item }) => item),
    };
}

function previewPayload() {
    return {
        bank_account_id: props.reconciliation.bank_account_id,
        period_start: dateValue(props.reconciliation.period_start),
        period_end: dateValue(props.reconciliation.period_end),
        bank_reconciliation_id: props.reconciliation.id,
        ...updatePayload(),
    };
}

const signature = computed(() => JSON.stringify(updatePayload()));
const previewIsCurrent = computed(() => preview.value !== null && previewSignature.value === signature.value);
const canSave = computed(() => previewIsCurrent.value && !preview.value?.has_existing_overlap && !form.processing);
const formErrorMessages = computed(() => Object.values(form.errors));

function updateBalance(event: Event) {
    const value = (event.target as HTMLInputElement).value;
    form.statement_balance_cents = toCents(value);
    statementBalanceInput.value = formatMoneyInput(form.statement_balance_cents);
}

function addManualItem() {
    form.statement_items.push({
        bank_statement_import_transaction_id: null,
        transaction_date: dateValue(props.reconciliation.period_start),
        description: '',
        amount_cents: 0,
        journal_line_id: null,
        amount_input: '',
    });
}

function removeManualItem(index: number) {
    if (form.statement_items[index].bank_statement_import_transaction_id === null) form.statement_items.splice(index, 1);
}

function updateManualAmount(index: number, event: Event) {
    const value = (event.target as HTMLInputElement).value;
    form.statement_items[index].amount_cents = toCents(value);
    form.statement_items[index].amount_input = formatMoneyInput(form.statement_items[index].amount_cents);
}

async function requestPreview() {
    previewProcessing.value = true;
    previewError.value = null;

    try {
        const response = await axios.post<Preview>(route('bank-reconciliations.preview'), previewPayload(), {
            headers: { Accept: 'application/json' },
        });
        preview.value = response.data;
        previewSignature.value = signature.value;
    } catch (error: any) {
        preview.value = null;
        previewSignature.value = null;
        previewError.value = Object.values(error.response?.data?.errors ?? {})[0]?.[0] ?? 'Não foi possível pré-visualizar a revisão.';
    } finally {
        previewProcessing.value = false;
    }
}

function save() {
    if (!canSave.value) return;

    form.transform(() => updatePayload()).put(route('bank-reconciliations.update', [props.reconciliation.id]), {
        preserveScroll: true,
        onError: () => (previewSignature.value = null),
    });
}
</script>

<template>
    <AppLayout title="Revisar conciliação bancária">
        <ReportPage title="Revisar rascunho" :subtitle="wallet.name">
            <div class="flex justify-end">
                <Link
                    :href="route('bank-reconciliations.show', [reconciliation.id])"
                    class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300"
                    >Voltar</Link
                >
            </div>

            <ReportSection>
                <template #header><h2 class="text-lg font-bold text-white">Identidade da conciliação</h2></template>
                <div class="grid gap-4 p-6 md:grid-cols-3">
                    <div>
                        <p class="text-xs text-gray-500 uppercase">Conta bancária</p>
                        <p class="mt-1 font-semibold text-white">{{ reconciliation.bank_account.name }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase">Período</p>
                        <p class="mt-1 text-white">{{ formatDate(reconciliation.period_start) }} até {{ formatDate(reconciliation.period_end) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase">Status atual</p>
                        <StatusBadge class="mt-1" status="draft" />
                    </div>
                    <p class="text-sm text-gray-400 md:col-span-3">
                        Conta e período são imutáveis. Para corrigi-los, descarte este rascunho e crie uma nova conciliação.
                    </p>
                </div>
            </ReportSection>

            <ReportSection>
                <template #header><h2 class="text-lg font-bold text-white">Dados revisáveis</h2></template>
                <div class="grid gap-4 p-6">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Saldo final informado pelo banco</label
                        ><input
                            :value="statementBalanceInput"
                            class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                            @input="updateBalance"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Observações</label
                        ><textarea v-model="form.notes" rows="3" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" />
                    </div>
                </div>
            </ReportSection>

            <ReportSection>
                <template #header
                    ><div class="flex w-full items-center justify-between">
                        <div>
                            <h2 class="text-lg font-bold text-white">Itens do extrato</h2>
                            <p class="text-sm text-gray-400">Dados importados permanecem canônicos; o vínculo contábil pode ser revisado.</p>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg border border-indigo-500 px-4 py-2 text-sm font-semibold text-indigo-300"
                            @click="addManualItem"
                        >
                            Adicionar item manual
                        </button>
                    </div></template
                >
                <div class="divide-y divide-gray-800">
                    <div
                        v-for="(item, index) in form.statement_items"
                        :key="`${item.bank_statement_import_transaction_id ?? 'manual'}-${index}`"
                        class="grid gap-3 p-6 md:grid-cols-12"
                    >
                        <div class="md:col-span-2">
                            <p class="text-xs text-gray-500 uppercase">Origem</p>
                            <p class="mt-2 text-sm font-semibold">{{ item.bank_statement_import_transaction_id ? 'Extrato importado' : 'Manual' }}</p>
                        </div>
                        <div class="md:col-span-2">
                            <label class="text-xs text-gray-500 uppercase">Data</label>
                            <p v-if="item.bank_statement_import_transaction_id" class="py-2">{{ item.transaction_date }}</p>
                            <input v-else v-model="item.transaction_date" type="date" class="w-full rounded border border-gray-700 bg-black p-2" />
                        </div>
                        <div class="md:col-span-3">
                            <label class="text-xs text-gray-500 uppercase">Descrição</label>
                            <p v-if="item.bank_statement_import_transaction_id" class="py-2">{{ item.description }}</p>
                            <input v-else v-model="item.description" class="w-full rounded border border-gray-700 bg-black p-2" />
                        </div>
                        <div class="md:col-span-2">
                            <label class="text-xs text-gray-500 uppercase">Valor</label>
                            <p v-if="item.bank_statement_import_transaction_id" class="py-2">{{ formatCurrency(item.amount_cents) }}</p>
                            <input
                                v-else
                                :value="item.amount_input"
                                class="w-full rounded border border-gray-700 bg-black p-2"
                                @input="updateManualAmount(index, $event)"
                            />
                        </div>
                        <div class="md:col-span-3">
                            <label class="text-xs text-gray-500 uppercase">Lançamento vinculado</label
                            ><select v-model="item.journal_line_id" class="w-full rounded border border-gray-700 bg-black p-2">
                                <option :value="null">Pendente</option>
                                <option v-for="line in availableLines" :key="line.id" :value="line.id">
                                    {{ line.date }} · {{ line.description }} · {{ formatCurrency(line.signed_amount_cents) }}
                                </option></select
                            ><button
                                v-if="item.bank_statement_import_transaction_id === null"
                                type="button"
                                class="mt-2 text-sm text-red-300"
                                @click="removeManualItem(index)"
                            >
                                Remover
                            </button>
                        </div>
                    </div>
                </div>
            </ReportSection>

            <div v-if="preview && !previewIsCurrent" class="rounded-xl border border-yellow-500/30 bg-yellow-950/30 p-4 text-sm text-yellow-200">
                Os dados mudaram. Gere uma nova prévia antes de salvar.
            </div>
            <div v-if="previewError" class="rounded-xl border border-red-500/30 bg-red-950/30 p-4 text-sm text-red-200">{{ previewError }}</div>
            <div v-if="formErrorMessages.length" role="alert" class="rounded-xl border border-red-500/30 bg-red-950/30 p-4 text-sm text-red-200">
                <p class="font-semibold">Revise os dados antes de salvar:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li v-for="message in formErrorMessages" :key="message">{{ message }}</li>
                </ul>
            </div>
            <div class="flex justify-end">
                <button
                    type="button"
                    :disabled="previewProcessing"
                    class="rounded-lg bg-indigo-600 px-5 py-2.5 font-semibold text-white disabled:opacity-50"
                    @click="requestPreview"
                >
                    {{ previewProcessing ? 'Calculando...' : 'Pré-visualizar revisão' }}
                </button>
            </div>

            <ReportSection v-if="preview && previewIsCurrent">
                <template #header
                    ><div class="flex w-full justify-between">
                        <h2 class="text-lg font-bold text-white">Resultado projetado</h2>
                        <StatusBadge :status="preview.status" /></div
                ></template>
                <div class="grid gap-4 p-6 sm:grid-cols-2 xl:grid-cols-4">
                    <ReportSummaryCard label="Saldo inicial" :value="formatCurrency(preview.opening_balance_cents)" tone="blue" /><ReportSummaryCard
                        label="Saldo final informado pelo banco"
                        :value="formatCurrency(preview.statement_balance_cents)"
                        tone="neutral"
                    /><ReportSummaryCard
                        label="Saldo calculado pelos itens"
                        :value="formatCurrency(preview.calculated_statement_balance_cents)"
                        tone="neutral"
                    /><ReportSummaryCard
                        label="Saldo contábil"
                        :value="formatCurrency(preview.book_balance_cents)"
                        tone="neutral"
                    /><ReportSummaryCard
                        label="Saldo conciliado"
                        :value="formatCurrency(preview.reconciled_balance_cents)"
                        tone="green"
                    /><ReportSummaryCard
                        label="Diferença da conciliação"
                        :value="formatCurrency(preview.difference_cents)"
                        :tone="preview.difference_cents === 0 ? 'green' : 'yellow'"
                    /><ReportSummaryCard
                        label="Pendências"
                        :value="String(preview.pending_count)"
                        :tone="preview.pending_count === 0 ? 'green' : 'yellow'"
                    /><ReportSummaryCard
                        label="Status projetado"
                        :value="preview.status === 'completed' ? 'Pronta para conclusão' : 'Permanecerá rascunho'"
                        :tone="preview.status === 'completed' ? 'green' : 'yellow'"
                    />
                </div>
                <div v-if="preview.has_existing_overlap" class="mx-6 mb-6 rounded border border-red-500/30 p-4 text-red-200">
                    Outra conciliação cobre este período. A revisão não pode ser salva.<Link
                        v-if="preview.existing_reconciliation_id"
                        class="ml-2 underline"
                        :href="route('bank-reconciliations.show', [preview.existing_reconciliation_id])"
                        >Ver conciliação</Link
                    >
                </div>
                <div class="flex justify-end border-t border-gray-800 p-6">
                    <button
                        type="button"
                        :disabled="!canSave"
                        class="rounded-lg bg-green-600 px-5 py-2.5 font-semibold text-white disabled:opacity-50"
                        @click="save"
                    >
                        {{ form.processing ? 'Salvando...' : preview.status === 'completed' ? 'Salvar e concluir' : 'Salvar rascunho' }}
                    </button>
                </div>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
