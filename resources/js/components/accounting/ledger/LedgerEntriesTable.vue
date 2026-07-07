<script setup>
import ReportTable from '@/components/reports/ReportTable.vue'
import { formatCurrency, formatDate, formatMoneyOrDash } from '@/lib/formatters'
import { Link } from '@inertiajs/vue3'

defineProps({
    entries: {
        type: Array,
        default: () => [],
    },
    ledgerReady: Boolean,
})
</script>

<template>
    <ReportTable
        :empty="!entries || entries.length === 0"
        empty-message="Nenhuma movimentação encontrada para os filtros informados."
        :empty-colspan="6"
    >
        <template #head>
            <tr>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Data</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Lançamento</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Descrição</th>
                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Débito</th>
                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Crédito</th>
                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Saldo</th>
            </tr>
        </template>

        <tr
            v-for="entry in entries"
            :key="entry.id"
            class="hover:bg-gray-800/50"
        >
            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                {{ formatDate(entry.date) }}
            </td>

            <td class="whitespace-nowrap px-4 py-3 text-sm">
                <Link
                    v-if="entry.entry_show_url"
                    :href="entry.entry_show_url"
                    class="text-blue-400 hover:text-blue-300"
                >
                    {{ entry.entry_label }}
                </Link>

                <span v-else class="text-gray-300">
                    {{ entry.entry_label }}
                </span>
            </td>

            <td class="px-4 py-3 text-sm text-white">
                {{ entry.description || '—' }}
            </td>

            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-green-300">
                {{ formatMoneyOrDash(entry.debit_cents) }}
            </td>

            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-blue-300">
                {{ formatMoneyOrDash(entry.credit_cents) }}
            </td>

            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-white">
                {{ formatCurrency(entry.running_balance_cents) }}
            </td>
        </tr>

        <tr v-if="ledgerReady && !entries.length">
            <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-400">
                Nenhuma movimentação encontrada para os filtros informados.
            </td>
        </tr>

        <tr v-if="!ledgerReady">
            <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-400">
                Selecione a conta e o período para visualizar o Livro Razão.
            </td>
        </tr>
    </ReportTable>
</template>
