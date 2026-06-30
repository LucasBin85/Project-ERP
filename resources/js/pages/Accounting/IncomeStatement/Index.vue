<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import DateRangeFilter from '@/components/filters/DateRangeFilter.vue'
import ReportPage from '@/components/reports/ReportPage.vue'
import ReportSection from '@/components/reports/ReportSection.vue'
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue'
import ReportTable from '@/components/reports/ReportTable.vue'
import { useAutoFilters } from '@/composables/useAutoFilters'
import { useDateRangeFilter } from '@/composables/useDateRangeFilter'
import { formatCurrency } from '@/lib/formatters'

const props = defineProps({
    wallet: Object,
    incomeStatement: Object,
    filters: {
        type: Object,
        default: () => ({}),
    },
})

const { form } = useDateRangeFilter(props.filters)

useAutoFilters(form, 'income-statement.index')

function formatPercent(value, base) {
    if (!base) return '-'

    return `${((Number(value || 0) / Number(base)) * 100).toFixed(2).replace('.', ',')}%`
}

function rowPadding(level) {
    return `${Number(level || 0) * 1.5}rem`
}

function amountClass(sectionKey) {
    return sectionKey === 'receita'
        ? 'text-green-300'
        : 'text-red-300'
}
</script>

<template>
    <AppLayout title="DRE">
        <ReportPage
            title="DRE"
            :subtitle="wallet.name"
        >
            <DateRangeFilter
                v-model:start="form.start_date"
                v-model:end="form.end_date"
            />

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <ReportSummaryCard
                    label="Receitas"
                    :value="formatCurrency(incomeStatement.totals.revenue_cents)"
                    tone="green"
                />

                <ReportSummaryCard
                    label="Despesas"
                    :value="formatCurrency(incomeStatement.totals.expense_cents)"
                    tone="red"
                />

                <ReportSummaryCard
                    label="Resultado Líquido"
                    :value="formatCurrency(incomeStatement.totals.net_income_cents)"
                    :tone="incomeStatement.totals.net_income_cents >= 0 ? 'green' : 'red'"
                />
            </div>

            <ReportSection
                v-for="section in incomeStatement.sections"
                :key="section.key"
            >
                <template #header>
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-bold text-white">
                            {{ section.title }}
                        </h2>

                        <div
                            class="text-sm font-bold"
                            :class="amountClass(section.key)"
                        >
                            {{ formatCurrency(section.total_cents) }}
                        </div>
                    </div>
                </template>

                <ReportTable
                    :empty="section.rows.length === 0"
                    :empty-message="`Nenhum lançamento encontrado em ${section.title.toLowerCase()} no período.`"
                    :empty-colspan="4"
                >
                    <template #head>
                        <tr>
                            <th class="w-[160px] px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">
                                Código
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">
                                Conta
                            </th>

                            <th class="w-[160px] px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">
                                % Receita
                            </th>

                            <th class="w-[180px] px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">
                                Valor
                            </th>
                        </tr>
                    </template>

                    <tr
                        v-for="row in section.rows"
                        :key="row.account_id"
                        class="hover:bg-gray-800/50"
                        :class="row.is_summary ? 'bg-gray-900/40' : ''"
                    >
                        <td class="w-[160px] whitespace-nowrap px-4 py-3 text-sm font-mono text-gray-300">
                            {{ row.code }}
                        </td>

                        <td
                            class="px-4 py-3 text-sm text-white"
                            :class="row.is_summary ? 'font-bold' : ''"
                        >
                            <span :style="{ paddingLeft: rowPadding(row.level) }">
                                {{ row.name }}
                            </span>
                        </td>

                        <td class="w-[160px] whitespace-nowrap px-4 py-3 text-right text-sm text-gray-400">
                            {{ formatPercent(row.amount_cents, incomeStatement.totals.revenue_cents) }}
                        </td>

                        <td
                            class="w-[180px] whitespace-nowrap px-4 py-3 text-right text-sm font-semibold"
                            :class="amountClass(section.key)"
                        >
                            {{ formatCurrency(row.amount_cents) }}
                        </td>
                    </tr>

                    <template #foot>
                        <tr>
                            <td
                                colspan="2"
                                class="px-4 py-4 text-right text-sm font-bold text-white"
                            >
                                Total {{ section.title }}
                            </td>

                            <td class="w-[160px] whitespace-nowrap px-4 py-4 text-right text-sm font-bold text-gray-300">
                                {{ formatPercent(section.total_cents, incomeStatement.totals.revenue_cents) }}
                            </td>

                            <td
                                class="w-[180px] whitespace-nowrap px-4 py-4 text-right text-sm font-bold"
                                :class="amountClass(section.key)"
                            >
                                {{ formatCurrency(section.total_cents) }}
                            </td>
                        </tr>
                    </template>
                </ReportTable>
            </ReportSection>

            <ReportSection>
                <div class="flex justify-between px-4 py-4 text-lg font-bold">
                    <span class="text-white">
                        Resultado Líquido
                    </span>

                    <div class="text-right">
                        <div
                            :class="incomeStatement.totals.net_income_cents >= 0
                                ? 'text-green-300'
                                : 'text-red-300'"
                        >
                            {{ formatCurrency(incomeStatement.totals.net_income_cents) }}
                        </div>

                        <div class="text-sm text-gray-400">
                            {{ formatPercent(incomeStatement.totals.net_income_cents, incomeStatement.totals.revenue_cents) }}
                            da receita
                        </div>
                    </div>
                </div>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>