<script setup lang="ts">
import BankStatementDateRangeFilter from '@/components/financial/bankStatements/BankStatementDateRangeFilter.vue';
import BankStatementFilters from '@/components/financial/bankStatements/BankStatementFilters.vue';
import BankStatementSummary from '@/components/financial/bankStatements/BankStatementSummary.vue';
import BankStatementTable from '@/components/financial/bankStatements/BankStatementTable.vue';
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue';
import ReportTable from '@/components/reports/ReportTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useBankStatementIndex } from '@/composables/financial/useBankStatementIndex';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps<{
    wallet: Record<string, any>;
    bankAccounts: Array<Record<string, any>>;
    filters: Record<string, string>;
    statementReady: boolean;
    selectedBankAccount: Record<string, any> | null;
    summary: Record<string, number>;
    transactions: Array<Record<string, any>>;
    operations: Record<string, any>;
}>();

const bankStatement = useBankStatementIndex(props.filters as any);
</script>

<template>
    <AppLayout title="Extrato Bancário">
        <ReportPage title="Extrato Bancário" :subtitle="props.wallet?.name">
            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">
                            Filtros do extrato
                        </h2>

                        <p class="mt-1 text-sm text-gray-400">
                            O extrato é o centro operacional da conta: confira movimentos, importe OFX e concilie o período.
                        </p>
                    </div>
                </template>

                <BankStatementFilters
                    v-model:bank-account-id="bankStatement.form.bank_account_id"
                    v-model:search="bankStatement.form.search"
                    :bank-accounts="bankAccounts"
                    @clear="bankStatement.clearFilters"
                />
            </ReportSection>

            <BankStatementDateRangeFilter
                v-model:start="bankStatement.form.start_date"
                v-model:end="bankStatement.form.end_date"
            />

            <div
                v-if="!statementReady"
                class="rounded-xl border border-dashed border-gray-700 bg-gray-900/50 p-8 text-center"
            >
                <h2 class="text-lg font-bold text-white">
                    Selecione uma conta bancária para gerar o extrato
                </h2>

                <p class="mt-2 text-sm text-gray-400">
                    O período já vem preenchido com o mês atual. Depois de selecionar a conta, os filtros passam a atualizar a tela de forma dinâmica.
                </p>
            </div>

            <template v-else>
                <div class="flex flex-wrap justify-end gap-2">
                    <Link
                        v-if="operations.actions?.account_url"
                        :href="operations.actions.account_url"
                        class="rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                    >
                        Resumo da conta
                    </Link>

                    <Link
                        v-if="operations.actions?.ofx_import_url"
                        :href="operations.actions.ofx_import_url"
                        class="rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                    >
                        Importar OFX
                    </Link>

                    <Link
                        v-if="operations.actions?.reconciliation_url"
                        :href="operations.actions.reconciliation_url"
                        class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500"
                    >
                        Conciliar período
                    </Link>
                </div>

                <BankStatementSummary :summary="summary" />

                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <ReportSummaryCard
                        label="OFX pendentes"
                        :value="String(operations.pending_ofx?.count ?? 0)"
                        :tone="Number(operations.pending_ofx?.count ?? 0) > 0 ? 'yellow' : 'green'"
                    />

                    <ReportSummaryCard
                        label="Entradas OFX pendentes"
                        :value="formatCurrency(operations.pending_ofx?.total_in_cents ?? 0)"
                        tone="green"
                    />

                    <ReportSummaryCard
                        label="Saídas OFX pendentes"
                        :value="formatCurrency(operations.pending_ofx?.total_out_cents ?? 0)"
                        tone="red"
                    />
                </div>

                <ReportSection>
                    <template #header>
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 class="text-lg font-bold text-white">
                                    {{ selectedBankAccount?.name }}
                                </h2>

                                <p class="text-sm text-gray-400">
                                    Movimentações financeiras em formato de internet banking.
                                </p>
                            </div>

                            <div class="text-sm text-gray-400">
                                {{ transactions.length }} movimentação(ões)
                            </div>
                        </div>
                    </template>

                    <BankStatementTable :transactions="transactions" />
                </ReportSection>

                <ReportSection>
                    <template #header>
                        <div class="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 class="text-lg font-bold text-white">Pendências OFX do período</h2>
                                <p class="text-sm text-gray-400">Transações importadas por OFX que ainda não foram conciliadas neste banco.</p>
                            </div>

                            <Link
                                v-if="operations.actions?.reconciliation_url"
                                :href="operations.actions.reconciliation_url"
                                class="text-sm font-semibold text-indigo-300 hover:text-indigo-200"
                            >
                                Conciliar agora
                            </Link>
                        </div>
                    </template>

                    <ReportTable
                        :empty="(operations.pending_ofx?.transactions ?? []).length === 0"
                        empty-message="Nenhuma pendência OFX no período selecionado."
                        :empty-colspan="6"
                    >
                        <template #head>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Data</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Descrição</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Arquivo</th>
                                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Valor</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Lançamento</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Status</th>
                            </tr>
                        </template>

                        <tr v-for="item in operations.pending_ofx?.transactions ?? []" :key="item.id" class="hover:bg-gray-800/50">
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">{{ formatDate(item.posted_at) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-200">{{ item.description }}</td>
                            <td class="px-4 py-3 text-sm text-gray-400">{{ item.import_filename ?? '-' }}</td>
                            <td
                                class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold"
                                :class="Number(item.amount_cents) >= 0 ? 'text-green-300' : 'text-red-300'"
                            >
                                {{ formatCurrency(item.amount_cents) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm">
                                <Link
                                    v-if="item.journal_entry_id"
                                    :href="route('journal-entries.show', [item.journal_entry_id])"
                                    class="inline-flex items-center rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-medium text-gray-200 transition hover:bg-gray-700"
                                >
                                    JE-{{ String(item.journal_entry_id).padStart(6, '0') }}
                                </Link>
                                <span v-else class="text-gray-500">-</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm">
                                <StatusBadge :status="item.journal_entry_status ?? 'pending'" />
                            </td>
                        </tr>
                    </ReportTable>
                </ReportSection>

                <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                    <ReportSection>
                        <template #header>
                            <div class="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                                <div>
                                    <h2 class="text-lg font-bold text-white">Importações OFX recentes</h2>
                                    <p class="text-sm text-gray-400">Arquivos importados para esta conta.</p>
                                </div>

                                <Link
                                    v-if="operations.actions?.ofx_import_url"
                                    :href="operations.actions.ofx_import_url"
                                    class="text-sm font-semibold text-indigo-300 hover:text-indigo-200"
                                >
                                    Importar OFX
                                </Link>
                            </div>
                        </template>

                        <ReportTable
                            :empty="(operations.recent_imports ?? []).length === 0"
                            empty-message="Nenhum OFX importado para esta conta."
                            :empty-colspan="5"
                        >
                            <template #head>
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Arquivo</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Período</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Transações</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Duplicadas</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Status</th>
                                </tr>
                            </template>

                            <tr v-for="item in operations.recent_imports ?? []" :key="item.id" class="hover:bg-gray-800/50">
                                <td class="px-4 py-3 text-sm font-semibold text-white">{{ item.original_filename }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">{{ formatDate(item.statement_started_at) }} até {{ formatDate(item.statement_ended_at) }}</td>
                                <td class="px-4 py-3 text-right text-sm text-gray-300">{{ item.imported_transactions }}/{{ item.total_transactions }}</td>
                                <td class="px-4 py-3 text-right text-sm text-yellow-300">{{ item.skipped_duplicates }}</td>
                                <td class="px-4 py-3 text-sm"><StatusBadge :status="item.status" /></td>
                            </tr>
                        </ReportTable>
                    </ReportSection>

                    <ReportSection>
                        <template #header>
                            <div class="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                                <div>
                                    <h2 class="text-lg font-bold text-white">Conciliações recentes</h2>
                                    <p class="text-sm text-gray-400">Fechamentos anteriores desta conta.</p>
                                </div>

                                <Link
                                    v-if="operations.actions?.reconciliation_url"
                                    :href="operations.actions.reconciliation_url"
                                    class="text-sm font-semibold text-indigo-300 hover:text-indigo-200"
                                >
                                    Nova conciliação
                                </Link>
                            </div>
                        </template>

                        <ReportTable
                            :empty="(operations.recent_reconciliations ?? []).length === 0"
                            empty-message="Nenhuma conciliação registrada para esta conta."
                            :empty-colspan="5"
                        >
                            <template #head>
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Período</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Saldo extrato</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Diferença</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Status</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Ações</th>
                                </tr>
                            </template>

                            <tr v-for="item in operations.recent_reconciliations ?? []" :key="item.id" class="hover:bg-gray-800/50">
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">{{ formatDate(item.period_start) }} até {{ formatDate(item.period_end) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-gray-100">{{ formatCurrency(item.statement_balance_cents) }}</td>
                                <td
                                    class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold"
                                    :class="Number(item.difference_cents) === 0 ? 'text-green-300' : 'text-yellow-300'"
                                >
                                    {{ formatCurrency(item.difference_cents) }}
                                </td>
                                <td class="px-4 py-3 text-sm"><StatusBadge :status="item.status" /></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                                    <Link
                                        :href="route('bank-reconciliations.show', [item.id])"
                                        class="inline-flex items-center rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-medium text-gray-200 transition hover:bg-gray-700"
                                    >
                                        Ver
                                    </Link>
                                </td>
                            </tr>
                        </ReportTable>
                    </ReportSection>
                </div>
            </template>
        </ReportPage>
    </AppLayout>
</template>
