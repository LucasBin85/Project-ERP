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

    function goToCashFlow() {
        router.get(route('cash-flow.index'), {
            start_date: form.start_date,
            end_date: form.end_date,
            type: 'both',
        })
    }

    function goToBankAccounts() {
        router.get(route('bank-accounts.index'))
    }

    function goToAccountsReceivable() {
        router.get(route('accounts-receivable.index'), {
            start_date: form.start_date,
            end_date: form.end_date,
            status: 'pending',
        })
    }

    function goToAccountsPayable() {
        router.get(route('accounts-payable.index'), {
            start_date: form.start_date,
            end_date: form.end_date,
            status: 'pending',
        })
    }

    function goToCreditCards() {
        router.get(route('credit-cards.index'))
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

    function visit(url) {
        if (!url) return
        router.visit(url)
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
            label: 'Saldo bancário',
            value: formatCurrency(props.kpis.cash_balance_cents),
            helper: 'Saldo contábil: somente lançamentos postados até a data final',
            tone: Number(props.kpis.cash_balance_cents || 0) >= 0 ? 'positive' : 'negative',
            badge: 'Caixa',
            action: () => goToBankAccounts(),
        },
        {
            label: 'Caixa projetado',
            value: formatCurrency(props.kpis.projected_cash_balance_cents),
            helper: 'Saldo bancário + previsões do período',
            tone: Number(props.kpis.projected_cash_balance_cents || 0) >= 0 ? 'positive' : 'negative',
            badge: 'Projetado',
            action: () => goToCashFlow(),
        },
        {
            label: 'Entradas previstas',
            value: formatCurrency(props.kpis.projected_inflow_cents),
            helper: 'Contas a receber pendentes no período',
            tone: 'positive',
            badge: 'Receber',
            action: () => goToAccountsReceivable(),
        },
        {
            label: 'Saídas previstas',
            value: formatCurrency(props.kpis.projected_outflow_cents),
            helper: 'Contas a pagar e faturas de cartão',
            tone: 'negative',
            badge: 'Pagar',
            action: () => goToAccountsPayable(),
        },
        {
            label: 'Receitas realizadas',
            value: formatCurrency(props.kpis.revenue_cents),
            helper: 'Receitas reconhecidas no período',
            tone: 'positive',
            badge: 'DRE',
            action: () => goToGeneralJournal({ search: 'receita' }),
        },
        {
            label: 'Despesas realizadas',
            value: formatCurrency(props.kpis.expense_cents),
            helper: 'Despesas reconhecidas no período',
            tone: 'negative',
            badge: 'DRE',
            action: () => goToGeneralJournal({ search: 'despesa' }),
        },
        {
            label: 'Resultado contábil',
            value: formatCurrency(props.kpis.result_cents),
            helper: `${resultMargin.value} sobre receitas`,
            tone: resultTone.value,
            badge: 'Resultado',
            action: () => goToGeneralJournal(),
        },
        {
            label: 'Vencidos',
            value: formatCurrency(Number(props.kpis.overdue_inflow_cents || 0) + Number(props.kpis.overdue_outflow_cents || 0)),
            helper: 'Recebimentos e pagamentos vencidos',
            tone: Number(props.kpis.overdue_outflow_cents || 0) > 0 ? 'negative' : 'warning',
            badge: 'Atenção',
            action: () => goToCashFlow(),
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
        goToCashFlow,
        goToBankAccounts,
        goToAccountsReceivable,
        goToAccountsPayable,
        goToCreditCards,
        goToDate,
        goToEntry,
        visit,
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
