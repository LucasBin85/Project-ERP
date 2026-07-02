<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import JournalEntryActions from '@/components/accounting/journalEntries/JournalEntryActions.vue'
import JournalEntryHeader from '@/components/accounting/journalEntries/JournalEntryHeader.vue'
import JournalEntryInfoPanel from '@/components/accounting/journalEntries/JournalEntryInfoPanel.vue'
import JournalEntryLedger from '@/components/accounting/journalEntries/JournalEntryLedger.vue'
import JournalEntryReclassification from '@/components/accounting/journalEntries/JournalEntryReclassification.vue'
import JournalEntryStatusBadges from '@/components/accounting/journalEntries/JournalEntryStatusBadges.vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { computed, ref, watch } from 'vue'
import { moneyToCents } from '@/lib/input'

const props = defineProps({
    wallet: { type: Object, required: true },
    entry: { type: Object, required: true },
    classificationAccounts: { type: Array, required: true },
})

const page = usePage()

const selectedAccountId = ref('')
const selectedAmount = ref('')
const selectedMemo = ref('')

function formatCentsToInput(cents) {
    return (Number(cents || 0) / 100).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })
}

const debitLines = computed(() => props.entry.lines?.filter(line => line.type === 'debit') || [])
const creditLines = computed(() => props.entry.lines?.filter(line => line.type === 'credit') || [])

const debitTotal = computed(() => {
    return debitLines.value.reduce((total, line) => total + Number(line.amount_cents || 0), 0)
})

const creditTotal = computed(() => {
    return creditLines.value.reduce((total, line) => total + Number(line.amount_cents || 0), 0)
})

const difference = computed(() => debitTotal.value - creditTotal.value)
const isBalanced = computed(() => difference.value === 0)
const isPosted = computed(() => props.entry.status === 'posted')
const statusLabel = computed(() => isPosted.value ? 'POSTADO' : 'RASCUNHO')

const hasSuspenseLine = computed(() => {
    return props.entry.lines?.some(line => Number(line.chart_of_account_id) === Number(props.wallet.suspense_account_id))
})

const suspenseLine = computed(() => {
    return props.entry.lines?.find(line => Number(line.chart_of_account_id) === Number(props.wallet.suspense_account_id)) || null
})

const canReclassify = computed(() => !isPosted.value && hasSuspenseLine.value)
const canPost = computed(() => !isPosted.value && isBalanced.value && !hasSuspenseLine.value)

watch(
    () => suspenseLine.value?.amount_cents,
    value => {
        if (value && !selectedAmount.value) {
            selectedAmount.value = formatCentsToInput(value)
        }
    },
    { immediate: true },
)

const form = useForm({
    splits: [],
})

function submitReclassification() {
    if (!canReclassify.value) return

    const amountCents = moneyToCents(selectedAmount.value)

    if (!selectedAccountId.value || amountCents <= 0) return

    form.splits = [
        {
            chart_of_account_id: Number(selectedAccountId.value),
            amount_cents: amountCents,
            memo: selectedMemo.value || null,
        },
    ]

    form.post(route('journal-entries.reclassify', props.entry.id), {
        preserveScroll: true,
        onSuccess: () => {
            selectedMemo.value = ''
        },
    })
}

function postEntry() {
    if (!canPost.value) return

    router.post(route('journal-entries.post', props.entry.id), {}, {
        preserveScroll: true,
    })
}
</script>

<template>
    <AppLayout :title="`Lançamento #${entry.id}`">
        <div class="space-y-6">
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
                class="rounded-xl border border-green-700 bg-green-950/30 px-4 py-3 text-sm text-green-300"
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
