<script setup lang="ts">
import BankStatementDateRangeFilter from '@/components/financial/bankStatements/BankStatementDateRangeFilter.vue';
import BankStatementTable from '@/components/financial/bankStatements/BankStatementTable.vue';
import OfxImportDialog from '@/components/financial/ofxImports/OfxImportDialog.vue';
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type {
    BankStatementAccount,
    BankStatementClassificationAccount,
    BankStatementFilters,
    BankStatementOperational,
    BankStatementTransaction,
    BankStatementWallet,
} from '@/types/financial/bankStatement';
import type { OfxImportPreview } from '@/types/financial/ofxImport';
import type { FinancialOperationTypeOption } from '@/types/financial/operationType';
import { Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { route } from 'ziggy-js';

const props = defineProps<{
    wallet: BankStatementWallet;
    filters: BankStatementFilters;
    selectedBankAccount: BankStatementAccount | null;
    transactions: BankStatementTransaction[];
    classificationAccounts: BankStatementClassificationAccount[];
    operationTypes: FinancialOperationTypeOption[];
    operational: BankStatementOperational;
    ofxPreview?: OfxImportPreview | null;
    flash?: {
        success?: string | null;
        error?: string | null;
    };
    errors?: Record<string, string>;
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

const accountUrl = computed(() => (props.selectedBankAccount ? route('bank-accounts.show', [props.selectedBankAccount.id]) : null));
const feedbackError = computed(() => props.flash?.error ?? Object.values(props.errors ?? {})[0] ?? null);

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

    return [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-');
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

    observer = new IntersectionObserver(
        (entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                loadOlderTransactions();
            }
        },
        { rootMargin: '300px' },
    );

    observer.observe(loadMoreRef.value);
});

onBeforeUnmount(() => {
    observer?.disconnect();
});
</script>

<template>
    <AppLayout title="Extrato">
        <ReportPage title="Extrato" :subtitle="selectedBankAccount ? `${selectedBankAccount.name} · ${wallet.name}` : wallet.name">
            <div class="flex flex-wrap justify-end gap-3">
                <Link
                    v-if="accountUrl"
                    :href="accountUrl"
                    class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                >
                    Resumo da conta
                </Link>

                <button
                    type="button"
                    class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                    :aria-expanded="showFilters"
                    aria-controls="bank-statement-filters"
                    @click="showFilters = !showFilters"
                >
                    {{ showFilters ? 'Ocultar filtros' : 'Filtros' }}
                </button>

                <OfxImportDialog v-if="selectedBankAccount" :bank-account="selectedBankAccount" :initial-preview="ofxPreview" />

                <Link
                    :href="route('accounting.pending-entries.index')"
                    class="rounded-lg border border-green-600/70 px-4 py-2 text-sm font-semibold text-green-300 hover:bg-green-950/30"
                >
                    Pendências contábeis
                </Link>
            </div>

            <div
                v-if="flash?.success && !feedbackError"
                role="status"
                class="rounded-2xl border border-green-500/30 bg-green-950/30 px-4 py-3 text-sm font-semibold text-green-300"
            >
                {{ flash.success }}
            </div>

            <div
                v-if="feedbackError"
                role="alert"
                class="rounded-2xl border border-red-500/30 bg-red-950/30 px-4 py-3 text-sm font-semibold text-red-300"
            >
                {{ feedbackError }}
            </div>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Movimentos da conta</h2>
                        <p class="mt-1 text-sm text-gray-400">
                            Consulte movimentos manuais, importados por OFX e de outras origens. Classifique e vincule pendências para deixar os itens
                            prontos para a Contabilidade.
                        </p>
                    </div>
                </template>

                <div class="border-b border-gray-700 p-6">
                    <label for="bank-statement-search" class="mb-2 block text-sm font-semibold text-gray-300">Buscar movimentos</label>
                    <input
                        id="bank-statement-search"
                        v-model="form.search"
                        class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                        placeholder="Descrição ou histórico..."
                    />
                </div>

                <div v-if="showFilters" id="bank-statement-filters" class="border-b border-gray-700 p-6">
                    <BankStatementDateRangeFilter v-model:start="form.start_date" v-model:end="form.end_date" />
                </div>

                <BankStatementTable
                    v-if="selectedBankAccount"
                    :transactions="transactions"
                    :bank-account="selectedBankAccount"
                    :classification-accounts="classificationAccounts"
                    :operation-types="operationTypes"
                />

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
        </ReportPage>
    </AppLayout>
</template>
