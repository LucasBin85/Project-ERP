<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { route } from 'ziggy-js'

interface Account {
  id: number
  code: string
  name: string
  label: string
}

type MovementType = 'entry' | 'exit'
type LineType = 'debit' | 'credit'

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

const props = defineProps<{
  accounts: Account[]
}>()

const dateInput = ref<HTMLInputElement | null>(null)

function todayLocal(): string {
  const today = new Date()
  const year = today.getFullYear()
  const month = String(today.getMonth() + 1).padStart(2, '0')
  const day = String(today.getDate()).padStart(2, '0')

  return `${year}-${month}-${day}`
}

const form = useForm<JournalEntryForm>({
  entry_date: todayLocal(),
  description: '',
  movement_type: null,
  main_account_id: null,
  amount: '',
  lines: [],
})

const errors = computed(
  () => form.errors as Partial<Record<keyof JournalEntryForm | 'lines', string>>
)

function openDatePicker() {
  dateInput.value?.showPicker?.()
}

function cancel() {
  window.history.back()
}

function parseCurrency(value: string): number {
  if (!value) return 0

  const normalized = value.replace(/\./g, '').replace(',', '.')
  const number = Number(normalized)

  return Number.isNaN(number) ? 0 : number
}

function toCents(value: string): number {
  return Math.round(parseCurrency(value) * 100)
}

function formatCurrencyFromCents(value: number): string {
  return (value / 100).toLocaleString('pt-BR', {
    style: 'currency',
    currency: 'BRL',
  })
}

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
  return (
    form.entry_date.length > 0 &&
    form.description.trim().length > 0 &&
    form.movement_type !== null &&
    form.main_account_id !== null &&
    toCents(form.amount) > 0
  )
})

function selectedAccountName(accountId: number | null): string {
  const account = props.accounts.find(item => item.id === accountId)

  return account ? account.label : 'Conta principal'
}

function rebuildMainLine() {
  if (!factCompleted.value || !mainSide.value || !oppositeSide.value) {
    form.lines = []
    return
  }

  const existingOppositeLines = form.lines.filter(
    line => line.type === oppositeSide.value
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
  rebuildMainLine
)

const debitLines = computed(() =>
  form.lines
    .map((line, index) => ({ line, index }))
    .filter(item => item.line.type === 'debit')
)

const creditLines = computed(() =>
  form.lines
    .map((line, index) => ({ line, index }))
    .filter(item => item.line.type === 'credit')
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
    item => item.type === oppositeSide.value
  ).length

  if (oppositeLinesCount <= 1) return

  form.lines.splice(index, 1)
}

const debitTotal = computed(() =>
  form.lines
    .filter(line => line.type === 'debit')
    .reduce((total, line) => total + toCents(line.amount), 0)
)

const creditTotal = computed(() =>
  form.lines
    .filter(line => line.type === 'credit')
    .reduce((total, line) => total + toCents(line.amount), 0)
)

const difference = computed(() => debitTotal.value - creditTotal.value)
const absoluteDifference = computed(() => Math.abs(difference.value))

