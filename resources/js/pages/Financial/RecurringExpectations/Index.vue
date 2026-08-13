<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportTable from '@/components/reports/ReportTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { Link, router, useForm } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import { route } from 'ziggy-js';

const props = defineProps<{
    wallet: Record<string, any>;
    period: Record<string, any>;
    summary: Record<string, number>;
    expectations: Array<Record<string, any>>;
    suppliers: Array<Record<string, any>>;
    customers: Array<Record<string, any>>;
    expenseAccounts: Array<Record<string, any>>;
    revenueAccounts: Array<Record<string, any>>;
}>();

const selectedYear = ref(Number(props.period.year));
const selectedMonth = ref(Number(props.period.month));

const form = useForm({
    type: 'payable',
    counterparty_id: '',
    description: '',
    frequency: 'monthly',
    due_day: 10,
    amount_mode: 'variable',
    expected_amount: '',
    default_account_id: '',
    starts_on: props.period.start_date,
    ends_on: '',
    notes: '',
});

const confirmations = reactive<Record<number, { amount: string; due_date: string }>>({});

for (const item of props.expectations) {
    confirmations[item.id] = {
        amount: item.expected_amount_cents ? (Number(item.expected_amount_cents) / 100).toFixed(2).replace('.', ',') : '',
        due_date: item.period.due_date,
    };
}

function moneyToCents(value: string): number | null {
    const trimmed = String(value ?? '').trim();
    if (!trimmed) return null;

    const normalized = trimmed.includes(',')
        ? trimmed.replace(/\./g, '').replace(',', '.')
        : trimmed;
    const parsed = Number(normalized);

    return Number.isFinite(parsed) ? Math.round(parsed * 100) : null;
}

function loadPeriod() {
    router.get(route('recurring-expectations.index'), {
        year: selectedYear.value,
        month: selectedMonth.value,
    }, {
        preserveState: false,
        preserveScroll: false,
    });
}

function resetCounterparty() {
    form.counterparty_id = '';
    form.default_account_id = '';
}

watch(() => form.type, resetCounterparty);
watch(() => form.counterparty_id, (value) => {
    if (!value) return;

    if (form.type === 'payable') {
        const supplier = props.suppliers.find((item) => Number(item.id) === Number(value));
        if (supplier?.default_expense_account_id) {
            form.default_account_id = String(supplier.default_expense_account_id);
        }
        return;
    }

    const customer = props.customers.find((item) => Number(item.id) === Number(value));
    if (customer?.default_revenue_account_id) {
        form.default_account_id = String(customer.default_revenue_account_id);
    }
});

function submitExpectation() {
    const expectedAmountCents = moneyToCents(form.expected_amount);

    form.transform((data) => ({
        type: data.type,
        supplier_id: data.type === 'payable' ? data.counterparty_id : null,
        customer_id: data.type === 'receivable' ? data.counterparty_id : null,
        description: data.description,
        frequency: data.frequency,
        due_day: Number(data.due_day),
        amount_mode: data.amount_mode,
        expected_amount_cents: expectedAmountCents,
        default_account_id: data.default_account_id,
        starts_on: data.starts_on,
        ends_on: data.ends_on || null,
        notes: data.notes || null,
    })).post(route('recurring-expectations.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.type = 'payable';
            form.frequency = 'monthly';
            form.due_day = 10;
            form.amount_mode = 'variable';
            form.starts_on = props.period.start_date;
        },
    });
}

function confirmExpectation(item: Record<string, any>) {
    const values = confirmations[item.id];
    const amountCents = moneyToCents(values?.amount ?? '');
    if (!amountCents) return;

    router.post(route('recurring-expectations.confirm', item.id), {
        period: props.period.key,
        amount_cents: amountCents,
        due_date: values?.due_date || item.period.due_date,
    }, {
        preserveScroll: true,
    });
}

function skipExpectation(item: Record<string, any>) {
    router.post(route('recurring-expectations.skip', item.id), {
        period: props.period.key,
    }, {
        preserveScroll: true,
    });
}

function toggleStatus(item: Record<string, any>) {
    router.patch(route('recurring-expectations.status', item.id), {}, {
        preserveScroll: true,
    });
}

function frequencyLabel(value: string): string {
    return ({
        monthly: 'Mensal',
        quarterly: 'Trimestral',
        semiannual: 'Semestral',
        annual: 'Anual',
    } as Record<string, string>)[value] ?? value;
}

function stateLabel(value: string): string {
    return ({
        missing: 'Aguardando confirmação',
        missing_overdue: 'Não cadastrada / vencida',
        confirmed: 'Confirmada',
        skipped: 'Ignorada no mês',
        inactive: 'Inativa',
        not_due: 'Fora da periodicidade',
    } as Record<string, string>)[value] ?? value;
}

