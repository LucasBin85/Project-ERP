<script setup lang="ts">
import ReportTable from '@/components/reports/ReportTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { formatCurrency, formatDate } from '@/lib/formatters';
import type { BankStatementTransaction } from '@/types/financial/bankStatement';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

defineProps<{
    transactions: BankStatementTransaction[];
}>();
</script>

<template>
    <ReportTable :empty="transactions.length === 0" empty-message="Nenhuma movimentação encontrada para os filtros informados." :empty-colspan="9">
        <template #head>
            <tr>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Data</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Descrição</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Origem</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Status</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Conciliação</th>
                <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase">Entrada</th>
                <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase">Saída</th>
                <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase">Saldo</th>
                <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase">Lançamento</th>
            </tr>
        </template>

        <tr v-for="transaction in transactions" :key="transaction.id" class="hover:bg-gray-800/50">
            <td class="px-4 py-3 text-sm whitespace-nowrap text-gray-300">
                {{ formatDate(transaction.date) }}
            </td>

            <td class="px-4 py-3 text-sm">
                <div class="font-semibold text-white">
                    {{ transaction.description || 'Sem descrição' }}
                </div>

                <div class="mt-1 flex items-center gap-2 text-xs text-gray-500">
                    <span>#{{ transaction.id }}</span>
                </div>
            </td>

            <td class="px-4 py-3 text-sm whitespace-nowrap text-gray-300">
                {{ transaction.source_label || transaction.source || 'Manual' }}
            </td>

            <td class="px-4 py-3 text-sm whitespace-nowrap">
                <StatusBadge :status="transaction.status" />
            </td>

            <td class="px-4 py-3 text-sm whitespace-nowrap">
                <StatusBadge :status="transaction.reconciliation_status || 'pending'" />
            </td>

            <td class="px-4 py-3 text-right text-sm font-semibold whitespace-nowrap text-green-300">
                {{ transaction.inflow_cents ? formatCurrency(transaction.inflow_cents) : '-' }}
            </td>

            <td class="px-4 py-3 text-right text-sm font-semibold whitespace-nowrap text-red-300">
                {{ transaction.outflow_cents ? formatCurrency(transaction.outflow_cents) : '-' }}
            </td>

            <td class="px-4 py-3 text-right text-sm font-semibold whitespace-nowrap text-gray-100">
                {{ formatCurrency(transaction.running_balance_cents) }}
            </td>

            <td class="px-4 py-3 text-right text-sm whitespace-nowrap">
                <Link
                    v-if="transaction.journal_entry_id"
                    :href="route('journal-entries.show', [transaction.journal_entry_id])"
                    class="inline-flex items-center rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-medium text-gray-200 transition hover:bg-gray-700"
                >
                    JE-{{ String(transaction.journal_entry_id).padStart(6, '0') }}
                </Link>
                <span v-else class="text-gray-500">-</span>
            </td>
        </tr>
    </ReportTable>
</template>
