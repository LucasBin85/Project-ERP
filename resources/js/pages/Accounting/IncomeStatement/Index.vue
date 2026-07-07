<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import DateRangeFilter from '@/components/filters/DateRangeFilter.vue'
import IncomeStatementResult from '@/components/accounting/incomeStatement/IncomeStatementResult.vue'
import IncomeStatementSection from '@/components/accounting/incomeStatement/IncomeStatementSection.vue'
import IncomeStatementSummary from '@/components/accounting/incomeStatement/IncomeStatementSummary.vue'
import ReportPage from '@/components/reports/ReportPage.vue'
import { useIncomeStatementIndex } from '@/composables/accounting/useIncomeStatementIndex'

const props = defineProps({
    wallet: Object,
    incomeStatement: Object,
    filters: {
        type: Object,
        default: () => ({}),
    },
})

const income = useIncomeStatementIndex(props)
</script>

<template>
    <AppLayout title="DRE">
        <ReportPage
            title="DRE"
            :subtitle="wallet.name"
        >
            <DateRangeFilter
                v-model:start="income.form.start_date"
                v-model:end="income.form.end_date"
            />

            <IncomeStatementSummary :totals="incomeStatement.totals" />

            <IncomeStatementSection
                v-for="section in incomeStatement.sections"
                :key="section.key"
                :section="section"
                :revenue-cents="incomeStatement.totals.revenue_cents"
                :format-percent="income.formatPercent"
                :row-padding="income.rowPadding"
                :amount-class="income.amountClass"
            />

            <IncomeStatementResult
                :totals="incomeStatement.totals"
                :format-percent="income.formatPercent"
            />
        </ReportPage>
    </AppLayout>
</template>
