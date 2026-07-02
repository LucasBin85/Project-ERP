<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { computed, reactive, watch } from 'vue'
import { route } from 'ziggy-js'
import { formatCurrency, formatDate } from '@/lib/formatters'

const props = defineProps({
    wallet: Object,
    filters: Object,
    kpis: Object,
    chart: Array,
    latestEntries: Array,
})

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
</script>

<template>
    <AppLayout title="Dashboard">
        <div class="space-y-6 p-6">
            <section class="rounded-2xl border border-white/10 bg-gradient-to-br from-[#10213a] to-[#0b1220] p-6 shadow">
                <div class="flex flex-col justify-between gap-6 lg:flex-row lg:items-start">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-wide text-blue-300">
                            Dashboard financeiro
                        </p>

                        <h1 class="mt-2 text-3xl font-bold text-white">
                            {{ wallet.name }}
                        </h1>

                        <p class="mt-2 text-sm text-gray-400">
                            Visão consolidada de receitas, despesas, resultado e últimos lançamentos.
                        </p>

                        <p class="mt-3 text-sm text-gray-500">
                            Período: {{ periodLabel }}
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-3 lg:min-w-[520px]">
                        <div>
                            <label class="mb-1 block text-xs font-bold uppercase text-gray-400">
                                Data inicial
                            </label>

                            <input
                                v-model="form.start_date"
                                type="date"
                                :max="form.end_date || undefined"
                                class="w-full rounded-lg border border-gray-700 bg-gray-950 px-3 py-2 text-sm text-white outline-none focus:border-blue-500"
                                @click="openDatePicker"
                            >
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-bold uppercase text-gray-400">
                                Data final
                            </label>

                            <input
                                v-model="form.end_date"
                                type="date"
                                :min="form.start_date || undefined"
                                class="w-full rounded-lg border border-gray-700 bg-gray-950 px-3 py-2 text-sm text-white outline-none focus:border-blue-500"
                                @click="openDatePicker"
                            >
                        </div>

                        <div class="flex items-end">
                            <button
                                type="button"
                                class="w-full rounded-lg border border-gray-700 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                                @click="clearFilters"
                            >
                                Limpar
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <section class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <button
                    v-for="card in dashboardCards"
                    :key="card.label"
                    type="button"
                    class="rounded-2xl border border-white/10 bg-[#111827] p-5 text-left shadow transition hover:border-blue-500/60 hover:bg-[#152238]"
                    @click="card.action"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold text-gray-400">
                                {{ card.label }}
                            </p>

                            <p
                                class="mt-3 text-2xl font-bold"
                                :class="card.tone === 'negative' ? 'text-red-300' : 'text-green-300'"
                            >
                                {{ card.value }}
                            </p>
                        </div>

                        <span
                            class="rounded-full px-3 py-1 text-xs font-bold"
                            :class="card.tone === 'negative'
                                ? 'bg-red-950/60 text-red-300'
                                : 'bg-green-950/60 text-green-300'"
                        >
                            {{ card.tone === 'negative' ? 'Saída' : 'Entrada' }}
                        </span>
                    </div>

                    <p class="mt-4 text-xs text-gray-500">
                        {{ card.helper }}
                    </p>
                </button>
            </section>

            <section class="grid grid-cols-1 gap-6 xl:grid-cols-[1.4fr_1fr]">
                <div class="rounded-2xl border border-white/10 bg-[#111827] p-5 shadow">
                    <div class="mb-5 flex items-center justify-between gap-4">
                        <div>
                            <h2 class="text-xl font-bold text-white">
                                Receitas x Despesas
                            </h2>
                            <p class="mt-1 text-sm text-gray-500">
                                Evolução diária no período filtrado.
                            </p>
                        </div>

                        <div class="flex items-center gap-4 text-sm">
                            <div class="flex items-center gap-2">
                                <span class="inline-block h-3 w-3 rounded-full bg-emerald-500"></span>
                                <span class="text-gray-400">Receitas</span>
                            </div>

                            <div class="flex items-center gap-2">
                                <span class="inline-block h-3 w-3 rounded-full bg-red-500"></span>
                                <span class="text-gray-400">Despesas</span>
                            </div>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <svg :viewBox="`0 0 ${chartWidth} ${chartHeight}`" class="min-w-[900px] w-full">
                            <line
                                :x1="padding"
                                :y1="chartHeight - padding"
                                :x2="chartWidth - padding"
                                :y2="chartHeight - padding"
                                stroke="currentColor"
                                class="text-gray-700"
                            />

                            <line
                                :x1="padding"
                                :y1="padding"
                                :x2="padding"
                                :y2="chartHeight - padding"
                                stroke="currentColor"
                                class="text-gray-700"
                            />

                            <polyline
                                :points="pointsRevenue"
                                fill="none"
                                stroke="#10b981"
                                stroke-width="3"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />

                            <polyline
                                :points="pointsExpense"
                                fill="none"
                                stroke="#ef4444"
                                stroke-width="3"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />

                            <template v-for="(point, index) in revenuePoints" :key="`revenue-${index}`">
                                <circle
                                    :cx="point.x"
                                    :cy="point.y"
                                    r="5"
                                    fill="#10b981"
                                    class="cursor-pointer"
                                    @click="goToDate(point.date)"
                                />
                            </template>

                            <template v-for="(point, index) in expensePoints" :key="`expense-${index}`">
                                <circle
                                    :cx="point.x"
                                    :cy="point.y"
                                    r="5"
                                    fill="#ef4444"
                                    class="cursor-pointer"
                                    @click="goToDate(point.date)"
                                />
                            </template>

                            <template v-for="(tick, index) in chartTicks" :key="`tick-${index}`">
                                <text
                                    :x="tick.x"
                                    :y="chartHeight - 8"
                                    text-anchor="middle"
                                    font-size="11"
                                    fill="currentColor"
                                    class="text-gray-500"
                                >
                                    {{ tick.label }}
                                </text>
                            </template>
                        </svg>
                    </div>
                </div>

                <div class="rounded-2xl border border-white/10 bg-[#111827] p-5 shadow">
                    <div class="mb-5 flex items-center justify-between">
                        <div>
                            <h2 class="text-xl font-bold text-white">
                                Resumo do período
                            </h2>
                            <p class="mt-1 text-sm text-gray-500">
                                Indicadores rápidos da carteira.
                            </p>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div class="rounded-xl border border-white/10 bg-gray-950/40 p-4">
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-400">Margem de resultado</span>
                                <span
                                    class="text-sm font-bold"
                                    :class="resultTone === 'positive' ? 'text-green-300' : 'text-red-300'"
                                >
                                    {{ resultMargin }}
                                </span>
                            </div>
                        </div>

                        <div class="rounded-xl border border-white/10 bg-gray-950/40 p-4">
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-400">Total de lançamentos recentes</span>
                                <span class="text-sm font-bold text-blue-300">
                                    {{ latestEntries.length }}
                                </span>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="w-full rounded-xl border border-blue-500/40 bg-blue-950/30 px-4 py-3 text-left text-sm font-bold text-blue-200 hover:bg-blue-950/50"
                            @click="goToGeneralJournal()"
                        >
                            Abrir Livro Diário →
                        </button>
                    </div>
                </div>
            </section>

            <section class="rounded-2xl border border-white/10 bg-[#111827] p-5 shadow">
                <div class="mb-5 flex items-center justify-between">
                    <div>
                        <h2 class="text-xl font-bold text-white">
                            Últimos lançamentos
                        </h2>
                        <p class="mt-1 text-sm text-gray-500">
                            Movimentações mais recentes da carteira ativa.
                        </p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="border-b border-gray-700">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Data</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Lançamento</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Descrição</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Origem</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Status</th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="entry in latestEntries"
                                :key="entry.id"
                                class="cursor-pointer border-b border-gray-800 hover:bg-gray-800/50"
                                @click="goToEntry(entry.entry_show_url)"
                            >
                                <td class="px-4 py-3 text-sm text-gray-300">
                                    {{ formatDate(entry.date) }}
                                </td>

                                <td class="px-4 py-3 text-sm">
                                    <Link
                                        :href="entry.entry_show_url"
                                        class="font-semibold text-blue-300 hover:text-blue-200"
                                        @click.stop
                                    >
                                        {{ entry.entry_label }}
                                    </Link>
                                </td>

                                <td class="px-4 py-3 text-sm text-gray-300">
                                    {{ entry.description || '—' }}
                                </td>

                                <td class="px-4 py-3 text-sm text-gray-300">
                                    {{ sourceLabel(entry.source) }}
                                </td>

                                <td class="px-4 py-3 text-sm">
                                    <span
                                        class="rounded px-2 py-1 text-xs font-bold"
                                        :class="entry.status === 'posted'
                                            ? 'bg-green-950/60 text-green-300'
                                            : 'bg-yellow-950/60 text-yellow-300'"
                                    >
                                        {{ statusLabel(entry.status) }}
                                    </span>
                                </td>
                            </tr>

                            <tr v-if="!latestEntries.length">
                                <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">
                                    Nenhum lançamento encontrado no período.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
