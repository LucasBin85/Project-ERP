<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import DateRangeFilter from '@/components/filters/DateRangeFilter.vue'
import LedgerAccountSummary from '@/components/accounting/ledger/LedgerAccountSummary.vue'
import LedgerEntriesTable from '@/components/accounting/ledger/LedgerEntriesTable.vue'
import LedgerFilters from '@/components/accounting/ledger/LedgerFilters.vue'
import ReportPage from '@/components/reports/ReportPage.vue'
import ReportSection from '@/components/reports/ReportSection.vue'
import { useLedgerIndex } from '@/composables/accounting/useLedgerIndex'

const props = defineProps({
    wallet: Object,
    filters: { type: Object, default: () => ({}) },
    accounts: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    selectedAccount: Object,
    summary: Object,
    entries: { type: Array, default: () => [] },
    ledgerReady: Boolean,
})

const ledger = useLedgerIndex(props)
</script>

<template>
    <AppLayout title="Livro Razão">
        <ReportPage
            title="Livro Razão"
            subtitle="Movimentações por conta contábil."
        >
            <DateRangeFilter
                v-model:start="ledger.form.start_date"
                v-model:end="ledger.form.end_date"
            />

            <ReportSection>
                <LedgerFilters
                    v-model:chart-of-account-id="ledger.form.chart_of_account_id"
                    v-model:status="ledger.form.status"
                    :accounts="accounts"
                    :statuses="statuses"
                />
            </ReportSection>

            <ReportSection v-if="selectedAccount && ledgerReady">
                <LedgerAccountSummary
                    :selected-account="selectedAccount"
                    :summary="summary"
                    :type-label="ledger.typeLabel"
                    :normal-balance-label="ledger.normalBalanceLabel"
                />
            </ReportSection>

            <ReportSection>
                <template #header>
                    <h2 class="text-lg font-bold text-white">
                        Movimentações
                    </h2>
                </template>

                <LedgerEntriesTable
                    :entries="entries"
                    :ledger-ready="ledgerReady"
                />
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
