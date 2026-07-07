<script setup>
import ReportTable from '@/components/reports/ReportTable.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { formatAccount, formatDate, formatMoneyOrDash } from '@/lib/formatters'
import { Link } from '@inertiajs/vue3'
import { route } from 'ziggy-js'

defineProps({
    rows: {
        type: Array,
        default: () => [],
    },
})
</script>

<template>
    <ReportTable
        :empty="rows.length === 0"
        empty-message="Nenhum lançamento encontrado."
        :empty-colspan="7"
    >
        <template #head>
            <tr>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Data</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Descrição</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Origem</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Status</th>
                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Débito</th>
                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Crédito</th>
                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Ações</th>
            </tr>
        </template>

        <template
            v-for="entry in rows"
            :key="entry.id"
        >
            <tr
                v-for="(line, index) in entry.lines"
                :key="`${entry.id}-${line.id}`"
                class="hover:bg-gray-800/50"
            >
                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                    {{ index === 0 ? formatDate(entry.entry_date) : '' }}
                </td>

                <td class="px-4 py-3 text-sm text-white">
                    <div v-if="index === 0" class="font-semibold">
                        {{ entry.description }}
                    </div>

                    <div class="text-sm text-gray-400">
                        {{ formatAccount(line.account_code, line.account_name) }}
                    </div>
                </td>

                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-400">
                    {{ index === 0 ? entry.source : '' }}
                </td>

                <td class="whitespace-nowrap px-4 py-3 text-sm">
                    <StatusBadge
                        v-if="index === 0"
                        :status="entry.status"
                    />
                </td>

                <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-green-300">
                    {{ formatMoneyOrDash(line.debit_cents) }}
                </td>

                <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-blue-300">
                    {{ formatMoneyOrDash(line.credit_cents) }}
                </td>

                <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                    <Link
                        v-if="index === 0"
                        :href="route('journal-entries.show', entry.id)"
                        class="text-blue-400 hover:text-blue-300"
                    >
                        Ver
                    </Link>
                </td>
            </tr>
        </template>
    </ReportTable>
</template>
