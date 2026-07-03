import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { moneyToCents } from '@/lib/input'

export function useJournalEntryShow(props) {
    const selectedAccountId = ref('')
    const selectedAmount = ref('')
    const selectedMemo = ref('')

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

    const form = useForm({
        splits: [],
    })

    function formatCentsToInput(cents) {
        return (Number(cents || 0) / 100).toLocaleString('pt-BR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })
    }

    watch(
        () => suspenseLine.value?.amount_cents,
        value => {
            if (value && !selectedAmount.value) {
                selectedAmount.value = formatCentsToInput(value)
            }
        },
        { immediate: true },
    )

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

    return {
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
    }
}
