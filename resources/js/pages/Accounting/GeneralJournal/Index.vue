<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import DateRangeFilter from '@/components/filters/DateRangeFilter.vue'
import GeneralJournalFilters from '@/components/accounting/generalJournal/GeneralJournalFilters.vue'
import GeneralJournalTable from '@/components/accounting/generalJournal/GeneralJournalTable.vue'
import ReportPage from '@/components/reports/ReportPage.vue'
import ReportSection from '@/components/reports/ReportSection.vue'
import { useGeneralJournalIndex } from '@/composables/accounting/useGeneralJournalIndex'

const props = defineProps({
    entries: Object,
    filters: { type: Object, default: () => ({}) },
    sources: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
})

const journal = useGeneralJournalIndex(props)
</script>

<template>
    <AppLayout title="Livro Diário">
        <ReportPage
            title="Livro Diário"
            subtitle="Lançamentos contábeis em ordem cronológica."
        >
            <DateRangeFilter
                v-model:start="journal.form.start_date"
                v-model:end="journal.form.end_date"
            />

            <ReportSection>
                <GeneralJournalFilters
                    v-model:source="journal.form.source"
                    v-model:status="journal.form.status"
                    v-model:search="journal.form.search"
                    :sources="sources"
                    :statuses="statuses"
                />
            </ReportSection>

            <ReportSection>
                <template #header>
                    <h2 class="text-lg font-bold text-white">
                        Lançamentos
                    </h2>
                </template>

                <GeneralJournalTable :rows="journal.rows.value" />
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
