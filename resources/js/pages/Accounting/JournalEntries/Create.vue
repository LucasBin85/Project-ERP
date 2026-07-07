<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Head } from '@inertiajs/vue3'
import { useJournalEntryCreate } from '@/composables/accounting/useJournalEntryCreate'

interface Account {
  id: number
  code: string
  name: string
  label: string
}

const props = defineProps<{
  accounts: Account[]
}>()

const {
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
  absoluteDifference,
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
} = useJournalEntryCreate(props.accounts)
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
