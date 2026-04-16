<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { reactive, watch, onMounted } from 'vue'
import { route } from 'ziggy-js'

const props = defineProps({
  wallet: Object,
  filters: Object,
  accounts: Array,
  statuses: Array,
  selectedAccount: Object,
  summary: Object,
  entries: Array,
  ledgerReady: Boolean,
})

function formatInputDate(date) {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')

  return `${y}-${m}-${d}`
}

function getDefaultStartDate() {
  const now = new Date()
  return formatInputDate(new Date(now.getFullYear(), now.getMonth(), 1))
}

function getDefaultEndDate() {
  return formatInputDate(new Date())
}

const form = reactive({
  chart_of_account_id: props.filters?.chart_of_account_id || '',
  start_date: props.filters?.start_date || getDefaultStartDate(),
  end_date: props.filters?.end_date || getDefaultEndDate(),
  status: props.filters?.status || '',
})

function canAutoFilter() {
  return (
    form.chart_of_account_id &&
    form.start_date &&
    form.end_date &&
    form.start_date <= form.end_date
  )
}

function submitFilters() {
  router.get(
    route('ledger.index'),
    {
      chart_of_account_id: form.chart_of_account_id || undefined,
      start_date: form.start_date || undefined,
      end_date: form.end_date || undefined,
      status: form.status || undefined,
    },
    {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    }
  )
}

watch(
  form,
  () => {
    if (!canAutoFilter()) return
    submitFilters()
  },
  { deep: true }
)

onMounted(() => {
  const cameWithAccountOnly =
    !!props.filters?.chart_of_account_id &&
    !props.filters?.start_date &&
    !props.filters?.end_date

  if (cameWithAccountOnly) {
    submitFilters()
  }
})

function clearFilters() {
  form.chart_of_account_id = ''
  form.start_date = getDefaultStartDate()
  form.end_date = getDefaultEndDate()
  form.status = ''
}

function openDatePicker(event) {
  try {
    if (typeof event?.target?.showPicker === 'function') {
      event.target.showPicker()
    }
  } catch (error) {
    // ignora se o navegador bloquear ou não suportar
  }
}

function formatMoney(cents) {
  if (cents === null || cents === undefined) return '—'

  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
  }).format(cents / 100)
}

function formatDate(value) {
  if (!value) return '—'

  const datePart = String(value).slice(0, 10)
  const parts = datePart.split('-')

  if (parts.length !== 3) return '—'

  const [year, month, day] = parts

  return `${day}/${month}/${year}`
}

function typeLabel(type) {
  const map = {
    ativo: 'Ativo',
    passivo: 'Passivo',
    receita: 'Receita',
    despesa: 'Despesa',
    patrimonio: 'Patrimônio',
  }

  return map[type] || type || '—'
}
</script>

<template>
  <AppLayout title="Livro Razão">
    <template #header>
      <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-100">
        Livro Razão
      </h2>
    </template>

    <div class="py-6">
      <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
        <div class="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
          <div class="space-y-4">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
              <div>
                <label for="chart_of_account_id" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                  Conta
                </label>
                <select
                  id="chart_of_account_id"
                  v-model="form.chart_of_account_id"
                  name="chart_of_account_id"
                  class="w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                >
                  <option value="">Selecione</option>
                  <option
                    v-for="account in accounts"
                    :key="account.id"
                    :value="String(account.id)"
                  >
                    {{ account.label }}
                  </option>
                </select>
              </div>

              <div>
                <label for="start_date" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                  Data inicial
                </label>
                <input
                  id="start_date"
                  v-model="form.start_date"
                  name="start_date"
                  type="date"
                  :max="form.end_date || undefined"
                  class="w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                  @click="openDatePicker"
                >
              </div>

              <div>
                <label for="end_date" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                  Data final
                </label>
                <input
                  id="end_date"
                  v-model="form.end_date"
                  name="end_date"
                  type="date"
                  :min="form.start_date || undefined"
                  class="w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                  @click="openDatePicker"
                >
              </div>

              <div>
                <label for="status" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                  Status
                </label>
                <select
                  id="status"
                  v-model="form.status"
                  name="status"
                  class="w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                >
                  <option value="">Todos</option>
                  <option
                    v-for="status in statuses"
                    :key="status.value"
                    :value="status.value"
                  >
                    {{ status.label }}
                  </option>
                </select>
              </div>
            </div>

            <div class="flex items-center justify-end gap-3">
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

        <div
          v-if="selectedAccount && ledgerReady"
          class="rounded-lg bg-white p-4 shadow dark:bg-gray-800"
        >
          <div class="space-y-1">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
              {{ selectedAccount.code }} - {{ selectedAccount.name }}
            </h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">
              Tipo: {{ typeLabel(selectedAccount.type) }} • Natureza:
              {{ selectedAccount.normal_balance_side === 'debit' ? 'Devedora' : 'Credora' }}
            </p>
          </div>

          <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg border p-4 dark:border-gray-700">
              <div class="text-sm text-gray-500 dark:text-gray-400">Saldo inicial</div>
              <div class="mt-1 text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ formatMoney(summary.opening_balance_cents) }}
              </div>
            </div>

            <div class="rounded-lg border p-4 dark:border-gray-700">
              <div class="text-sm text-gray-500 dark:text-gray-400">Débitos</div>
              <div class="mt-1 text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ formatMoney(summary.total_debits_cents) }}
              </div>
            </div>

            <div class="rounded-lg border p-4 dark:border-gray-700">
              <div class="text-sm text-gray-500 dark:text-gray-400">Créditos</div>
              <div class="mt-1 text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ formatMoney(summary.total_credits_cents) }}
              </div>
            </div>

            <div class="rounded-lg border p-4 dark:border-gray-700">
              <div class="text-sm text-gray-500 dark:text-gray-400">Saldo final</div>
              <div class="mt-1 text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ formatMoney(summary.closing_balance_cents) }}
              </div>
            </div>
          </div>
        </div>

        <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
              <thead class="bg-gray-50 dark:bg-gray-900/50">
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
                  <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    Débito
                  </th>
                  <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    Crédito
                  </th>
                  <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    Saldo
                  </th>
                </tr>
              </thead>

              <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                <tr
                  v-for="entry in entries"
                  :key="entry.id"
                  class="hover:bg-gray-50 dark:hover:bg-gray-700/30"
                >
                  <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                    {{ formatDate(entry.date) }}
                  </td>

                  <td class="whitespace-nowrap px-4 py-3 text-sm">
                    <Link
                      v-if="entry.entry_show_url"
                      :href="entry.entry_show_url"
                      class="font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300"
                    >
                      {{ entry.entry_label }}
                    </Link>
                    <span v-else class="text-gray-700 dark:text-gray-200">
                      {{ entry.entry_label }}
                    </span>
                  </td>

                  <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                    {{ entry.description || '—' }}
                  </td>

                  <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-200">
                    {{ formatMoney(entry.debit_cents) }}
                  </td>

                  <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-200">
                    {{ formatMoney(entry.credit_cents) }}
                  </td>

                  <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium text-gray-900 dark:text-gray-100">
                    {{ formatMoney(entry.running_balance_cents) }}
                  </td>
                </tr>

                <tr v-if="ledgerReady && !entries.length">
                  <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    Nenhuma movimentação encontrada para os filtros informados.
                  </td>
                </tr>

                <tr v-if="!ledgerReady">
                  <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    Selecione a conta e o período para visualizar o Livro Razão.
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