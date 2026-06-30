<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import DateFilter from '@/components/filters/DateFilter.vue'
import ReportPage from '@/components/reports/ReportPage.vue'
import ReportSection from '@/components/reports/ReportSection.vue'
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue'
import ReportTable from '@/components/reports/ReportTable.vue'
import { useAutoFilters } from '@/composables/useAutoFilters'
import { useDateFilter } from '@/composables/useDateFilter'
import { formatCurrency } from '@/lib/formatters'

const props = defineProps({
    wallet: Object,
    balanceSheet: Object,
    filters: {
        type: Object,
        default: () => ({}),
    },
})

const { form } = useDateFilter(props.filters)

useAutoFilters(form, 'balance-sheet.index')

function rowPadding(level) {
    return `${Number(level || 0) * 1.5}rem`
}

function isBalanced() {
    return Number(balanceSheetDifference()) === 0
}

function balanceSheetDifference() {
    return props.balanceSheet.totals.difference_cents || 0
}

function sectionTone(sectionKey) {
    return sectionKey === 'ativo'
        ? 'text-green-300'
        : 'text-blue-300'
}
</script>

<template>
    <AppLayout title="Balanço Patrimonial">
        <ReportPage
            title="Balanço Patrimonial"
            :subtitle="wallet.name"
        >
            <DateFilter v-model="form.date" />

            <div
                class="rounded-xl border px-4 py-3 text-sm font-bold"
                :class="isBalanced()
                    ? 'border-green-500 bg-green-950/40 text-green-300'
                    : 'border-red-500 bg-red-950/40 text-red-300'"
            >
                <template v-if="isBalanced()">
                    BALANÇO FECHADO
                </template>

                <template v-else>
                    Diferença:
                    {{ formatCurrency(Math.abs(balanceSheetDifference())) }}
                </template>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <ReportSummaryCard
                    label="Ativo"
                    :value="formatCurrency(balanceSheet.totals.assets_cents)"
                    tone="green"
                />

                <ReportSummaryCard
                    label="Passivo"
                    :value="formatCurrency(balanceSheet.totals.liabilities_cents)"
                    tone="blue"
                />

                <ReportSummaryCard
                    label="Patrimônio Líquido"
                    :value="formatCurrency(balanceSheet.totals.equity_cents)"
                    tone="blue"
                />
            </div>

<div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
    <!-- ATIVO -->
    <ReportSection>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-bold text-white">
                    Ativo
                </h2>

                <div class="text-sm font-bold text-green-300">
                    {{ formatCurrency(balanceSheet.totals.assets_cents) }}
                </div>
            </div>
        </template>

        <ReportTable
            :empty="balanceSheet.sections[0].rows.length === 0"
            empty-message="Nenhuma conta de ativo encontrada."
            :empty-colspan="3"
        >
            <template #head>
                <tr>
                    <th class="w-[120px] px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">
                        Código
                    </th>

                    <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">
                        Conta
                    </th>

                    <th class="w-[180px] px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">
                        Saldo
                    </th>
                </tr>
            </template>

            <tr
                v-for="row in balanceSheet.sections[0].rows"
                :key="row.account_id"
                class="hover:bg-gray-800/50"
                :class="row.is_summary ? 'bg-gray-900/40' : ''"
            >
                <td class="w-[120px] whitespace-nowrap px-4 py-3 text-sm font-mono text-gray-300">
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

                <td class="w-[180px] whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-green-300">
                    {{ formatCurrency(row.balance_cents) }}
                </td>
            </tr>
        </ReportTable>
    </ReportSection>

    <!-- PASSIVO + PL -->
    <ReportSection>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-bold text-white">
                    Passivo + Patrimônio Líquido
                </h2>

                <div class="text-sm font-bold text-blue-300">
                    {{ formatCurrency(balanceSheet.totals.liabilities_and_equity_cents) }}
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <div
                v-for="section in balanceSheet.sections.slice(1)"
                :key="section.key"
            >
                <div class="flex items-center justify-between border-b border-gray-700 px-4 py-3">
                    <h3 class="text-base font-bold text-white">
                        {{ section.title }}
                    </h3>

                    <span
                        class="text-sm font-bold"
                        :class="sectionTone(section.key)"
                    >
                        {{ formatCurrency(section.total_cents) }}
                    </span>
                </div>

                <ReportTable
                    :empty="section.rows.length === 0"
                    :empty-message="`Nenhuma conta de ${section.title.toLowerCase()} encontrada.`"
                    :empty-colspan="3"
                >
                    <template #head>
                        <tr>
                            <th class="w-[120px] px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">
                                Código
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">
                                Conta
                            </th>

                            <th class="w-[180px] px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">
                                Saldo
                            </th>
                        </tr>
                    </template>

                    <tr
                        v-for="row in section.rows"
                        :key="row.account_id"
                        class="hover:bg-gray-800/50"
                        :class="[
                            row.is_summary ? 'bg-gray-900/40' : '',
                            row.is_virtual ? 'border-t border-indigo-700/50 bg-indigo-950/20' : '',
                        ]"
                    >
                        <td class="w-[120px] whitespace-nowrap px-4 py-3 text-sm font-mono text-gray-300">
                            {{ row.code || '-' }}
                        </td>

                        <td
                            class="px-4 py-3 text-sm text-white"
                            :class="row.is_summary || row.is_virtual ? 'font-bold' : ''"
                        >
                            <span :style="{ paddingLeft: rowPadding(row.level) }">
                                {{ row.name }}
                            </span>
                        </td>

                        <td
                            class="w-[180px] whitespace-nowrap px-4 py-3 text-right text-sm font-semibold"
                            :class="sectionTone(section.key)"
                        >
                            {{ formatCurrency(row.balance_cents) }}
                        </td>
                    </tr>
                </ReportTable>
            </div>
        </div>
    </ReportSection>
</div>

            <ReportSection>
                <div class="grid grid-cols-1 gap-4 px-4 py-4 text-lg font-bold md:grid-cols-2">
                    <div class="flex justify-between">
                        <span class="text-white">Total Ativo</span>
                        <span class="text-green-300">
                            {{ formatCurrency(balanceSheet.totals.assets_cents) }}
                        </span>
                    </div>

                    <div class="flex justify-between">
                        <span class="text-white">Passivo + Patrimônio Líquido</span>
                        <span class="text-blue-300">
                            {{ formatCurrency(balanceSheet.totals.liabilities_and_equity_cents) }}
                        </span>
                    </div>
                </div>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>