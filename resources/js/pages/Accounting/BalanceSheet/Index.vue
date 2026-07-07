<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import BalanceSheetSections from '@/components/accounting/balanceSheet/BalanceSheetSections.vue'
import BalanceSheetStatus from '@/components/accounting/balanceSheet/BalanceSheetStatus.vue'
import BalanceSheetSummary from '@/components/accounting/balanceSheet/BalanceSheetSummary.vue'
import DateFilter from '@/components/filters/DateFilter.vue'
import ReportPage from '@/components/reports/ReportPage.vue'
import ReportSection from '@/components/reports/ReportSection.vue'
import { formatCurrency } from '@/lib/formatters'
import { useBalanceSheetIndex } from '@/composables/accounting/useBalanceSheetIndex'

const props = defineProps({
    wallet: Object,
    balanceSheet: Object,
    filters: {
        type: Object,
        default: () => ({}),
    },
})

const balance = useBalanceSheetIndex(props)
</script>

<template>
    <AppLayout title="Balanço Patrimonial">
        <ReportPage
            title="Balanço Patrimonial"
            :subtitle="wallet.name"
        >
            <DateFilter v-model="balance.form.date" />

            <BalanceSheetStatus
                :balanced="balance.isBalanced()"
                :difference="balance.balanceSheetDifference()"
            />

            <BalanceSheetSummary :totals="balanceSheet.totals" />

            <BalanceSheetSections
                :balance-sheet="balanceSheet"
                :row-padding="balance.rowPadding"
                :section-tone="balance.sectionTone"
            />

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
