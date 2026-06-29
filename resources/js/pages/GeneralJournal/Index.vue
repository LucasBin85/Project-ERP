<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import DateRangeFilter from '@/components/filters/DateRangeFilter.vue'
import ReportPage from '@/components/reports/ReportPage.vue'
import ReportSection from '@/components/reports/ReportSection.vue'
import ReportTable from '@/components/reports/ReportTable.vue'
import { useAutoFilters } from '@/composables/useAutoFilters'
import { useDateRangeFilter } from '@/composables/useDateRangeFilter'
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import {
    formatAccount,
    formatDate,
    formatMoneyOrDash,
} from '@/lib/formatters'

const props = defineProps({
    entries: Object,
    filters: { type: Object, default: () => ({}) },
    sources: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
})

const { form } = useDateRangeFilter(props.filters)

form.source = props.filters.source ?? ''
form.status = props.filters.status ?? ''
form.search = props.filters.search ?? ''

useAutoFilters(form, 'general-journal.index')

const rows = computed(() => props.entries?.data ?? [])

</script>

<template>
    <AppLayout title="Livro Diário">
        <ReportPage
            title="Livro Diário"
            subtitle="Lançamentos contábeis em ordem cronológica."
        >
            <DateRangeFilter
                v-model:start="form.start_date"
                v-model:end="form.end_date"
            />

            <ReportSection>
                <div class="grid w-full grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">
                            Origem
                        </label>

                        <select
                            v-model="form.source"
                            class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                        >
                            <option value="">Todas</option>

                            <option
                                v-for="source in sources"
                                :key="source.value"
                                :value="source.value"
                            >
                                {{ source.label }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">
                            Status
                        </label>

                        <select
                            v-model="form.status"
                            class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                        >
                            <option value="">Todos</option>

                            <option
                                v-for="status in statuses"
                                :key="status.value"
                                :value="status.value"
                            >
                                {{ status.label }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">
                            Busca
                        </label>

                        <input
                            v-model="form.search"
                            type="text"
                            placeholder="Descrição..."
                            class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                        />
                    </div>
                </div>
            </ReportSection>

            <ReportSection>
                <template #header>
                    <h2 class="text-lg font-bold text-white">
                        Lançamentos
                    </h2>
                </template>

                <ReportTable
                    :empty="rows.length === 0"
                    empty-message="Nenhum lançamento encontrado."
                    :empty-colspan="7"
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
                                Débito
                            </th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">
                                Crédito
                            </th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">
                                Ações
                            </th>
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
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>