const hasValidLines = computed(() => {
  return (
    form.lines.length >= 2 &&
    form.lines.every(line => {
      return line.chart_of_account_id !== null && toCents(line.amount) > 0
    })
  )
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
</script>

<template>
  <Head title="Novo Lançamento" />

  <AppLayout>
    <div class="p-6">
      <h1 class="mb-6 text-2xl font-bold">
        Novo Lançamento
      </h1>

      <form @submit.prevent="submit" class="space-y-6">
        <div class="rounded-lg border border-gray-700 bg-[#0b1220] p-4">
          <h2 class="mb-4 text-lg font-semibold">
            1. Fato contábil
          </h2>

          <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
              <label for="entry-date" class="mb-1 block text-sm font-medium">
                Data
              </label>

              <input
                id="entry-date"
                ref="dateInput"
                v-model="form.entry_date"
                name="entry_date"
                type="date"
                class="w-full cursor-pointer rounded border border-gray-700 bg-black px-3 py-2 text-white"
                @click="openDatePicker"
                @focus="openDatePicker"
              />

              <p v-if="errors.entry_date" class="mt-1 text-sm text-red-500">
                {{ errors.entry_date }}
              </p>
            </div>

            <div>
              <label for="description" class="mb-1 block text-sm font-medium">
                Descrição
              </label>

              <input
                id="description"
                v-model="form.description"
                name="description"
                type="text"
                class="w-full rounded border border-gray-700 bg-black px-3 py-2 text-white"
                placeholder="Ex.: Pagamento de energia elétrica"
              />

              <p v-if="errors.description" class="mt-1 text-sm text-red-500">
                {{ errors.description }}
              </p>
            </div>

            <div>
              <label for="main-account" class="mb-1 block text-sm font-medium">
                Conta principal
              </label>

              <select
                id="main-account"
                v-model="form.main_account_id"
                name="main_account_id"
                class="w-full rounded border border-gray-700 bg-black px-3 py-2 text-white"
              >
                <option :value="null" class="bg-white text-black">
                  Selecione
                </option>

                <option
                  v-for="account in props.accounts"
                  :key="account.id"
                  :value="account.id"
                  class="bg-white text-black"
                >
                  {{ account.label }}
                </option>
              </select>
            </div>

            <div>
              <label for="movement-type" class="mb-1 block text-sm font-medium">
                Movimento
              </label>

              <select
                id="movement-type"
                v-model="form.movement_type"
                name="movement_type"
                class="w-full rounded border border-gray-700 bg-black px-3 py-2 text-white"
              >
                <option :value="null" class="bg-white text-black">
                  Selecione
                </option>

                <option value="entry" class="bg-white text-black">
                  Entrada
                </option>

                <option value="exit" class="bg-white text-black">
                  Saída
                </option>
              </select>
            </div>

            <div>
              <label for="amount" class="mb-1 block text-sm font-medium">
                Valor
              </label>

              <input
                id="amount"
                v-model="form.amount"
                name="amount"
                type="text"
                placeholder="0,00"
                class="w-full rounded border border-gray-700 bg-black px-3 py-2 text-right text-white"
              />
            </div>
          </div>
        </div>

        <div
          v-if="!factCompleted"
          class="rounded border border-yellow-600 bg-yellow-950/20 p-4 text-yellow-300"
        >
          Preencha o fato contábil para liberar a contrapartida e visualizar o razonete.
        </div>

        <div
          v-else
          class="rounded-lg border border-gray-700 bg-[#0b1220] p-4"
        >
          <h2 class="mb-4 text-lg font-semibold">
            2. Contrapartida e revisão
          </h2>

          <div class="mb-5 flex justify-center">
            <div
              class="min-w-[260px] rounded-lg border px-6 py-4 text-center"
              :class="isBalanced
                ? 'border-green-500 bg-green-950/20 text-green-300'
                : 'border-red-500 bg-red-950/20 text-red-300'"
            >
              <div class="text-sm text-gray-300">
                {{ isBalanced ? 'Lançamento' : 'Diferença' }}
              </div>

              <div class="mt-1 text-2xl font-bold">
                {{ formatCurrencyFromCents(absoluteDifference) }}
              </div>

              <div class="mt-1 text-sm font-semibold">
                {{ differenceLabel }}
              </div>
            </div>
          </div>

          <div class="grid grid-cols-1 overflow-hidden rounded border border-gray-700 md:grid-cols-2">
            <div class="border-b border-gray-700 md:border-r md:border-b-0">
              <div class="bg-[#1f2e45] px-4 py-3 text-center text-lg font-semibold text-green-300">
                DÉBITO
              </div>

              <div class="space-y-3 p-4">
                <div
                  v-for="item in debitLines"
                  :key="item.index"
                  class="rounded border border-gray-700 bg-black/40 p-3"
                >
                  <div
                    v-if="item.line.type === mainSide"
                    class="grid grid-cols-1 gap-2 md:grid-cols-[1fr_150px]"
                  >
                    <div class="rounded border border-gray-700 bg-gray-900 px-3 py-2 text-gray-300">
                      {{ selectedAccountName(item.line.chart_of_account_id) }}
                    </div>

                    <div class="rounded border border-gray-700 bg-gray-900 px-3 py-2 text-right text-gray-300">
                      {{ formatCurrencyFromCents(toCents(item.line.amount)) }}
                    </div>
                  </div>

                  <div
                    v-else
                    class="grid grid-cols-1 gap-2 md:grid-cols-[1fr_150px_auto]"
                  >
                    <select
                      v-model="item.line.chart_of_account_id"
                      class="rounded border border-gray-700 bg-black px-2 py-2 text-white"
                    >
                      <option :value="null" class="bg-white text-black">
                        Selecione a conta
                      </option>

                      <option
                        v-for="account in props.accounts"
                        :key="account.id"
                        :value="account.id"
                        class="bg-white text-black"
                      >
                        {{ account.label }}
                      </option>
                    </select>

                    <input
                      v-model="item.line.amount"
                      type="text"
                      placeholder="0,00"
                      class="rounded border border-gray-700 bg-black px-2 py-2 text-right text-white"
                    />

                    <button
                      type="button"
                      class="text-red-400 hover:text-red-300"
                      @click="removeLine(item.index)"
                    >
                      Remover
                    </button>
                  </div>
                </div>

                <button
                  v-if="oppositeSide === 'debit'"
                  type="button"
                  class="w-full rounded border border-green-600 px-4 py-2 text-green-300 hover:bg-green-950/30"
                  @click="addOppositeLine"
                >
                  + Adicionar débito
                </button>
              </div>

              <div class="border-t border-gray-700 p-5 text-center">
                <div class="text-sm text-green-300">
                  Total Débito
                </div>

                <div class="mt-2 text-2xl font-bold text-green-300">
                  {{ formatCurrencyFromCents(debitTotal) }}
                </div>

                <div class="mt-4 border-b border-green-500"></div>
              </div>
            </div>

            <div>
              <div class="bg-[#1f2e45] px-4 py-3 text-center text-lg font-semibold text-blue-300">
                CRÉDITO
              </div>

              <div class="space-y-3 p-4">
                <div
                  v-for="item in creditLines"
                  :key="item.index"
                  class="rounded border border-gray-700 bg-black/40 p-3"
                >
                  <div
                    v-if="item.line.type === mainSide"
                    class="grid grid-cols-1 gap-2 md:grid-cols-[1fr_150px]"
                  >
                    <div class="rounded border border-gray-700 bg-gray-900 px-3 py-2 text-gray-300">
                      {{ selectedAccountName(item.line.chart_of_account_id) }}
                    </div>

                    <div class="rounded border border-gray-700 bg-gray-900 px-3 py-2 text-right text-gray-300">
                      {{ formatCurrencyFromCents(toCents(item.line.amount)) }}
                    </div>
                  </div>

                  <div
                    v-else
                    class="grid grid-cols-1 gap-2 md:grid-cols-[1fr_150px_auto]"
                  >
                    <select
                      v-model="item.line.chart_of_account_id"
                      class="rounded border border-gray-700 bg-black px-2 py-2 text-white"
                    >
                      <option :value="null" class="bg-white text-black">
                        Selecione a conta
                      </option>

                      <option
                        v-for="account in props.accounts"
                        :key="account.id"
                        :value="account.id"
                        class="bg-white text-black"
                      >
                        {{ account.label }}
                      </option>
                    </select>

                    <input
                      v-model="item.line.amount"
                      type="text"
                      placeholder="0,00"
                      class="rounded border border-gray-700 bg-black px-2 py-2 text-right text-white"
                    />

                    <button
                      type="button"
                      class="text-red-400 hover:text-red-300"
                      @click="removeLine(item.index)"
                    >
                      Remover
                    </button>
                  </div>
                </div>

                <button
                  v-if="oppositeSide === 'credit'"
                  type="button"
                  class="w-full rounded border border-blue-600 px-4 py-2 text-blue-300 hover:bg-blue-950/30"
                  @click="addOppositeLine"
                >
                  + Adicionar crédito
                </button>
              </div>

              <div class="border-t border-gray-700 p-5 text-center">
                <div class="text-sm text-blue-300">
                  Total Crédito
                </div>

                <div class="mt-2 text-2xl font-bold text-blue-300">
                  {{ formatCurrencyFromCents(creditTotal) }}
                </div>

                <div class="mt-4 border-b border-blue-500"></div>
              </div>
            </div>
          </div>

          <p v-if="errors.lines" class="mt-3 text-sm text-red-500">
            {{ errors.lines }}
          </p>
        </div>

        <div class="flex justify-end gap-3">
          <button
            type="button"
            class="rounded border border-gray-600 px-5 py-2 text-gray-200 hover:bg-gray-800"
            @click="cancel"
          >
            Cancelar
          </button>

          <button
            type="submit"
            :disabled="form.processing || !canSubmit"
            class="rounded bg-blue-600 px-5 py-2 text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
          >
            Salvar Lançamento
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>