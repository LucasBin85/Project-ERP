<script setup>
import ReportTable from '@/components/reports/ReportTable.vue'
import { formatCurrency, formatMoneyOrDash } from '@/lib/formatters'

defineProps({
    rows: {
        type: Array,
        default: () => [],
    },
    totals: Object,
})
</script>

<template>
    <ReportTable>
        <template #head>
            <tr>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Código</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Conta</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Tipo</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Natureza</th>
                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Débitos</th>
                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Créditos</th>
                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Saldo Devedor</th>
                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Saldo Credor</th>
            </tr>
        </template>

        <tr
            v-for="row in rows"
            :key="row.account_id"
            class="hover:bg-gray-800/50"
        >
            <td class="whitespace-nowrap px-4 py-3 text-sm font-mono text-gray-300">
                {{ row.code }}
            </td>

            <td class="px-4 py-3 text-sm text-white">
                {{ row.name }}
            </td>

            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-400">
                {{ row.type }}
            </td>

            <td class="whitespace-nowrap px-4 py-3 text-sm">
                <span
                    class="rounded px-2 py-1 text-xs font-semibold"
                    :class="row.nature === 'devedora'
                        ? 'bg-green-950 text-green-300'
                        : 'bg-blue-950 text-blue-300'"
                >
                    {{ row.nature }}
                </span>
            </td>

            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-green-300">
                {{ formatMoneyOrDash(row.debit_cents) }}
            </td>

            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-blue-300">
                {{ formatMoneyOrDash(row.credit_cents) }}
            </td>

            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-green-300">
                {{ formatMoneyOrDash(row.debit_balance_cents) }}
            </td>

            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-blue-300">
                {{ formatMoneyOrDash(row.credit_balance_cents) }}
            </td>
        </tr>

        <tr v-if="rows.length === 0">
            <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-400">
                Nenhuma conta movimentada em lançamentos postados.
            </td>
        </tr>

        <template #foot>
            <tr>
                <td colspan="4" class="px-4 py-4 text-right text-sm font-bold text-white">
                    Totais
                </td>

                <td class="whitespace-nowrap px-4 py-4 text-right text-sm font-bold text-green-300">
                    {{ formatCurrency(totals.debit_cents) }}
                </td>

                <td class="whitespace-nowrap px-4 py-4 text-right text-sm font-bold text-blue-300">
                    {{ formatCurrency(totals.credit_cents) }}
                </td>

                <td class="whitespace-nowrap px-4 py-4 text-right text-sm font-bold text-green-300">
                    {{ formatCurrency(totals.debit_balance_cents) }}
                </td>

                <td class="whitespace-nowrap px-4 py-4 text-right text-sm font-bold text-blue-300">
                    {{ formatCurrency(totals.credit_balance_cents) }}
                </td>
            </tr>
        </template>
    </ReportTable>
</template>
