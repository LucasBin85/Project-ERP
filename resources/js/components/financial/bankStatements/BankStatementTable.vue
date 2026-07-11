<script setup lang="ts">
import ReportTable from '@/components/reports/ReportTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { Link } from '@inertiajs/vue3';

defineProps<{
    transactions: Array<Record<string, any>>;
}>();
</script>

<template>
    <ReportTable
        :empty="transactions.length === 0"
        empty-message="Nenhuma movimentação encontrada para os filtros informados."
        :empty-colspan="8"
    >
        <template #head>
            <tr>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Data</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Descrição</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Origem</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Conciliação</th>
                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Entrada</th>
                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Saída</th>
                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Saldo</th>
                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Lançamento</th>
            </tr>
        </template>

        <tr
            v-for="transaction in transactions"
            :key="transaction.id"
            class="hover:bg-gray-800/50"
        >
            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                {{ formatDate(transaction.date) }}
            </td>

            <td class="px-4 py-3 text-sm">
                <div class="font-semibold text-white">
                    {{ transaction.description || 'Sem descrição' }}
                </div>

                <div class="mt-1 flex items-center gap-2 text-xs text-gray-500">
                    <span>#{{ transaction.id }}</span>
                    <StatusBadge :status="transaction.status" />
                </div>
            </td>

            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                {{ transaction.source_label || transaction.source || 'Manual' }}
            </td>

            <td class="whitespace-nowrap px-4 py-3 text-sm">
                <StatusBadge :status="transaction.reconciliation_status || 'pending'" />
            </td>

            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-green-300">
                {{ transaction.inflow_cents ? formatCurrency(transaction.inflow_cents) : '-' }}
            </td>

            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-red-300">
                {{ transaction.outflow_cents ? formatCurrency(transaction.outflow_cents) : '-' }}
            </td>

            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-gray-100">
                {{ formatCurrency(transaction.running_balance_cents) }}
            </td>

            <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                <Link
                    v-if="transaction.journal_entry_url"
                    :href="transaction.journal_entry_url"
                    class="inline-flex items-center rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-medium text-gray-200 transition hover:bg-gray-700"
                >
                    JE-{{ String(transaction.journal_entry_id).padStart(6, '0') }}
                </Link>
                <span v-else class="text-gray-500">-</span>
            </td>
        </tr>
    </ReportTable>
</template>
