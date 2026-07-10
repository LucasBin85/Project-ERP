<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import DashboardAlerts from '@/components/dashboard/DashboardAlerts.vue'
import DashboardBankBalances from '@/components/dashboard/DashboardBankBalances.vue'
import DashboardCards from '@/components/dashboard/DashboardCards.vue'
import DashboardChart from '@/components/dashboard/DashboardChart.vue'
import DashboardHero from '@/components/dashboard/DashboardHero.vue'
import DashboardLatestEntries from '@/components/dashboard/DashboardLatestEntries.vue'
import DashboardSummary from '@/components/dashboard/DashboardSummary.vue'
import DashboardUpcoming from '@/components/dashboard/DashboardUpcoming.vue'
import { useDashboard } from '@/composables/dashboard/useDashboard'

const props = defineProps({
    wallet: Object,
    filters: Object,
    kpis: Object,
    chart: Array,
    latestEntries: Array,
    bankBalances: Array,
    upcoming: Array,
    alerts: Array,
})

const dashboard = useDashboard(props)
</script>

<template>
    <AppLayout title="Dashboard">
        <div class="space-y-6 p-6">
            <DashboardHero
                :wallet="wallet"
                v-model:start-date="dashboard.form.start_date"
                v-model:end-date="dashboard.form.end_date"
                :period-label="dashboard.periodLabel.value"
                @clear-filters="dashboard.clearFilters"
                @open-date-picker="dashboard.openDatePicker"
            />

            <DashboardAlerts :alerts="alerts" />

            <DashboardCards :cards="dashboard.dashboardCards.value" />

            <section class="grid grid-cols-1 gap-6 xl:grid-cols-[1.4fr_1fr]">
                <DashboardChart
                    :chart-width="dashboard.chartWidth"
                    :chart-height="dashboard.chartHeight"
                    :padding="dashboard.padding"
                    :points-revenue="dashboard.pointsRevenue.value"
                    :points-expense="dashboard.pointsExpense.value"
                    :revenue-points="dashboard.revenuePoints.value"
                    :expense-points="dashboard.expensePoints.value"
                    :chart-ticks="dashboard.chartTicks.value"
                    @go-to-date="dashboard.goToDate"
                />

                <DashboardSummary
                    :result-tone="dashboard.resultTone.value"
                    :result-margin="dashboard.resultMargin.value"
                    :latest-entries-count="latestEntries.length"
                    @open-journal="dashboard.goToGeneralJournal"
                    @open-cash-flow="dashboard.goToCashFlow"
                    @open-payables="dashboard.goToAccountsPayable"
                    @open-receivables="dashboard.goToAccountsReceivable"
                    @open-credit-cards="dashboard.goToCreditCards"
                />
            </section>

            <section class="grid grid-cols-1 gap-6 xl:grid-cols-[1fr_1.4fr]">
                <DashboardBankBalances :bank-balances="bankBalances" />
                <DashboardUpcoming :upcoming="upcoming" @open-item="dashboard.visit" />
            </section>

            <DashboardLatestEntries
                :entries="latestEntries"
                @go-to-entry="dashboard.goToEntry"
            />
        </div>
    </AppLayout>
</template>
