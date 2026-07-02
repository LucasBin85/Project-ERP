<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import ReportPage from '@/components/reports/ReportPage.vue'
import ReportSection from '@/components/reports/ReportSection.vue'
import { computed } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { todayLocal } from '@/lib/date'
import {
    onlyNumbers,
    moneyToCents,
    formatMoneyInput,
} from '@/lib/input'

const props = defineProps({
    wallet: Object,
    bankAccounts: {
        type: Array,
        default: () => [],
    },
})


const form = useForm({
    name: '',
    bank_code: '',
    agency: '',
    account_number: '',
    account_type: 'checking',
    opening_balance: 'R$ 0,00',
    opening_balance_cents: 0,
    opening_balance_date: todayLocal(),
})

const normalizedName = computed(() => form.name.trim().toLowerCase())

const isDuplicateName = computed(() => {
    if (!normalizedName.value) return false

    return props.bankAccounts.some(account =>
        account.name?.trim().toLowerCase() === normalizedName.value
    )
})

const isDuplicateBankAccount = computed(() => {
    if (!form.bank_code || !form.agency || !form.account_number) {
        return false
    }

    return props.bankAccounts.some(account =>
        String(account.bank_code || '') === String(form.bank_code) &&
        String(account.agency || '') === String(form.agency) &&
        String(account.account_number || '') === String(form.account_number)
    )
})

const canSubmit = computed(() => {
    return (
        form.name.trim().length > 0 &&
        !isDuplicateName.value &&
        !isDuplicateBankAccount.value &&
        !form.processing
    )
})

function updateOnlyNumbers(field, event) {
    const value = onlyNumbers(event.target.value)

    form[field] = value
    event.target.value = value
}


function updateOpeningBalance(event) {
    form.opening_balance = formatMoneyInput(event.target.value)
    form.opening_balance_cents = moneyToCents(form.opening_balance)
}

function submit() {
    if (!canSubmit.value) return

    form.post(route('bank-accounts.store'))
}
</script>

<template>
    <AppLayout title="Nova Conta Bancária">
        <ReportPage
            title="Nova Conta Bancária"
            :subtitle="wallet?.name"
        >
            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">
                            Dados da conta
                        </h2>

                        <p class="mt-1 text-sm text-gray-400">
                            Ao salvar, o sistema criará automaticamente uma subconta em
                            <strong>1.1.2 Bancos</strong>.
                        </p>
                    </div>
                </template>

                <form
                    class="space-y-6 p-6"
                    @submit.prevent="submit"
                >
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-gray-300">
                                Nome/Banco
                            </label>

                            <input
                                v-model="form.name"
                                class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-white"
                                :class="isDuplicateName ? 'border-red-500' : ''"
                                placeholder="Ex: Banco Nubank"
                            />

                            <p
                                v-if="isDuplicateName"
                                class="mt-1 text-sm text-red-400"
                            >
                                Já existe uma conta bancária com esse nome.
                            </p>

                            <p class="mt-1 text-sm text-red-400">
                                {{ form.errors.name }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-semibold text-gray-300">
                                Código do banco
                            </label>

                            <input
                                :value="form.bank_code"
                                @input="updateOnlyNumbers('bank_code', $event)"
                                class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-white"
                                placeholder="Ex: 260"
                                inputmode="numeric"
                            />

                            <p class="mt-1 text-sm text-red-400">
                                {{ form.errors.bank_code }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-semibold text-gray-300">
                                Agência
                            </label>

                            <input
                                v-model="form.agency"
                                @input="updateOnlyNumbers('agency', $event)"
                                class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-white"
                                placeholder="Ex: 0001"
                                inputmode="numeric"
                            />

                            <p class="mt-1 text-sm text-red-400">
                                {{ form.errors.agency }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-semibold text-gray-300">
                                Número da conta
                            </label>

                            <input
                                v-model="form.account_number"
                                @input="updateOnlyNumbers('account_number', $event)"
                                class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-white"
                                :class="isDuplicateBankAccount ? 'border-red-500' : ''"
                                placeholder="Ex: 123456"
                                inputmode="numeric"
                            />

                            <p
                                v-if="isDuplicateBankAccount"
                                class="mt-1 text-sm text-red-400"
                            >
                                Já existe uma conta bancária com este código, agência e número.
                            </p>

                            <p class="mt-1 text-sm text-red-400">
                                {{ form.errors.account_number }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-semibold text-gray-300">
                                Tipo
                            </label>

                            <select
                                v-model="form.account_type"
                                class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-white"
                            >
                                <option value="checking">Conta Corrente</option>
                                <option value="savings">Poupança</option>
                                <option value="investment">Investimento</option>
                                <option value="cash">Caixa</option>
                                <option value="other">Outra</option>
                            </select>

                            <p class="mt-1 text-sm text-red-400">
                                {{ form.errors.account_type }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-semibold text-gray-300">
                                Saldo inicial
                            </label>

                            <input
                                :value="form.opening_balance"
                                @input="updateOpeningBalance"
                                class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-white"
                            />

                            <p class="mt-1 text-sm text-red-400">
                                {{ form.errors.opening_balance_cents }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-semibold text-gray-300">
                                Data do saldo inicial
                            </label>

                            <input
                                v-model="form.opening_balance_date"
                                type="date"
                                class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-white"
                            />

                            <p class="mt-1 text-sm text-red-400">
                                {{ form.errors.opening_balance_date }}
                            </p>
                        </div>
                    </div>

                    <div class="rounded-lg border border-blue-900 bg-blue-950/30 p-4 text-sm text-blue-200">
                        A conta contábil será criada automaticamente como filha de:
                        <strong>1.1.2 Bancos</strong>.
                    </div>

                    <div class="flex justify-end gap-3">
                        <Link
                            :href="route('bank-accounts.index')"
                            class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                        >
                            Cancelar
                        </Link>

                        <button
                            type="submit"
                            :disabled="!canSubmit"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Salvar
                        </button>
                    </div>
                </form>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>