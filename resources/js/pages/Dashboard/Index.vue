<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { computed, reactive, watch } from 'vue'
import { route } from 'ziggy-js'
import { formatCurrency as formatMoney, formatDate } from '@/lib/formatters'

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
  { deep: true }
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

const chartWidth = 900
const chartHeight = 280
const padding = 32

const maxValue = computed(() => {
  const values = props.chart.flatMap(item => [item.revenue_cents, item.expense_cents])
  return Math.max(...values, 1)
})

const pointsRevenue = computed(() => {
  if (!props.chart.length) return ''

  return props.chart.map((item, index) => {
    const x = padding + (index * ((chartWidth - padding * 2) / Math.max(props.chart.length - 1, 1)))
    const y = chartHeight - padding - ((item.revenue_cents / maxValue.value) * (chartHeight - padding * 2))
    return `${x},${y}`
  }).join(' ')
})

const pointsExpense = computed(() => {
  if (!props.chart.length) return ''

  return props.chart.map((item, index) => {
    const x = padding + (index * ((chartWidth - padding * 2) / Math.max(props.chart.length - 1, 1)))
    const y = chartHeight - padding - ((item.expense_cents / maxValue.value) * (chartHeight - padding * 2))
    return `${x},${y}`
  }).join(' ')
})

const chartTicks = computed(() => {
  return props.chart.map((item, index) => {
    const x = padding + (index * ((chartWidth - padding * 2) / Math.max(props.chart.length - 1, 1)))

    return {
      x,
      label: formatDate(item.date).slice(0, 5),
    }
  })
})

const revenuePoints = computed(() => {
  return props.chart.map((item, index) => {
    const x = padding + (index * ((chartWidth - padding * 2) / Math.max(props.chart.length - 1, 1)))
    const y = chartHeight - padding - ((item.revenue_cents / maxValue.value) * (chartHeight - padding * 2))

    return {
      x,
      y,
      date: item.date,
      value: item.revenue_cents,
    }
  })
})

const expensePoints = computed(() => {
  return props.chart.map((item, index) => {
    const x = padding + (index * ((chartWidth - padding * 2) / Math.max(props.chart.length - 1, 1)))
    const y = chartHeight - padding - ((item.expense_cents / maxValue.value) * (chartHeight - padding * 2))

    return {
      x,
      y,
      date: item.date,
      value: item.expense_cents,
    }
  })
})
</script>

<template>
  <AppLayout title="Dashboard">
    <template #header>
      <div class="space-y-1">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100">
          Dashboard
        </h2>
        <div class="text-sm text-gray-500 dark:text-gray-400">
          Carteira: {{ wallet.name }}
        </div>
      </div>
    </template>

    <div class="py-6">
      <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
        <div class="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
          <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <div>
              <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                Data inicial
              </label>
              <input
                v-model="form.start_date"
                type="date"
                :max="form.end_date || undefined"
                class="w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                @click="openDatePicker"
              >
            </div>

            <div>
              <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                Data final
              </label>
              <input
                v-model="form.end_date"
                type="date"
                :min="form.start_date || undefined"
                class="w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                @click="openDatePicker"
              >
            </div>

            <div class="flex items-end">
              <button
                type="button"
                class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                @click="clearFilters"
              >
                Limpar filtros
              </button>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
          <button
            type="button"
            class="rounded-lg bg-white p-5 text-left shadow transition hover:shadow-md dark:bg-gray-800"
            @click="goToGeneralJournal()"
          >
            <div class="text-sm text-gray-500 dark:text-gray-400">Saldo</div>
            <div class="mt-2 text-2xl font-semibold text-gray-900 dark:text-gray-100">
              {{ formatMoney(kpis.balance_cents) }}
            </div>
          </button>

          <button
            type="button"
            class="rounded-lg bg-white p-5 text-left shadow transition hover:shadow-md dark:bg-gray-800"
            @click="goToGeneralJournal({ search: 'receita' })"
          >
            <div class="text-sm text-gray-500 dark:text-gray-400">Receitas</div>
            <div class="mt-2 text-2xl font-semibold text-gray-900 dark:text-gray-100">
              {{ formatMoney(kpis.revenue_cents) }}
            </div>
          </button>

          <button
            type="button"
            class="rounded-lg bg-white p-5 text-left shadow transition hover:shadow-md dark:bg-gray-800"
            @click="goToGeneralJournal({ search: 'despesa' })"
          >
            <div class="text-sm text-gray-500 dark:text-gray-400">Despesas</div>
            <div class="mt-2 text-2xl font-semibold text-gray-900 dark:text-gray-100">
              {{ formatMoney(kpis.expense_cents) }}
            </div>
          </button>

          <button
            type="button"
            class="rounded-lg bg-white p-5 text-left shadow transition hover:shadow-md dark:bg-gray-800"
            @click="goToGeneralJournal()"
          >
            <div class="text-sm text-gray-500 dark:text-gray-400">Resultado</div>
            <div
              class="mt-2 text-2xl font-semibold"
              :class="kpis.result_cents >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
            >
              {{ formatMoney(kpis.result_cents) }}
            </div>
          </button>
        </div>

        <div class="rounded-lg bg-white p-5 shadow dark:bg-gray-800">
          <div class="mb-4 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
              Receitas x Despesas
            </h3>

            <div class="flex items-center gap-4 text-sm">
              <div class="flex items-center gap-2">
                <span class="inline-block h-3 w-3 rounded-full bg-emerald-500"></span>
                <span class="text-gray-600 dark:text-gray-300">Receitas</span>
              </div>

              <div class="flex items-center gap-2">
                <span class="inline-block h-3 w-3 rounded-full bg-red-500"></span>
                <span class="text-gray-600 dark:text-gray-300">Despesas</span>
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
                class="text-gray-300 dark:text-gray-600"
              />

              <line
                :x1="padding"
                :y1="padding"
                :x2="padding"
                :y2="chartHeight - padding"
                stroke="currentColor"
                class="text-gray-300 dark:text-gray-600"
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
                  class="text-gray-500 dark:text-gray-400"
                >
                  {{ tick.label }}
                </text>
              </template>
            </svg>
          </div>
        </div>

        <div class="rounded-lg bg-white p-5 shadow dark:bg-gray-800">
          <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
            Últimos lançamentos
          </h3>

          <div class="overflow-x-auto">
            <table class="min-w-full">
              <thead class="border-b border-gray-200 dark:border-gray-700">
                <tr>
                  <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    Data
                  </th>
                  <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    Lançamento
                  </th>
                  <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    Descrição
                  </th>
                  <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    Origem
                  </th>
                  <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    Status
                  </th>
                </tr>
              </thead>

              <tbody>
                <tr
                  v-for="entry in latestEntries"
                  :key="entry.id"
                  class="cursor-pointer border-b border-gray-100 dark:border-gray-700/60"
                  @click="goToEntry(entry.entry_show_url)"
                >
                  <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                    {{ formatDate(entry.date) }}
                  </td>

                  <td class="px-4 py-3 text-sm">
                    <Link
                      :href="entry.entry_show_url"
                      class="font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300"
                      @click.stop
                    >
                      {{ entry.entry_label }}
                    </Link>
                  </td>

                  <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                    {{ entry.description || '—' }}
                  </td>

                  <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                    {{ sourceLabel(entry.source) }}
                  </td>

                  <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                    {{ entry.status === 'posted' ? 'Postado' : 'Rascunho' }}
                  </td>
                </tr>

                <tr v-if="!latestEntries.length">
                  <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                    Nenhum lançamento encontrado no período.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
