<script setup lang="ts">
import ReportTable from '@/components/reports/ReportTable.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { formatCurrency, formatDate } from '@/lib/formatters'
import { Link } from '@inertiajs/vue3'

defineProps({
    rows: {
        type: Array,
        default: () => [],
    },
    entryTotal: {
        type: Function,
        required: true,
    },
    entryDate: {
        type: Function,
        required: true,
    },
})
</script>

<template>
    <ReportTable
        :empty="rows.length === 0"
        empty-message="Nenhum lançamento encontrado."
        :empty-colspan="6"
    >
        <template #head>
            <tr>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Data</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Descrição</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Origem</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Status</th>
                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Valor</th>
                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Ações</th>
            </tr>
        </template>

        <tr
            v-for="entry in rows"
            :key="entry.id"
            class="hover:bg-gray-800/50"
        >
            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                {{ formatDate(entryDate(entry)) }}
            </td>

            <td class="px-4 py-3 text-sm text-white">
                <div class="font-semibold">
                    {{ entry.description }}
                </div>

                <div class="text-sm text-gray-400">
                    #{{ entry.id }}
                </div>
            </td>

            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-400">
                {{ entry.source }}
            </td>

            <td class="whitespace-nowrap px-4 py-3 text-sm">
                <StatusBadge :status="entry.status" />
            </td>

            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-white">
                {{ formatCurrency(entryTotal(entry)) }}
            </td>

            <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                <Link
                    :href="route('journal-entries.show', entry.id)"
                    class="text-blue-400 hover:text-blue-300"
                >
                    Ver
                </Link>
            </td>
        </tr>
    </ReportTable>
</template>
