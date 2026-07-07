import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { todayLocal } from '@/lib/date'
import { formatCurrency } from '@/lib/formatters'
import { moneyToCents } from '@/lib/input'

type MovementType = 'entry' | 'exit'
type LineType = 'debit' | 'credit'

type Account = {
    id: number
    code: string
    name: string
    label: string
}

type JournalLineForm = {
    chart_of_account_id: number | null
    type: LineType
    amount: string
}

type JournalEntryForm = {
    entry_date: string
    description: string
    movement_type: MovementType | null
    main_account_id: number | null
    amount: string
    lines: JournalLineForm[]
}

export function useJournalEntryCreate(accounts: Account[]) {
    const dateInput = ref<HTMLInputElement | null>(null)

    const form = useForm<JournalEntryForm>({
        entry_date: todayLocal(),
        description: '',
        movement_type: null,
        main_account_id: null,
        amount: '',
        lines: [],
    })

    const errors = computed(
        () => form.errors as Partial<Record<keyof JournalEntryForm | 'lines', string>>,
    )

    const mainSide = computed<LineType | null>(() => {
        if (form.movement_type === 'entry') return 'debit'
        if (form.movement_type === 'exit') return 'credit'

        return null
    })

    const oppositeSide = computed<LineType | null>(() => {
        if (form.movement_type === 'entry') return 'credit'
        if (form.movement_type === 'exit') return 'debit'

        return null
    })

    const factCompleted = computed(() => {
        return form.entry_date.length > 0 &&
            form.description.trim().length > 0 &&
            form.movement_type !== null &&
            form.main_account_id !== null &&
            toCents(form.amount) > 0
    })

    const debitLines = computed(() =>
        form.lines
            .map((line, index) => ({ line, index }))
            .filter(item => item.line.type === 'debit'),
    )

    const creditLines = computed(() =>
        form.lines
            .map((line, index) => ({ line, index }))
            .filter(item => item.line.type === 'credit'),
    )

    const debitTotal = computed(() =>
        form.lines
            .filter(line => line.type === 'debit')
            .reduce((total, line) => total + toCents(line.amount), 0),
    )

    const creditTotal = computed(() =>
        form.lines
            .filter(line => line.type === 'credit')
            .reduce((total, line) => total + toCents(line.amount), 0),
    )

    const difference = computed(() => debitTotal.value - creditTotal.value)
    const absoluteDifference = computed(() => Math.abs(difference.value))

    const hasValidLines = computed(() => {
        return form.lines.length >= 2 &&
            form.lines.every(line => {
                return line.chart_of_account_id !== null && toCents(line.amount) > 0
            })
    })

    const isBalanced = computed(() => {
        return factCompleted.value && hasValidLines.value && difference.value === 0
    })

    const differenceLabel = computed(() => {
        if (!factCompleted.value) return 'Pendente'
        if (!hasValidLines.value) return 'Conta pendente'
        if (difference.value > 0) return 'Débito'
        if (difference.value < 0) return 'Crédito'

        return 'Balanceado'
    })

    const canSubmit = computed(() => {
        return factCompleted.value && hasValidLines.value && isBalanced.value
    })

    function openDatePicker() {
        dateInput.value?.showPicker?.()
    }

    function cancel() {
        window.history.back()
    }

    function toCents(value: string): number {
        return moneyToCents(value)
    }

    function formatCurrencyFromCents(value: number): string {
        return formatCurrency(value)
    }

    function selectedAccountName(accountId: number | null): string {
        const account = accounts.find(item => item.id === accountId)

        return account ? account.label : 'Conta principal'
    }

    function rebuildMainLine() {
        if (!factCompleted.value || !mainSide.value || !oppositeSide.value) {
            form.lines = []
            return
        }

        const existingOppositeLines = form.lines.filter(
            line => line.type === oppositeSide.value,
        )

        form.lines = [
            {
                chart_of_account_id: form.main_account_id,
                type: mainSide.value,
                amount: form.amount,
            },
            ...existingOppositeLines,
        ]

        if (existingOppositeLines.length === 0) {
            form.lines.push({
                chart_of_account_id: null,
                type: oppositeSide.value,
                amount: '',
            })
        }
    }

    watch(
        () => [
            form.entry_date,
            form.description,
            form.movement_type,
            form.main_account_id,
            form.amount,
        ],
        rebuildMainLine,
    )

    function addOppositeLine() {
        if (!oppositeSide.value) return

        form.lines.push({
            chart_of_account_id: null,
            type: oppositeSide.value,
            amount: '',
        })
    }

    function removeLine(index: number) {
        const line = form.lines[index]

        if (!line) return
        if (line.type === mainSide.value) return

        const oppositeLinesCount = form.lines.filter(
            item => item.type === oppositeSide.value,
        ).length

        if (oppositeLinesCount <= 1) return

        form.lines.splice(index, 1)
    }

    function submit() {
        rebuildMainLine()

        if (!canSubmit.value) return

        form
            .transform(data => ({
                entry_date: data.entry_date,
                description: data.description,
                lines: data.lines.map(line => ({
                    chart_of_account_id: line.chart_of_account_id,
                    type: line.type,
                    amount_cents: toCents(line.amount),
                })),
            }))
            .post(route('journal-entries.store'))
    }

    return {
        dateInput,
        form,
        errors,
        mainSide,
        oppositeSide,
        factCompleted,
        debitLines,
        creditLines,
        debitTotal,
        creditTotal,
        difference,
        absoluteDifference,
        hasValidLines,
        isBalanced,
        differenceLabel,
        canSubmit,
        openDatePicker,
        cancel,
        toCents,
        formatCurrencyFromCents,
        selectedAccountName,
        addOppositeLine,
        removeLine,
        submit,
    }
}
