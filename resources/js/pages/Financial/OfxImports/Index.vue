<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue';
import ReportTable from '@/components/reports/ReportTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCurrency, formatDate, formatDateTime } from '@/lib/formatters';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps<{
    wallet: Record<string, any>;
    selectedBankAccountId?: number | null;
    imports: Array<Record<string, any>>;
}>();
</script>

<template>
    <AppLayout title="Auditoria de importações OFX">
        <ReportPage title="Histórico de importações OFX" :subtitle="props.wallet?.name">
            <div v-if="selectedBankAccountId" class="flex justify-end">
                <Link
                    :href="route('bank-accounts.show', [selectedBankAccountId])"
                    class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                >
                    Resumo da conta
                </Link>
            </div>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Registros de importação</h2>
                        <p class="text-sm text-gray-400">
                            {{
                                selectedBankAccountId
                                    ? 'Auditoria dos arquivos OFX já processados nesta conta. Novas importações devem ser iniciadas no Extrato.'
                                    : 'Auditoria dos arquivos OFX já processados na carteira ativa. Novas importações devem ser iniciadas no Extrato de uma conta.'
                            }}
                        </p>
                    </div>
                </template>

                <div v-if="imports.length === 0" class="p-6 text-sm text-gray-400">Nenhuma importação OFX encontrada.</div>

                <div v-else class="space-y-6 p-6">
                    <div v-for="importItem in imports" :key="importItem.id" class="overflow-hidden rounded-xl border border-gray-700 bg-gray-900/40">
                        <div class="grid grid-cols-1 gap-4 border-b border-gray-700 p-4 xl:grid-cols-[1.5fr_1fr_auto]">
                            <div>
                                <div class="flex flex-wrap items-center gap-3">
                                    <h3 class="text-base font-bold text-white">{{ importItem.original_filename }}</h3>
                                    <StatusBadge :status="importItem.status" />
                                </div>
                                <p class="mt-1 text-sm text-gray-400">
                                    {{ importItem.bank_account?.name ?? '-' }} · importado em {{ formatDateTime(importItem.created_at) }}
                                </p>
                                <p class="mt-1 text-xs text-gray-500">
                                    Período do arquivo: {{ importItem.statement_started_at ? formatDate(importItem.statement_started_at) : '-' }} até
                                    {{ importItem.statement_ended_at ? formatDate(importItem.statement_ended_at) : '-' }}
                                </p>
                            </div>

                            <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-2">
                                <ReportSummaryCard label="Transações" :value="String(importItem.total_transactions)" tone="neutral" />
                                <ReportSummaryCard label="Importadas" :value="String(importItem.imported_transactions)" tone="green" />
                                <ReportSummaryCard label="Duplicadas" :value="String(importItem.skipped_duplicates)" tone="yellow" />
                                <ReportSummaryCard label="Entradas" :value="formatCurrency(importItem.total_in_cents)" tone="green" />
                            </div>

                            <div class="flex items-center justify-end">
                                <ReportSummaryCard label="Saídas" :value="formatCurrency(importItem.total_out_cents)" tone="red" />
                            </div>
                        </div>

                        <ReportTable
                            :empty="importItem.transactions.length === 0"
                            empty-message="Nenhuma transação registrada para esta importação."
                            :empty-colspan="7"
                        >
                            <template #head>
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Data</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Descrição</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">FITID</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase">Valor</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Lançamento</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase">Ação</th>
                                </tr>
                            </template>

                            <tr v-for="transaction in importItem.transactions" :key="transaction.id" class="hover:bg-gray-800/50">
                                <td class="px-4 py-3 text-sm whitespace-nowrap text-gray-300">{{ formatDate(transaction.posted_at) }}</td>
                                <td class="px-4 py-3 text-sm text-gray-200">{{ transaction.description }}</td>
                                <td class="px-4 py-3 text-xs text-gray-500">{{ transaction.fit_id }}</td>
                                <td
                                    class="px-4 py-3 text-right text-sm font-semibold whitespace-nowrap"
                                    :class="transaction.direction === 'in' ? 'text-green-300' : 'text-red-300'"
                                >
                                    {{ transaction.direction === 'in' ? '+' : '-' }} {{ formatCurrency(transaction.amount_cents) }}
                                </td>
                                <td class="px-4 py-3 text-sm whitespace-nowrap"><StatusBadge :status="transaction.status" /></td>
                                <td class="px-4 py-3 text-sm whitespace-nowrap text-gray-300">
                                    <span v-if="transaction.journal_entry">JE-{{ String(transaction.journal_entry.id).padStart(6, '0') }}</span>
                                    <span v-else>-</span>
                                </td>
                                <td class="px-4 py-3 text-right text-sm whitespace-nowrap">
                                    <Link
                                        v-if="transaction.journal_entry"
                                        :href="route('journal-entries.show', [transaction.journal_entry.id])"
                                        class="inline-flex items-center rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-medium text-gray-200 transition hover:bg-gray-700"
                                    >
                                        Ver
                                    </Link>
                                </td>
                            </tr>
                        </ReportTable>
                    </div>
                </div>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
