<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import JournalEntryActions from '@/components/accounting/journalEntries/JournalEntryActions.vue'
import JournalEntryHeader from '@/components/accounting/journalEntries/JournalEntryHeader.vue'
import JournalEntryInfoPanel from '@/components/accounting/journalEntries/JournalEntryInfoPanel.vue'
import JournalEntryLedger from '@/components/accounting/journalEntries/JournalEntryLedger.vue'
import JournalEntryReclassification from '@/components/accounting/journalEntries/JournalEntryReclassification.vue'
import JournalEntryStatusBadges from '@/components/accounting/journalEntries/JournalEntryStatusBadges.vue'
import { usePage } from '@inertiajs/vue3'
import { useJournalEntryShow } from '@/composables/accounting/useJournalEntryShow'

const props = defineProps({
    wallet: { type: Object, required: true },
    entry: { type: Object, required: true },
    classificationAccounts: { type: Array, required: true },
})

const page = usePage()

const journal = useJournalEntryShow(props)
</script>

<template>
    <AppLayout :title="`Lançamento #${entry.id}`">
        <div class="space-y-6 p-6">
            <JournalEntryHeader :entry="entry">
                <template #badges>
                    <JournalEntryStatusBadges
                        :is-posted="journal.isPosted.value"
                        :is-balanced="journal.isBalanced.value"
                        :status-label="journal.statusLabel.value"
                    />
                </template>
            </JournalEntryHeader>

            <div
                v-if="page.props.flash?.success"
                class="rounded-2xl border border-green-500/30 bg-green-950/30 px-4 py-3 text-sm font-semibold text-green-300"
            >
                {{ page.props.flash.success }}
            </div>

            <JournalEntryInfoPanel
                :entry="entry"
                :active-wallet="wallet"
            />

            <JournalEntryLedger
                :debit-lines="journal.debitLines.value"
                :credit-lines="journal.creditLines.value"
                :debit-total="journal.debitTotal.value"
                :credit-total="journal.creditTotal.value"
                :difference="journal.difference.value"
                :is-balanced="journal.isBalanced.value"
                :suspense-account-id="wallet.suspense_account_id"
            />

            <JournalEntryActions
                :can-post="journal.canPost.value"
                :can-reclassify="journal.canReclassify.value"
                :is-posted="journal.isPosted.value"
                @post="journal.postEntry"
            />

            <JournalEntryReclassification
                v-model:selected-account-id="journal.selectedAccountId.value"
                v-model:selected-amount="journal.selectedAmount.value"
                v-model:selected-memo="journal.selectedMemo.value"
                :can-reclassify="journal.canReclassify.value"
                :classification-accounts="classificationAccounts"
                :form-processing="journal.form.processing"
                @submit="journal.submitReclassification"
            />
        </div>
    </AppLayout>
</template>
