<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import DateRangeFilter from '@/components/filters/DateRangeFilter.vue'
import ReportPage from '@/components/reports/ReportPage.vue'
import ReportSection from '@/components/reports/ReportSection.vue'
import TrialBalanceStatus from '@/components/accounting/trialBalance/TrialBalanceStatus.vue'
import TrialBalanceSummary from '@/components/accounting/trialBalance/TrialBalanceSummary.vue'
import TrialBalanceTable from '@/components/accounting/trialBalance/TrialBalanceTable.vue'
import { useTrialBalanceIndex } from '@/composables/accounting/useTrialBalanceIndex'

const props = defineProps({
    wallet: Object,
    trialBalance: Object,
    filters: {
        type: Object,
        default: () => ({}),
    },
})

const trial = useTrialBalanceIndex(props)
</script>

<template>
    <AppLayout title="Balancete de Verificação">
        <ReportPage
            title="Balancete de Verificação"
            :subtitle="wallet.name"
        >
            <TrialBalanceStatus
                :balanced="trial.isBalanced()"
                :difference="trial.differenceValue()"
            />

            <DateRangeFilter
                v-model:start="trial.form.start_date"
                v-model:end="trial.form.end_date"
            />

            <TrialBalanceSummary :totals="trialBalance.totals" />

            <ReportSection>
                <template #header>
                    <h2 class="text-lg font-bold text-white">
                        Contas movimentadas
                    </h2>
                </template>

                <TrialBalanceTable
                    :rows="trialBalance.rows"
                    :totals="trialBalance.totals"
                />
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
