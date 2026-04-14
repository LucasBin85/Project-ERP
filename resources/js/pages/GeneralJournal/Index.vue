<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { reactive, watch, computed  } from 'vue'
import { route } from 'ziggy-js'

const props = defineProps({
  entries: Object,
  filters: Object,
  sources: Array,
  statuses: Array,
})

const form = reactive({
  start_date: props.filters.start_date || '',
  end_date: props.filters.end_date || '',
  source: props.filters.source || '',
  status: props.filters.status || '',
  search: props.filters.search || '',
})

watch(
  form,
  () => {
    if (
      form.start_date &&
      form.end_date &&
      form.start_date > form.end_date
    ) return

    router.get(route('general-journal.index'), form, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    })
  },
  { deep: true }
)

const groupedEntries = computed(() => {
  const groups = {}

  for (const entry of props.entries.data || []) {
    const date = entry.date?.slice(0, 10) || 'sem-data'

    if (!groups[date]) {
      groups[date] = []
    }

    groups[date].push(entry)
  }

  return Object.entries(groups)
})

function clearFilters() {
  form.start_date = ''
  form.end_date = ''
  form.source = ''
  form.status = ''
  form.search = ''
}

function openDatePicker(event) {
  try {
    if (typeof event?.target?.showPicker === 'function') {
      event.target.showPicker()
    }
  } catch {}
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
  const [year, month, day] = datePart.split('-')

  return `${day}/${month}/${year}`
}

function statusLabel(status) {
  const map = {
    draft: 'Rascunho',
    posted: 'Postado',
  }

  return map[status] || status
}
</script>

<template>
  <AppLayout title="Livro Diário">
    <template #header>
      <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100">
        Livro Diário
      </h2>
    </template>

    <div class="py-6">
      <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
        <div class="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
          <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
            <div>
              <label class="text-sm">Data inicial</label>
              <input
                v-model="form.start_date"
                type="date"
                :max="form.end_date || undefined"
                class="w-full rounded-md border-gray-300 dark:bg-gray-900"
                @click="openDatePicker"
              >
            </div>

            <div>
              <label class="text-sm">Data final</label>
              <input
                v-model="form.end_date"
                type="date"
                :min="form.start_date || undefined"
                class="w-full rounded-md border-gray-300 dark:bg-gray-900"
                @click="openDatePicker"
              >
            </div>

            <div>
              <label class="text-sm">Origem</label>
              <select v-model="form.source" class="w-full rounded-md">
                <option value="">Todas</option>
                <option v-for="s in sources" :key="s.value" :value="s.value">
                  {{ s.label }}
                </option>
              </select>
            </div>

            <div>
              <label class="text-sm">Status</label>
              <select v-model="form.status" class="w-full rounded-md">
                <option value="">Todos</option>
                <option v-for="s in statuses" :key="s.value" :value="s.value">
                  {{ s.label }}
                </option>
              </select>
            </div>

            <div>
              <label class="text-sm">Busca</label>
              <input
                v-model="form.search"
                type="text"
                placeholder="Descrição..."
                class="w-full rounded-md"
              >
            </div>
          </div>

          <div class="mt-4 flex justify-end">
            <button
              class="rounded-md border px-4 py-2 text-sm"
              @click="clearFilters"
            >
              Limpar filtros
            </button>
          </div>
        </div>

        <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
          <table class="min-w-full">
            <thead class="bg-gray-50 dark:bg-gray-900">
              <tr>
                <th class="px-4 py-2 text-left text-xs">Data</th>
                <th class="px-4 py-2 text-left text-xs">Lançamento</th>
                <th class="px-4 py-2 text-left text-xs">Conta</th>
                <th class="px-4 py-2 text-left text-xs">Descrição</th>
                <th class="px-4 py-2 text-right text-xs">Débito</th>
                <th class="px-4 py-2 text-right text-xs">Crédito</th>
                <th class="px-4 py-2 text-left text-xs">Status</th>
              </tr>
            </thead>

            <tbody>
              <template v-for="[date, entries] in groupedEntries" :key="date">

                <!-- CABEÇALHO DA DATA -->
                <tr class="bg-gray-200 dark:bg-gray-800">
                  <td colspan="7" class="px-4 py-2 font-semibold text-gray-800 dark:text-gray-100">
                    📅 {{ formatDate(date) }}
                  </td>
                </tr>

                <!-- LANÇAMENTOS DO DIA -->
                <template v-for="entry in entries" :key="entry.id">

                  <!-- HEADER DO LANÇAMENTO -->
                  <tr class="bg-gray-100 dark:bg-gray-700/40">
                    <td></td>

                    <td class="px-4 py-3">
                      <Link :href="entry.entry_show_url" class="text-indigo-600">
                        {{ entry.entry_label }}
                      </Link>
                    </td>

                    <td colspan="3" class="px-4 py-3 text-gray-600 dark:text-gray-300">
                      {{ entry.description || '—' }}
                    </td>
                    <td></td>
                    <td class="px-4 py-3">
                      <span
                        class="inline-flex rounded-full px-2 py-1 text-xs"
                        :class="entry.status === 'posted'
                          ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'
                          : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300'"
                      >
                        {{ statusLabel(entry.status) }}
                      </span>
                    </td>
                  </tr>

                  <!-- LINHAS -->
                  <tr
                    v-for="line in entry.lines"
                    :key="line.id"
                    class="border-l-2 border-gray-200 dark:border-gray-700"
                  >
                    <td></td>
                    <td></td>

                    <td class="px-4 py-2">
                      <div class="font-medium">
                        {{ line.account_code }}
                      </div>
                      <div class="text-xs text-gray-500">
                        {{ line.account_name }}
                      </div>
                    </td>

                    <td class="px-4 py-2">
                      {{ line.description || '—' }}
                    </td>

                    <td class="px-4 py-2 text-right">
                      {{ formatMoney(line.debit_cents) }}
                    </td>

                    <td class="px-4 py-2 text-right">
                      {{ formatMoney(line.credit_cents) }}
                    </td>

                    <td></td>
                  </tr>

                </template>

              </template>

              <tr v-if="!entries.data.length">
                <td colspan="7" class="py-6 text-center text-gray-500">
                  Nenhum lançamento encontrado
                </td>
              </tr>
            </tbody>
          </table>

          <div class="flex flex-wrap gap-2 p-4">
            <Link
              v-for="link in entries.links"
              :key="link.label"
              :href="link.url || '#'"
              v-html="link.label"
              class="rounded px-3 py-1 text-sm"
              :class="link.active ? 'bg-indigo-600 text-white' : 'bg-gray-200 dark:bg-gray-700'"
            />
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>