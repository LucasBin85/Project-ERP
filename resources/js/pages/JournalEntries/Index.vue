<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import ReportPage from '@/components/reports/ReportPage.vue'
import ReportSection from '@/components/reports/ReportSection.vue'
import ReportTable from '@/components/reports/ReportTable.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { Link } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import {
    formatCurrency,
    formatDate,
} from '@/lib/formatters'

defineProps({
    wallet: {
        type: Object,
        required: true,
    },
    entries: {
        type: Object,
        required: true,
    },
})

function lineAmount(line) {
    return Number(line?.amount_cents ?? 0)
}

function entryDebitTotal(entry) {
    if (entry.debit_total !== undefined && entry.debit_total !== null) {
        return Number(entry.debit_total)
    }

    return entry.lines
        ?.filter((line) => line.type === 'debit')
        .reduce((total, line) => total + lineAmount(line), 0) ?? 0
}

function entryCreditTotal(entry) {
    if (entry.credit_total !== undefined && entry.credit_total !== null) {
        return Number(entry.credit_total)
    }

    return entry.lines
        ?.filter((line) => line.type === 'credit')
        .reduce((total, line) => total + lineAmount(line), 0) ?? 0
}

function entryTotal(entry) {
    return Math.max(entryDebitTotal(entry), entryCreditTotal(entry))
}

function formatSource(source) {
    const sources = {
        manual: 'Manual',
        ofx: 'OFX',
        open_finance: 'Open Finance',
    }

    return sources[source] ?? source ?? '-'
}

function formatPaginationLabel(label) {
    return label
        .replace(/&laquo;/g, '«')
        .replace(/&raquo;/g, '»')
        .replace(/&amp;/g, '&')
}
</script>

<template>
    <AppLayout title="Lançamentos">
        <ReportPage
            title="Lançamentos"
            :subtitle="wallet.name"
        >
            <div class="flex justify-end">
                <Link
                    :href="route('journal-entries.create')"
                    class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-500"
                >
                    Novo lançamento
                </Link>
            </div>

            <ReportSection>
                <template #header>
                    <div class="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h2 class="text-lg font-bold text-white">
                                Lançamentos contábeis
                            </h2>

                            <p class="text-sm text-gray-400">
                                Rascunhos, lançamentos postados e lançamentos importados da carteira ativa.
                            </p>
                        </div>
                    </div>
                </template>

                <ReportTable
                    :empty="entries.data.length === 0"
                    empty-message="Nenhum lançamento encontrado."
                    :empty-colspan="6"
                >
                    <template #head>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">
                                Data
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">
                                Descrição
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">
                                Origem
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">
                                Status
                            </th>

                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">
                                Valor
                            </th>

                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">
                                Ações
                            </th>
                        </tr>
                    </template>

                    <tr
                        v-for="entry in entries.data"
                        :key="entry.id"
                        class="hover:bg-gray-800/50"
                    >
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                            {{ formatDate(entry.entry_date) }}
                        </td>

                        <td class="px-4 py-3 text-sm">
                            <div class="font-semibold text-white">
                                {{ entry.description || 'Sem descrição' }}
                            </div>

                            <div class="text-xs text-gray-500">
                                #{{ entry.id }}
                            </div>
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                            {{ formatSource(entry.source) }}
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-sm">
                            <StatusBadge :status="entry.status" />
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-gray-100">
                            {{ formatCurrency(entryTotal(entry)) }}
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                            <Link
                                :href="route('journal-entries.show', [entry.id])"
                                class="inline-flex items-center rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-medium text-gray-200 transition hover:bg-gray-700"
                            >
                                Ver
                            </Link>
                        </td>
                    </tr>
                </ReportTable>

                <div
                    v-if="entries.links?.length > 3"
                    class="flex flex-wrap items-center justify-center gap-2 border-t border-gray-700 px-4 py-4"
                >
                    <template
                        v-for="link in entries.links"
                        :key="link.label"
                    >
                        <span
                            v-if="!link.url"
                            class="rounded-md px-3 py-1.5 text-sm text-gray-500"
                        >
                            {{ formatPaginationLabel(link.label) }}
                        </span>

                        <Link
                            v-else
                            :href="link.url"
                            class="rounded-md px-3 py-1.5 text-sm transition"
                            :class="link.active
                                ? 'bg-indigo-600 text-white'
                                : 'bg-gray-800 text-gray-300 hover:bg-gray-700'"
                        >
                            {{ formatPaginationLabel(link.label) }}
                        </Link>
                    </template>
                </div>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