function stateClass(value: string): string {
    if (value === 'confirmed') return 'border-green-700/60 bg-green-950/40 text-green-300';
    if (value === 'missing_overdue') return 'border-red-700/60 bg-red-950/40 text-red-300';
    if (value === 'missing') return 'border-amber-700/60 bg-amber-950/40 text-amber-300';
    if (value === 'skipped') return 'border-blue-700/60 bg-blue-950/40 text-blue-300';
    return 'border-gray-700 bg-gray-900 text-gray-400';
}
</script>

<template>
    <AppLayout title="Contas Recorrentes">
        <ReportPage title="Contas Recorrentes Esperadas" :subtitle="wallet.name">
            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Período operacional</h2>
                        <p class="mt-1 text-sm text-gray-400">A recorrência só vira título financeiro quando o mês é confirmado.</p>
                    </div>
                </template>

                <div class="grid grid-cols-1 gap-4 p-6 md:grid-cols-6">
                    <label class="text-sm text-gray-300">
                        Ano
                        <input v-model.number="selectedYear" type="number" min="2000" max="2100" class="mt-1 w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" />
                    </label>
                    <label class="text-sm text-gray-300">
                        Mês
                        <select v-model.number="selectedMonth" class="mt-1 w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option v-for="month in 12" :key="month" :value="month">{{ month }}</option>
                        </select>
                    </label>
                    <div class="flex items-end">
                        <button type="button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500" @click="loadPeriod">Aplicar</button>
                    </div>

                    <div class="rounded-lg border border-gray-800 bg-gray-950 p-3">
                        <p class="text-xs uppercase text-gray-500">Esperadas</p>
                        <p class="mt-1 text-xl font-bold text-white">{{ summary.expected_count }}</p>
                    </div>
                    <div class="rounded-lg border border-amber-800/60 bg-amber-950/20 p-3">
                        <p class="text-xs uppercase text-amber-400">Não cadastradas</p>
                        <p class="mt-1 text-xl font-bold text-amber-200">{{ summary.missing_count }}</p>
                    </div>
                    <div class="rounded-lg border border-green-800/60 bg-green-950/20 p-3">
                        <p class="text-xs uppercase text-green-400">Confirmadas</p>
                        <p class="mt-1 text-xl font-bold text-green-200">{{ summary.confirmed_count }}</p>
                    </div>
                </div>
            </ReportSection>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Nova recorrência</h2>
                        <p class="mt-1 text-sm text-gray-400">Cadastre a expectativa. Nenhuma conta ou provisão será criada agora.</p>
                    </div>
                </template>

                <form class="grid grid-cols-1 gap-4 p-6 md:grid-cols-4" @submit.prevent="submitExpectation">
                    <label class="text-sm text-gray-300">
                        Tipo
                        <select v-model="form.type" class="mt-1 w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="payable">A pagar</option>
                            <option value="receivable">A receber</option>
                        </select>
                    </label>

                    <label class="text-sm text-gray-300 md:col-span-2">
                        {{ form.type === 'payable' ? 'Fornecedor' : 'Cliente' }}
                        <select v-model="form.counterparty_id" class="mt-1 w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="">Selecione...</option>
                            <option v-for="item in (form.type === 'payable' ? suppliers : customers)" :key="item.id" :value="String(item.id)">{{ item.name }}</option>
                        </select>
                        <p v-if="form.errors.supplier_id || form.errors.customer_id" class="mt-1 text-xs text-red-400">{{ form.errors.supplier_id || form.errors.customer_id }}</p>
                    </label>

                    <label class="text-sm text-gray-300">
                        Periodicidade
                        <select v-model="form.frequency" class="mt-1 w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="monthly">Mensal</option>
                            <option value="quarterly">Trimestral</option>
                            <option value="semiannual">Semestral</option>
                            <option value="annual">Anual</option>
                        </select>
                    </label>

                    <label class="text-sm text-gray-300 md:col-span-2">
                        Descrição
                        <input v-model="form.description" type="text" class="mt-1 w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="Ex.: Energia elétrica" />
                        <p v-if="form.errors.description" class="mt-1 text-xs text-red-400">{{ form.errors.description }}</p>
                    </label>

                    <label class="text-sm text-gray-300">
                        Dia do vencimento
                        <input v-model.number="form.due_day" type="number" min="1" max="31" class="mt-1 w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" />
                    </label>

                    <label class="text-sm text-gray-300">
                        Comportamento do valor
                        <select v-model="form.amount_mode" class="mt-1 w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="variable">Variável</option>
                            <option value="fixed">Fixo</option>
                        </select>
                    </label>

                    <label class="text-sm text-gray-300">
                        Valor previsto
                        <input v-model="form.expected_amount" type="text" inputmode="decimal" class="mt-1 w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="0,00" />
                        <p v-if="form.errors.expected_amount_cents" class="mt-1 text-xs text-red-400">{{ form.errors.expected_amount_cents }}</p>
                    </label>

                    <label class="text-sm text-gray-300 md:col-span-2">
                        Conta padrão
                        <select v-model="form.default_account_id" class="mt-1 w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="">Selecione...</option>
                            <option v-for="account in (form.type === 'payable' ? expenseAccounts : revenueAccounts)" :key="account.id" :value="String(account.id)">{{ account.label }}</option>
                        </select>
                        <p v-if="form.errors.default_account_id" class="mt-1 text-xs text-red-400">{{ form.errors.default_account_id }}</p>
                    </label>

                    <label class="text-sm text-gray-300">
                        Início
                        <input v-model="form.starts_on" type="date" class="mt-1 w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" />
                    </label>

                    <label class="text-sm text-gray-300">
                        Fim opcional
                        <input v-model="form.ends_on" type="date" class="mt-1 w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" />
                    </label>

                    <label class="text-sm text-gray-300 md:col-span-4">
                        Observações
                        <textarea v-model="form.notes" rows="2" class="mt-1 w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" />
                    </label>

                    <div class="md:col-span-4 flex justify-end">
                        <button type="submit" :disabled="form.processing" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">
                            Cadastrar recorrência
                        </button>
                    </div>
                </form>
            </ReportSection>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Expectativas do período</h2>
                        <p class="mt-1 text-sm text-gray-400">Confirme o valor real quando a conta do mês estiver disponível.</p>
                    </div>
                </template>

                <ReportTable :empty="expectations.length === 0" empty-message="Nenhuma recorrência cadastrada." :empty-colspan="8">
                    <template #head>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Vencimento</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Tipo</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Contraparte</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Descrição</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Periodicidade</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Previsto / Real</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Situação</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Ações</th>
                        </tr>
                    </template>

                    <template v-for="item in expectations" :key="item.id">
                        <tr class="border-b border-gray-800 align-top hover:bg-gray-800/40">
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">{{ formatDate(item.period.due_date) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-300">{{ item.type === 'payable' ? 'A pagar' : 'A receber' }}</td>
                            <td class="px-4 py-3 text-sm font-semibold text-white">{{ item.counterparty }}</td>
                            <td class="px-4 py-3 text-sm text-gray-300">
                                {{ item.description }}
                                <div class="mt-1 text-xs text-gray-500">{{ item.default_account?.code }} - {{ item.default_account?.name }}</div>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-300">{{ frequencyLabel(item.frequency) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-300">
                                <div>{{ item.expected_amount_cents ? formatCurrency(item.expected_amount_cents) : 'Variável' }}</div>
                                <div v-if="item.period.actual_amount_cents" class="mt-1 font-semibold text-white">Real: {{ formatCurrency(item.period.actual_amount_cents) }}</div>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold" :class="stateClass(item.period.state)">{{ stateLabel(item.period.state) }}</span>
                                <div v-if="item.period.title_status" class="mt-1 text-xs text-gray-500">Título: {{ item.period.title_status }}</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                                <div class="flex justify-end gap-2">
                                    <Link v-if="item.period.title_url" :href="item.period.title_url" class="rounded border border-gray-600 px-2.5 py-1.5 text-xs text-gray-200 hover:bg-gray-700">Ver título</Link>
                                    <button type="button" class="rounded border border-gray-700 px-2.5 py-1.5 text-xs text-gray-400 hover:bg-gray-800" @click="toggleStatus(item)">
                                        {{ item.status === 'active' ? 'Desativar' : 'Ativar' }}
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <tr v-if="['missing', 'missing_overdue'].includes(item.period.state)" class="border-b border-gray-800 bg-gray-950/60">
                            <td colspan="8" class="px-4 py-3">
                                <div class="flex flex-wrap items-end justify-end gap-3">
                                    <label class="text-xs text-gray-400">
                                        Valor real
                                        <input v-model="confirmations[item.id].amount" type="text" inputmode="decimal" class="mt-1 w-36 rounded border border-gray-700 bg-black px-2 py-1.5 text-sm text-white" placeholder="0,00" />
                                    </label>
                                    <label class="text-xs text-gray-400">
                                        Vencimento
                                        <input v-model="confirmations[item.id].due_date" type="date" class="mt-1 rounded border border-gray-700 bg-black px-2 py-1.5 text-sm text-white" />
                                    </label>
                                    <button type="button" class="rounded border border-gray-600 px-3 py-1.5 text-sm text-gray-300 hover:bg-gray-800" @click="skipExpectation(item)">Ignorar neste mês</button>
                                    <button type="button" class="rounded bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500" @click="confirmExpectation(item)">Confirmar e criar título</button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </ReportTable>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
