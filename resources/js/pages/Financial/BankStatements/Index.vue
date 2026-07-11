<script setup lang="ts">
import BankStatementDateRangeFilter from '@/components/financial/bankStatements/BankStatementDateRangeFilter.vue';
import BankStatementTable from '@/components/financial/bankStatements/BankStatementTable.vue';
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportTable from '@/components/reports/ReportTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCurrency, formatDate, formatDateTime } from '@/lib/formatters';
import { Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { route } from 'ziggy-js';

const props = defineProps<{
    wallet: Record<string, any>;
    filters: Record<string, string>;
    statementReady: boolean;
    selectedBankAccount: Record<string, any> | null;
    transactions: Array<Record<string, any>>;
    operational: Record<string, any>;
}>();

const showFilters = ref(false);
const loadingOlder = ref(false);
const loadMoreRef = ref<HTMLElement | null>(null);
let observer: IntersectionObserver | null = null;

const form = reactive({
    start_date: props.filters.start_date ?? '',
    end_date: props.filters.end_date ?? '',
    search: props.filters.search ?? '',
});

function statementRoute(params: Record<string, unknown> = {}) {
    return route('bank-accounts.statement', {
        bankAccount: props.selectedBankAccount?.id,
        ...params,
    });
}

function applyFilters() {
    if (!props.selectedBankAccount?.id || !form.start_date || !form.end_date) return;

    router.get(
        statementRoute(),
        {
            start_date: form.start_date,
            end_date: form.end_date,
            search: form.search,
        },
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
}

let filterTimeout: ReturnType<typeof setTimeout> | null = null;

watch(
    () => form.search,
    () => {
        if (filterTimeout) clearTimeout(filterTimeout);
        filterTimeout = setTimeout(applyFilters, 400);
    },
);

watch(
    () => [form.start_date, form.end_date],
    () => {
        if (!form.start_date || !form.end_date) return;
        if (form.start_date > form.end_date) form.start_date = form.end_date;
        applyFilters();
    },
);

function subtractDays(dateString: string, days: number): string {
    const date = new Date(`${dateString}T12:00:00`);
    date.setDate(date.getDate() - days);

    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-');
}

function loadOlderTransactions() {
    if (loadingOlder.value || !props.operational?.has_older_transactions || !form.start_date) return;

    loadingOlder.value = true;

    router.get(
        statementRoute(),
        {
            start_date: subtractDays(form.start_date, 90),
            end_date: form.end_date,
            search: form.search,
        },
        {
            preserveScroll: true,
            preserveState: false,
            replace: true,
            onFinish: () => {
                loadingOlder.value = false;
            },
        },
    );
}

onMounted(() => {
    if (!loadMoreRef.value) return;

    observer = new IntersectionObserver((entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
            loadOlderTransactions();
        }
    }, { rootMargin: '300px' });

    observer.observe(loadMoreRef.value);
});

onBeforeUnmount(() => {
    observer?.disconnect();
});
</script>

<template>
    <AppLayout title="Extrato Bancário">
        <ReportPage
            title="Extrato Bancário"
            :subtitle="selectedBankAccount ? `${selectedBankAccount.name} · ${wallet.name}` : wallet.name"
        >
            <div class="flex flex-wrap justify-end gap-3">
                <Link
                    v-if="operational?.actions?.account_url"
                    :href="operational.actions.account_url"
                    class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                >
                    Resumo da conta
                </Link>

                <Link
                    v-if="operational?.actions?.ofx_import_url"
                    :href="operational.actions.ofx_import_url"
                    class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                >
                    Importar OFX
                </Link>

                <Link
                    v-if="operational?.actions?.reconciliation_url"
                    :href="operational.actions.reconciliation_url"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500"
                >
                    Conciliar período
                </Link>
            </div>

            <ReportSection>
                <template #header>
                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h2 class="text-lg font-bold text-white">
                                Movimentos da conta
                            </h2>
                            <p class="mt-1 text-sm text-gray-400">
                                O extrato carrega os movimentos recentes. Ao chegar ao fim da lista, períodos anteriores são carregados automaticamente.
                            </p>
                        </div>

                        <button
                            type="button"
                            class="rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                            @click="showFilters = !showFilters"
                        >
                            {{ showFilters ? 'Ocultar filtros' : 'Filtros' }}
                        </button>
                    </div>
                </template>

                <div class="border-b border-gray-700 p-6">
                    <input
                        v-model="form.search"
                        class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                        placeholder="Buscar no extrato..."
                    />
                </div>

                <div v-if="showFilters" class="border-b border-gray-700 p-6">
                    <BankStatementDateRangeFilter
                        v-model:start="form.start_date"
                        v-model:end="form.end_date"
                    />
                </div>

                <BankStatementTable :transactions="transactions" />

                <div ref="loadMoreRef" class="border-t border-gray-700 p-6 text-center text-sm text-gray-400">
                    <button
                        v-if="operational?.has_older_transactions"
                        type="button"
                        :disabled="loadingOlder"
                        class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="loadOlderTransactions"
                    >
                        {{ loadingOlder ? 'Carregando...' : 'Carregar movimentos anteriores' }}
                    </button>
                    <span v-else>Fim do extrato disponível.</span>
                </div>
            </ReportSection>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                <ReportSection>
                    <template #header>
                        <div>
                            <h2 class="text-lg font-bold text-white">Pendências OFX</h2>
                            <p class="text-sm text-gray-400">Transações importadas ainda não conciliadas no período.</p>
                        </div>
                    </template>

                    <ReportTable
                        :empty="(operational?.pending_ofx_transactions ?? []).length === 0"
                        empty-message="Nenhuma pendência OFX no período."
                        :empty-colspan="4"
                    >
                        <template #head>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Data</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Descrição</th>
                                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Valor</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">FITID</th>
                            </tr>
                        </template>

                        <tr v-for="item in operational.pending_ofx_transactions" :key="item.id" class="hover:bg-gray-800/50">
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">{{ formatDate(item.posted_at) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-200">{{ item.description }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold" :class="item.direction === 'in' ? 'text-green-300' : 'text-red-300'">
                                {{ item.direction === 'in' ? '+' : '-' }} {{ formatCurrency(item.amount_cents) }}
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500">{{ item.fit_id }}</td>
                        </tr>
                    </ReportTable>
                </ReportSection>

                <ReportSection>
                    <template #header>
                        <div>
                            <h2 class="text-lg font-bold text-white">Importações recentes</h2>
                            <p class="text-sm text-gray-400">Últimos arquivos OFX desta conta.</p>
                        </div>
                    </template>

                    <ReportTable
                        :empty="(operational?.recent_imports ?? []).length === 0"
                        empty-message="Nenhuma importação OFX para esta conta."
                        :empty-colspan="4"
                    >
                        <template #head>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Arquivo</th>
                                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Transações</th>
                                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Duplicadas</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Status</th>
                            </tr>
                        </template>

                        <tr v-for="item in operational.recent_imports" :key="item.id" class="hover:bg-gray-800/50">
                            <td class="px-4 py-3 text-sm text-gray-200">
                                <div class="font-semibold text-white">{{ item.original_filename }}</div>
                                <div class="text-xs text-gray-500">{{ formatDateTime(item.created_at) }}</div>
                            </td>
                            <td class="px-4 py-3 text-right text-sm text-gray-300">{{ item.imported_transactions }}/{{ item.total_transactions }}</td>
                            <td class="px-4 py-3 text-right text-sm text-yellow-300">{{ item.skipped_duplicates }}</td>
                            <td class="px-4 py-3 text-sm"><StatusBadge :status="item.status" /></td>
                        </tr>
                    </ReportTable>
                </ReportSection>

                <ReportSection>
                    <template #header>
                        <div>
                            <h2 class="text-lg font-bold text-white">Conciliações recentes</h2>
                            <p class="text-sm text-gray-400">Últimos fechamentos dessa conta.</p>
                        </div>
                    </template>

                    <ReportTable
                        :empty="(operational?.recent_reconciliations ?? []).length === 0"
                        empty-message="Nenhuma conciliação registrada."
                        :empty-colspan="4"
                    >
                        <template #head>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Período</th>
                                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Diferença</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Status</th>
                                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Ação</th>
                            </tr>
                        </template>

                        <tr v-for="item in operational.recent_reconciliations" :key="item.id" class="hover:bg-gray-800/50">
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">{{ formatDate(item.period_start) }} até {{ formatDate(item.period_end) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-semibold" :class="Number(item.difference_cents) === 0 ? 'text-green-300' : 'text-yellow-300'">
                                {{ formatCurrency(item.difference_cents) }}
                            </td>
                            <td class="px-4 py-3 text-sm"><StatusBadge :status="item.status" /></td>
                            <td class="px-4 py-3 text-right text-sm">
                                <Link :href="route('bank-reconciliations.show', [item.id])" class="text-indigo-300 hover:text-indigo-200">Ver</Link>
                            </td>
                        </tr>
                    </ReportTable>
                </ReportSection>
            </div>
        </ReportPage>
    </AppLayout>
</template>
