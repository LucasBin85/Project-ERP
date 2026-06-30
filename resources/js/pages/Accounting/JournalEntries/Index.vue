<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import ReportPage from '@/components/reports/ReportPage.vue'
import ReportSection from '@/components/reports/ReportSection.vue'
import ReportTable from '@/components/reports/ReportTable.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import {
    formatCurrency,
    formatDate,
} from '@/lib/formatters'
import { Head, Link } from '@inertiajs/vue3'

const props = defineProps({
    wallet: {
        type: Object,
        required: true,
    },
    entries: {
        type: Object,
        required: true,
    },
})

const rows = props.entries?.data ?? []

function entryTotal(entry) {
    if (entry.amount_cents !== undefined && entry.amount_cents !== null) {
        return entry.amount_cents
    }

    if (entry.debit_total_cents !== undefined && entry.debit_total_cents !== null) {
        return entry.debit_total_cents
    }

    if (entry.debit_total !== undefined && entry.debit_total !== null) {
        return entry.debit_total
    }

    if (entry.lines?.length) {
        return entry.lines
            .filter((line) => line.type === 'debit' || line.debit_cents)
            .reduce((total, line) => {
                return total + Number(line.amount_cents ?? line.debit_cents ?? 0)
            }, 0)
    }

    return 0
}

function entryDate(entry) {
    return entry.entry_date ?? entry.date
}
</script>

<template>
    <Head title="Lançamentos" />

    <AppLayout>
        <ReportPage
            title="Lançamentos"
            :subtitle="`Carteira: ${wallet.name}`"
        >
            <div class="flex justify-end">
                <Link
                    :href="route('journal-entries.create')"
                    class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500"
                >
                    Novo lançamento
                </Link>
            </div>

            <ReportSection>
                <template #header>
                    <h2 class="text-lg font-bold text-white">
                        Lançamentos
                    </h2>
                </template>

                <ReportTable
                    :empty="rows.length === 0"
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
            </ReportSection>

            <div
                v-if="entries?.links?.length"
                class="flex flex-wrap gap-2"
            >
                <Link
                    v-for="link in entries.links"
                    :key="link.label"
                    :href="link.url || '#'"
                    class="rounded border px-3 py-2 text-sm"
                    :class="[
                        link.active
                            ? 'border-blue-500 bg-blue-600 text-white'
                            : 'border-gray-700 text-gray-300 hover:bg-gray-800',
                        !link.url ? 'pointer-events-none opacity-50' : '',
                    ]"
                    v-html="link.label"
                />
            </div>
        </ReportPage>
    </AppLayout>
</template>