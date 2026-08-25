<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCurrency } from '@/lib/formatters';
import { moneyToCents } from '@/lib/input';
import { Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';
import { route } from 'ziggy-js';

interface BankAccountOption {
    id: number;
    name: string;
    bank_name: string | null;
    agency: string | null;
    account_number: string | null;
}

interface StatementItem {
    bank_statement_import_transaction_id: number | null;
    transaction_date: string;
    description: string;
    amount_cents: number;
    journal_line_id: number | null;
    source?: string;
    source_label?: string;
    match_reason?: string | null;
    amount_input?: string;
}

interface ReconciliationPreview {
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
    bankAccounts: BankAccountOption[];
    initial: {
        bank_account_id: number | null;
        period_start: string | null;
        period_end: string | null;
        statement_balance_cents: number | null;
        statement_items: StatementItem[];
    };
}>();

const form = useForm({
    bank_account_id: props.initial.bank_account_id ? String(props.initial.bank_account_id) : '',
    period_start: props.initial.period_start ?? '',
    period_end: props.initial.period_end ?? '',
    statement_balance_cents: props.initial.statement_balance_cents ?? 0,
    notes: '',
    statement_items: props.initial.statement_items.map((item) => ({ ...item, amount_input: formatSignedMoneyInput(item.amount_cents) })),
});

const statementBalanceInput = ref(
    props.initial.statement_balance_cents === null ? '' : formatSignedMoneyInput(props.initial.statement_balance_cents),
);
const preview = ref<ReconciliationPreview | null>(null);
const previewSignature = ref<string | null>(null);
const previewProcessing = ref(false);
const previewError = ref<string | null>(null);

function formatSignedMoneyInput(cents: number): string {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(cents / 100);
}

function signedMoneyToCents(value: string): number {
    const sign = value.trim().startsWith('-') ? -1 : 1;

    return sign * moneyToCents(value);
}

function payload() {
    return {
        bank_account_id: Number(form.bank_account_id),
        period_start: form.period_start,
        period_end: form.period_end,
        statement_balance_cents: form.statement_balance_cents,
        notes: form.notes,
        statement_items: form.statement_items.map((item) => ({
            bank_statement_import_transaction_id: item.bank_statement_import_transaction_id,
            transaction_date: item.transaction_date,
            description: item.description,
            amount_cents: item.amount_cents,
            journal_line_id: item.journal_line_id,
        })),
    };
}

const currentSignature = computed(() => JSON.stringify(payload()));
const previewIsCurrent = computed(() => preview.value !== null && previewSignature.value === currentSignature.value);
const manualItemsValid = computed(() =>
    form.statement_items
        .filter((item) => item.bank_statement_import_transaction_id === null)
        .every(
            (item) =>
                Boolean(item.transaction_date) &&
                item.transaction_date >= form.period_start &&
                item.transaction_date <= form.period_end &&
                Boolean(item.description.trim()) &&
                Boolean(item.amount_input?.trim()),
        ),
);
const canPreview = computed(
    () =>
        Boolean(
            form.bank_account_id &&
                form.period_start &&
                form.period_end &&
                form.period_start <= form.period_end &&
                statementBalanceInput.value.trim(),
        ) &&
        manualItemsValid.value &&
        !previewProcessing.value,
);
const canStore = computed(() => previewIsCurrent.value && !preview.value?.has_existing_overlap && !form.processing);

function updateStatementBalance(event: Event) {
    const value = (event.target as HTMLInputElement).value;
    form.statement_balance_cents = signedMoneyToCents(value);
    statementBalanceInput.value = formatSignedMoneyInput(form.statement_balance_cents);
}

function addManualItem() {
    form.statement_items.push({
        bank_statement_import_transaction_id: null,
        transaction_date: form.period_start,
        description: '',
        amount_cents: 0,
        journal_line_id: null,
        source: 'manual',
        source_label: 'Manual',
        amount_input: '',
    });
}

function removeManualItem(index: number) {
    if (form.statement_items[index]?.bank_statement_import_transaction_id !== null) return;

    form.statement_items.splice(index, 1);
}

function updateManualAmount(index: number, event: Event) {
    const item = form.statement_items[index];
    const value = (event.target as HTMLInputElement).value;
    item.amount_cents = signedMoneyToCents(value);
    item.amount_input = formatSignedMoneyInput(item.amount_cents);
}

async function requestPreview() {
    if (!canPreview.value) return;

    previewProcessing.value = true;
    previewError.value = null;
    form.clearErrors();

    try {
        const response = await axios.post<ReconciliationPreview>(route('bank-reconciliations.preview'), payload(), {
            headers: { Accept: 'application/json' },
        });
        preview.value = response.data;
        previewSignature.value = currentSignature.value;
    } catch (error: any) {
        preview.value = null;
        previewSignature.value = null;
        const errors = error.response?.data?.errors as Record<string, string[]> | undefined;
        previewError.value = errors ? Object.values(errors)[0]?.[0] : 'Não foi possível pré-visualizar a conciliação.';
    } finally {
        previewProcessing.value = false;
    }
}

function store() {
    if (!canStore.value) return;

    form.transform(() => payload()).post(route('bank-reconciliations.store'), {
        preserveScroll: true,
        onError: () => {
            previewSignature.value = null;
        },
    });
}
</script>

<template>
    <AppLayout title="Nova conciliação bancária">
        <ReportPage title="Nova conciliação bancária" :subtitle="wallet.name">
            <div class="flex justify-end">
                <Link
                    :href="route('bank-reconciliations.index')"
                    class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                >
                    Voltar ao histórico
                </Link>
            </div>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Dados do período</h2>
                        <p class="mt-1 text-sm text-gray-400">Informe o saldo oficial do banco e revise os itens antes de salvar.</p>
                    </div>
                </template>

                <div class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Conta bancária</label>
                        <select v-model="form.bank_account_id" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="">Selecione uma conta</option>
                            <option v-for="account in bankAccounts" :key="account.id" :value="String(account.id)">
                                {{ account.name }}{{ account.bank_name ? ` · ${account.bank_name}` : '' }}
                            </option>
                        </select>
                        <p class="mt-1 text-sm text-red-400">{{ form.errors.bank_account_id }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Data inicial</label>
                        <input
                            v-model="form.period_start"
                            type="date"
                            class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]"
                        />
                        <p class="mt-1 text-sm text-red-400">{{ form.errors.period_start }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Data final</label>
                        <input
                            v-model="form.period_end"
                            type="date"
                            class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]"
                        />
                        <p class="mt-1 text-sm text-red-400">{{ form.errors.period_end }}</p>
                    </div>

                    <div class="md:col-span-2">
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Saldo final informado pelo banco</label>
                        <input
                            :value="statementBalanceInput"
                            inputmode="numeric"
                            placeholder="R$ 0,00"
                            class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                            @input="updateStatementBalance"
                        />
                        <p class="mt-1 text-xs text-gray-500">Este é o saldo oficial externo e não é calculado automaticamente pelos itens.</p>
                        <p class="mt-1 text-sm text-red-400">{{ form.errors.statement_balance_cents }}</p>
                    </div>

                    <div class="md:col-span-2">
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Observações</label>
                        <textarea v-model="form.notes" rows="3" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" />
                        <p class="mt-1 text-sm text-red-400">{{ form.errors.notes }}</p>
                    </div>
                </div>
            </ReportSection>

            <ReportSection>
                <template #header>
                    <div class="flex w-full flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-bold text-white">Itens do extrato</h2>
                            <p class="mt-1 text-sm text-gray-400">Itens importados são preservados; itens manuais servem apenas como complemento.</p>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg border border-indigo-500 px-4 py-2 text-sm font-semibold text-indigo-300"
                            @click="addManualItem"
                        >
                            Adicionar item manual
                        </button>
                    </div>
                </template>

                <div v-if="form.statement_items.length === 0" class="p-6 text-sm text-gray-400">
                    Nenhum item importado neste período. Adicione itens manuais se necessário.
                </div>

                <div v-else class="divide-y divide-gray-800">
                    <div
                        v-for="(item, index) in form.statement_items"
                        :key="`${item.bank_statement_import_transaction_id ?? 'manual'}-${index}`"
                        class="grid grid-cols-1 gap-3 p-6 md:grid-cols-12"
                    >
                        <div class="md:col-span-2">
                            <p class="text-xs font-semibold text-gray-500 uppercase">Origem</p>
                            <p
                                class="mt-2 text-sm font-semibold"
                                :class="item.bank_statement_import_transaction_id ? 'text-green-300' : 'text-gray-300'"
                            >
                                {{ item.bank_statement_import_transaction_id ? 'Extrato importado' : 'Manual' }}
                            </p>
                        </div>

                        <div class="md:col-span-2">
                            <label class="mb-1 block text-xs font-semibold text-gray-500 uppercase">Data</label>
                            <input
                                v-if="!item.bank_statement_import_transaction_id"
                                v-model="item.transaction_date"
                                type="date"
                                class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]"
                            />
                            <p v-else class="py-2 text-sm text-gray-200">{{ item.transaction_date }}</p>
                        </div>

                        <div class="md:col-span-4">
                            <label class="mb-1 block text-xs font-semibold text-gray-500 uppercase">Descrição</label>
                            <input
                                v-if="!item.bank_statement_import_transaction_id"
                                v-model="item.description"
                                class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                            />
                            <p v-else class="py-2 text-sm text-gray-200">{{ item.description }}</p>
                            <p v-if="item.match_reason" class="mt-1 text-xs text-green-400">{{ item.match_reason }}</p>
                        </div>

                        <div class="md:col-span-2">
                            <label class="mb-1 block text-xs font-semibold text-gray-500 uppercase">Valor</label>
                            <input
                                v-if="!item.bank_statement_import_transaction_id"
                                :value="item.amount_input"
                                inputmode="numeric"
                                placeholder="R$ 0,00"
                                class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                                @input="updateManualAmount(index, $event)"
                            />
                            <p v-else class="py-2 text-sm font-semibold" :class="item.amount_cents >= 0 ? 'text-green-300' : 'text-red-300'">
                                {{ formatCurrency(item.amount_cents) }}
                            </p>
                        </div>

                        <div class="flex items-center justify-end md:col-span-2">
                            <StatusBadge :status="item.journal_line_id ? 'reconciled' : 'pending'" />
                            <button
                                v-if="!item.bank_statement_import_transaction_id"
                                type="button"
                                class="ml-3 text-sm font-semibold text-red-300 hover:text-red-200"
                                @click="removeManualItem(index)"
                            >
                                Remover
                            </button>
                        </div>
                    </div>
                </div>
            </ReportSection>

            <div v-if="!previewIsCurrent && preview" class="rounded-xl border border-yellow-500/30 bg-yellow-950/30 p-4 text-sm text-yellow-200">
                Os dados foram alterados depois da pré-visualização. Gere uma nova prévia antes de salvar.
            </div>

            <div v-if="previewError" role="alert" class="rounded-xl border border-red-500/30 bg-red-950/30 p-4 text-sm text-red-300">
                {{ previewError }}
            </div>

            <div class="flex justify-end">
                <button
                    type="button"
                    :disabled="!canPreview"
                    class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                    @click="requestPreview"
                >
                    {{ previewProcessing ? 'Calculando...' : 'Pré-visualizar conciliação' }}
                </button>
            </div>

            <ReportSection v-if="preview && previewIsCurrent">
                <template #header>
                    <div class="flex w-full flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-bold text-white">Revisão da conciliação</h2>
                            <p class="mt-1 text-sm text-gray-400">Revise os saldos e o status projetado antes de salvar.</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-sm text-gray-400">Status projetado:</span>
                            <StatusBadge :status="preview.status" />
                        </div>
                    </div>
                </template>

                <div class="grid grid-cols-1 gap-4 p-6 sm:grid-cols-2 xl:grid-cols-4">
                    <ReportSummaryCard label="Saldo inicial" :value="formatCurrency(preview.opening_balance_cents)" tone="blue" />
                    <ReportSummaryCard
                        label="Saldo final informado pelo banco"
                        :value="formatCurrency(preview.statement_balance_cents)"
                        tone="neutral"
                    />
                    <ReportSummaryCard
                        label="Saldo calculado pelos itens"
                        :value="formatCurrency(preview.calculated_statement_balance_cents)"
                        tone="neutral"
                    />
                    <ReportSummaryCard label="Saldo contábil" :value="formatCurrency(preview.book_balance_cents)" tone="neutral" />
                    <ReportSummaryCard label="Saldo conciliado" :value="formatCurrency(preview.reconciled_balance_cents)" tone="green" />
                    <ReportSummaryCard
                        label="Diferença da conciliação"
                        :value="formatCurrency(preview.difference_cents)"
                        :tone="preview.difference_cents === 0 ? 'green' : 'yellow'"
                    />
                    <ReportSummaryCard
                        label="Pendências"
                        :value="String(preview.pending_count)"
                        :tone="preview.pending_count === 0 ? 'green' : 'yellow'"
                    />
                    <ReportSummaryCard
                        label="Status projetado"
                        :value="preview.status === 'completed' ? 'Sem divergências' : 'Com pendências/divergências'"
                        :tone="preview.status === 'completed' ? 'green' : 'yellow'"
                    />
                </div>

                <div
                    v-if="preview.statement_items_difference_cents !== 0"
                    class="mx-6 mb-4 rounded-xl border border-yellow-500/30 bg-yellow-950/30 p-4 text-sm text-yellow-100"
                >
                    <p class="font-semibold">O saldo calculado pelos itens não corresponde ao saldo final informado pelo banco.</p>
                    <p class="mt-1">Diferença entre itens e saldo oficial: {{ formatCurrency(preview.statement_items_difference_cents) }}.</p>
                </div>

                <div
                    v-if="preview.difference_cents !== 0"
                    class="mx-6 mb-4 rounded-xl border border-orange-500/30 bg-orange-950/30 p-4 text-sm text-orange-100"
                >
                    O que foi conciliado contabilmente ainda não explica o saldo bancário declarado. Diferença da conciliação:
                    {{ formatCurrency(preview.difference_cents) }}.
                </div>

                <div v-if="preview.has_existing_overlap" class="mx-6 mb-6 rounded-xl border border-red-500/30 bg-red-950/30 p-4 text-sm text-red-100">
                    <p class="font-semibold">Já existe uma conciliação cobrindo este período. Uma nova conciliação não pode ser salva.</p>
                    <Link
                        v-if="preview.existing_reconciliation_id"
                        :href="route('bank-reconciliations.show', [preview.existing_reconciliation_id])"
                        class="mt-3 inline-flex rounded-lg border border-red-400/50 px-3 py-2 font-semibold text-red-200"
                    >
                        Ver conciliação existente
                    </Link>
                </div>

                <div
                    v-else-if="preview.status === 'draft'"
                    class="mx-6 mb-6 rounded-xl border border-yellow-500/30 bg-yellow-950/30 p-4 text-sm text-yellow-100"
                >
                    A conciliação será salva como rascunho. A edição e conclusão posterior serão tratadas no fluxo de revisão.
                </div>

                <div class="flex justify-end border-t border-gray-800 p-6">
                    <button
                        type="button"
                        :disabled="!canStore"
                        class="rounded-lg bg-green-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-green-500 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="store"
                    >
                        {{ form.processing ? 'Salvando...' : 'Salvar conciliação' }}
                    </button>
                </div>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
