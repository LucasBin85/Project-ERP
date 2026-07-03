import { computed, reactive, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { formatCurrency, formatDate } from '@/lib/formatters'

export function useDashboard(props) {
    const form = reactive({
        start_date: props.filters.start_date || '',
        end_date: props.filters.end_date || '',
    })

    watch(
        form,
        () => {
            if (!form.start_date || !form.end_date || form.start_date > form.end_date) return

            router.get(route('dashboard'), form, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            })
        },
        { deep: true },
    )

    function openDatePicker(event) {
        try {
            if (typeof event?.target?.showPicker === 'function') {
                event.target.showPicker()
            }
        } catch {}
    }

    function clearFilters() {
        form.start_date = ''
        form.end_date = ''
    }

    function sourceLabel(source) {
        const map = {
            manual: 'Manual',
            ofx: 'OFX',
            open_finance: 'Open Finance',
        }

        return map[source] || source || '—'
    }

    function statusLabel(status) {
        return status === 'posted' ? 'Postado' : 'Rascunho'
    }

    function goToGeneralJournal(params = {}) {
        router.get(route('general-journal.index'), {
            start_date: form.start_date,
            end_date: form.end_date,
            ...params,
        })
    }

    function goToDate(date) {
        router.get(route('general-journal.index'), {
            start_date: date,
            end_date: date,
        })
    }

    function goToEntry(entryUrl) {
        router.visit(entryUrl)
    }

    const periodLabel = computed(() => {
        if (!form.start_date || !form.end_date) return 'Todo o período'
        return `${formatDate(form.start_date)} até ${formatDate(form.end_date)}`
    })

    const resultTone = computed(() => Number(props.kpis.result_cents || 0) >= 0 ? 'positive' : 'negative')

    const resultMargin = computed(() => {
        const revenue = Number(props.kpis.revenue_cents || 0)
        const result = Number(props.kpis.result_cents || 0)

        if (!revenue) return '0,00%'
        return `${((result / revenue) * 100).toFixed(2).replace('.', ',')}%`
    })

    const dashboardCards = computed(() => [
        {
            label: 'Saldo',
            value: formatCurrency(props.kpis.balance_cents),
            helper: 'Resultado acumulado da carteira',
            tone: Number(props.kpis.balance_cents || 0) >= 0 ? 'positive' : 'negative',
            action: () => goToGeneralJournal(),
        },
        {
            label: 'Receitas',
            value: formatCurrency(props.kpis.revenue_cents),
            helper: 'Entradas reconhecidas no período',
            tone: 'positive',
            action: () => goToGeneralJournal({ search: 'receita' }),
        },
        {
            label: 'Despesas',
            value: formatCurrency(props.kpis.expense_cents),
            helper: 'Saídas reconhecidas no período',
            tone: 'negative',
            action: () => goToGeneralJournal({ search: 'despesa' }),
        },
        {
            label: 'Resultado',
            value: formatCurrency(props.kpis.result_cents),
            helper: `${resultMargin.value} sobre receitas`,
            tone: resultTone.value,
            action: () => goToGeneralJournal(),
        },
    ])

    return {
        form,
        openDatePicker,
        clearFilters,
        sourceLabel,
        statusLabel,
        goToGeneralJournal,
        goToDate,
        goToEntry,
        periodLabel,
        resultTone,
        resultMargin,
        dashboardCards,
    }
}
