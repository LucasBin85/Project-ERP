<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import JournalEntriesTable from '@/components/accounting/journalEntries/JournalEntriesTable.vue'
import ReportPage from '@/components/reports/ReportPage.vue'
import ReportSection from '@/components/reports/ReportSection.vue'
import { useJournalEntriesIndex } from '@/composables/accounting/useJournalEntriesIndex'
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

const journalEntries = useJournalEntriesIndex(props.entries)
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

                <JournalEntriesTable
                    :rows="journalEntries.rows.value"
                    :entry-total="journalEntries.entryTotal"
                    :entry-date="journalEntries.entryDate"
                />
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
