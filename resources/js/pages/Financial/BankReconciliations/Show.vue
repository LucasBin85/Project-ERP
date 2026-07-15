<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue';
import ReportTable from '@/components/reports/ReportTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

defineProps<{
    wallet: Record<string, any>;
    reconciliation: Record<string, any>;
}>();
</script>

<template>
    <AppLayout title="Auditoria de conciliação">
        <ReportPage title="Registro de conciliação" :subtitle="wallet.name">
            <div class="flex justify-end gap-3">
                <Link
                    :href="route('bank-reconciliations.index')"
                    class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                >
                    Voltar
                </Link>

                <Link
                    :href="
                        route('bank-accounts.statement', {
                            bankAccount: reconciliation.bank_account_id,
                            start_date: reconciliation.period_start?.substring(0, 10),
                            end_date: reconciliation.period_end?.substring(0, 10),
                        })
                    "
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500"
                >
                    Abrir extrato da conta
                </Link>
            </div>

            <ReportSection>
                <template #header>
                    <div class="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                        <div>
                            <p class="mb-1 text-xs font-bold tracking-wide text-gray-500 uppercase">Registro histórico para auditoria</p>
                            <h2 class="text-lg font-bold text-white">
                                {{ reconciliation.bank_account?.name }}
                            </h2>

                            <p class="text-sm text-gray-400">
                                {{ formatDate(reconciliation.period_start) }} até {{ formatDate(reconciliation.period_end) }}
                            </p>
                        </div>

                        <StatusBadge :status="reconciliation.status" />
                    </div>
                </template>

                <div class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2 xl:grid-cols-5">
                    <ReportSummaryCard label="Saldo inicial" :value="formatCurrency(reconciliation.opening_balance_cents)" tone="blue" />

                    <ReportSummaryCard label="Saldo contábil" :value="formatCurrency(reconciliation.book_balance_cents)" tone="neutral" />

                    <ReportSummaryCard label="Saldo extrato" :value="formatCurrency(reconciliation.statement_balance_cents)" tone="neutral" />

                    <ReportSummaryCard label="Saldo conciliado" :value="formatCurrency(reconciliation.reconciled_balance_cents)" tone="green" />

                    <ReportSummaryCard
                        label="Diferença"
                        :value="formatCurrency(reconciliation.difference_cents)"
                        :tone="Number(reconciliation.difference_cents) === 0 ? 'green' : 'yellow'"
                    />
                </div>

                <div v-if="reconciliation.notes" class="border-t border-gray-700 p-6">
                    <p class="text-xs text-gray-500 uppercase">Observações</p>
                    <p class="mt-1 text-sm text-gray-200">
                        {{ reconciliation.notes }}
                    </p>
                </div>
            </ReportSection>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Vínculos registrados</h2>

                        <p class="text-sm text-gray-400">
                            Relação histórica entre transações externas e lançamentos do ERP, preservada para rastreabilidade.
                        </p>
                    </div>
                </template>

                <ReportTable
                    :empty="(reconciliation.statement_items ?? []).length === 0"
                    empty-message="Nenhum item de extrato registrado."
                    :empty-colspan="7"
                >
                    <template #head>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Fonte</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Data extrato</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Descrição extrato</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase">Valor extrato</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Lançamento vinculado</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase">Valor sistema</th>
                        </tr>
                    </template>

                    <tr v-for="item in reconciliation.statement_items" :key="item.id" class="hover:bg-gray-800/50">
                        <td class="px-4 py-3 text-sm whitespace-nowrap">
                            <StatusBadge :status="item.status" />
                        </td>

                        <td class="px-4 py-3 text-sm whitespace-nowrap">
                            <div v-if="item.bank_statement_import_transaction" class="font-semibold text-green-300">OFX</div>
                            <div v-else class="font-semibold text-gray-300">Manual</div>
                            <div v-if="item.bank_statement_import_transaction?.fit_id" class="max-w-[140px] truncate text-xs text-gray-500">
                                {{ item.bank_statement_import_transaction.fit_id }}
                            </div>
                            <div
                                v-if="item.bank_statement_import_transaction?.import?.original_filename"
                                class="max-w-[140px] truncate text-xs text-gray-500"
                            >
                                {{ item.bank_statement_import_transaction.import.original_filename }}
                            </div>
                        </td>

                        <td class="px-4 py-3 text-sm whitespace-nowrap text-gray-300">
                            {{ formatDate(item.transaction_date) }}
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-200">
                            {{ item.description || 'Sem descrição' }}
                        </td>

                        <td
                            class="px-4 py-3 text-right text-sm font-semibold whitespace-nowrap"
                            :class="Number(item.amount_cents) >= 0 ? 'text-green-300' : 'text-red-300'"
                        >
                            {{ formatCurrency(item.amount_cents) }}
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-300">
                            <Link
                                v-if="item.journal_line?.journal_entry?.id"
                                :href="route('journal-entries.show', [item.journal_line.journal_entry.id])"
                                class="inline-flex items-center rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-medium text-gray-200 transition hover:bg-gray-700"
                            >
                                JE-{{ String(item.journal_line.journal_entry.id).padStart(6, '0') }} ·
                                {{ item.journal_line?.journal_entry?.description }}
                            </Link>

                            <span v-else class="text-yellow-300"> Sem vínculo registrado </span>
                        </td>

                        <td
                            class="px-4 py-3 text-right text-sm font-semibold whitespace-nowrap"
                            :class="Number(item.journal_line?.amount_cents ?? 0) >= 0 ? 'text-gray-100' : 'text-red-300'"
                        >
                            <span v-if="item.journal_line">
                                {{
                                    item.journal_line.type === 'debit'
                                        ? formatCurrency(item.journal_line.amount_cents)
                                        : formatCurrency(Number(item.journal_line.amount_cents) * -1)
                                }}
                            </span>
                            <span v-else class="text-gray-500">-</span>
                        </td>
                    </tr>
                </ReportTable>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
