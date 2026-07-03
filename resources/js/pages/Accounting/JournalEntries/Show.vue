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

const {
    selectedAccountId,
    selectedAmount,
    selectedMemo,
    debitLines,
    creditLines,
    debitTotal,
    creditTotal,
    difference,
    isBalanced,
    isPosted,
    statusLabel,
    canReclassify,
    canPost,
    form,
    submitReclassification,
    postEntry,
} = useJournalEntryShow(props)
</script>

<template>
    <AppLayout :title="`Lançamento #${entry.id}`">
        <div class="space-y-6 p-6">
            <JournalEntryHeader :entry="entry">
                <template #badges>
                    <JournalEntryStatusBadges
                        :is-posted="isPosted"
                        :is-balanced="isBalanced"
                        :status-label="statusLabel"
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
                :debit-lines="debitLines"
                :credit-lines="creditLines"
                :debit-total="debitTotal"
                :credit-total="creditTotal"
                :difference="difference"
                :is-balanced="isBalanced"
                :suspense-account-id="wallet.suspense_account_id"
            />

            <JournalEntryActions
                :can-post="canPost"
                :can-reclassify="canReclassify"
                :is-posted="isPosted"
                @post="postEntry"
            />

            <JournalEntryReclassification
                v-model:selected-account-id="selectedAccountId"
                v-model:selected-amount="selectedAmount"
                v-model:selected-memo="selectedMemo"
                :can-reclassify="canReclassify"
                :classification-accounts="classificationAccounts"
                :form-processing="form.processing"
                @submit="submitReclassification"
            />
        </div>
    </AppLayout>
</template>
