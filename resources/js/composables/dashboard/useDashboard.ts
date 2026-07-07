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
        if (typeof event?.target?.showPicker !== 'function') return

        event.target.showPicker()
    }

    function clearFilters() {
        form.start_date = ''
        form.end_date = ''
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

    const chartWidth = 900
    const chartHeight = 300
    const padding = 36

    const maxValue = computed(() => {
        const values = props.chart.flatMap(item => [item.revenue_cents, item.expense_cents])
        return Math.max(...values, 1)
    })

    function chartPoint(item, index, key) {
        const denominator = Math.max(props.chart.length - 1, 1)
        const x = padding + (index * ((chartWidth - padding * 2) / denominator))
        const y = chartHeight - padding - ((Number(item[key] || 0) / maxValue.value) * (chartHeight - padding * 2))

        return { x, y, date: item.date, value: item[key] }
    }

    const revenuePoints = computed(() => props.chart.map((item, index) => chartPoint(item, index, 'revenue_cents')))
    const expensePoints = computed(() => props.chart.map((item, index) => chartPoint(item, index, 'expense_cents')))
    const pointsRevenue = computed(() => revenuePoints.value.map(point => `${point.x},${point.y}`).join(' '))
    const pointsExpense = computed(() => expensePoints.value.map(point => `${point.x},${point.y}`).join(' '))

    const chartTicks = computed(() => {
        return props.chart.map((item, index) => {
            const denominator = Math.max(props.chart.length - 1, 1)
            const x = padding + (index * ((chartWidth - padding * 2) / denominator))

            return {
                x,
                label: formatDate(item.date).slice(0, 5),
            }
        })
    })

    return {
        form,
        openDatePicker,
        clearFilters,
        goToGeneralJournal,
        goToDate,
        goToEntry,
        periodLabel,
        resultTone,
        resultMargin,
        dashboardCards,
        chartWidth,
        chartHeight,
        padding,
        revenuePoints,
        expensePoints,
        pointsRevenue,
        pointsExpense,
        chartTicks,
    }
}
