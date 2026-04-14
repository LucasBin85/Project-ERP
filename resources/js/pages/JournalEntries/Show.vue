<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { computed, ref, watch } from 'vue'

const props = defineProps({
    wallet: {
        type: Object,
        required: true,
    },
    entry: {
        type: Object,
        required: true,
    },
    classificationAccounts: {
        type: Array,
        required: true,
    },
})

const page = usePage()

const selectedAccountId = ref('')
const selectedAmount = ref('')
const selectedMemo = ref('')

const hasSuspenseLine = computed(() => {
    return props.entry.lines?.some(
        line => Number(line.chart_of_account_id) === Number(props.wallet.suspense_account_id)
    )
})

const suspenseLine = computed(() => {
    return props.entry.lines?.find(
        line => Number(line.chart_of_account_id) === Number(props.wallet.suspense_account_id)
    ) || null
})

const canReclassify = computed(() => {
    return props.entry.status === 'draft' && hasSuspenseLine.value
})

const formatCurrency = (cents) => {
    const value = Number(cents || 0) / 100

    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    }).format(value)
}

const formatDate = (date) => {
    if (!date) return '-'
    return new Intl.DateTimeFormat('pt-BR').format(new Date(date))
}

const formatCentsToInput = (cents) => {
    const value = Number(cents || 0) / 100

    return value.toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })
}

const parseCurrencyToCents = (value) => {
    if (!value) return 0

    const normalized = String(value)
        .trim()
        .replace(/\s/g, '')
        .replace(/\./g, '')
        .replace(',', '.')

    const number = Number(normalized)

    if (Number.isNaN(number)) {
        return 0
    }

    return Math.round(number * 100)
}

watch(
    () => suspenseLine.value?.amount_cents,
    (value) => {
        if (value && !selectedAmount.value) {
            selectedAmount.value = formatCentsToInput(value)
        }
    },
    { immediate: true }
)

const form = useForm({
    splits: [],
})

const submitReclassification = () => {
    if (!selectedAccountId.value) {
        alert('Selecione uma conta')
        return
    }

    const amountCents = parseCurrencyToCents(selectedAmount.value)

    if (!amountCents || amountCents <= 0) {
        alert('Informe um valor válido')
        return
    }

    form.splits = [
        {
            chart_of_account_id: Number(selectedAccountId.value),
            amount_cents: amountCents,
            memo: selectedMemo.value || null,
        },
    ]

    form.post(route('journal-entries.reclassify', props.entry.id), {
        preserveScroll: true,
        onSuccess: () => {
            selectedMemo.value = ''
        },
    })
}

const postEntry = () => {
    router.post(route('journal-entries.post', props.entry.id), {}, {
        preserveScroll: true,
    })
}
</script>

<template>
    <AppLayout :title="`Lançamento #${entry.id}`">
        <Head :title="`Lançamento #${entry.id}`" />

        <div class="space-y-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="mb-2">
                        <Link
                            :href="route('journal-entries.index')"
                            class="text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                        >
                            ← Voltar para lançamentos
                        </Link>
                    </div>

                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                        Lançamento #{{ entry.id }}
                    </h1>

                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ entry.description || 'Sem descrição' }}
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <span
                        class="inline-flex rounded-full px-3 py-1 text-xs font-semibold"
                        :class="entry.status === 'posted'
                            ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'
                            : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300'"
                    >
                        {{ entry.status }}
                    </span>
                </div>
            </div>

            <div
                v-if="page.props.flash?.success"
                class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300"
            >
                {{ page.props.flash.success }}
            </div>

            <div
                v-if="page.props.flash?.error"
                class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300"
            >
                {{ page.props.flash.error }}
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="space-y-6 lg:col-span-2">
                    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <h2 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                            Dados do lançamento
                        </h2>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Data
                                </div>
                                <div class="mt-1 text-sm text-gray-800 dark:text-gray-200">
                                    {{ formatDate(entry.entry_date) }}
                                </div>
                            </div>

                            <div>
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Origem
                                </div>
                                <div class="mt-1 text-sm text-gray-800 dark:text-gray-200">
                                    {{ entry.source }}
                                </div>
                            </div>

                            <div>
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Balanceado
                                </div>
                                <div class="mt-1 text-sm text-gray-800 dark:text-gray-200">
                                    {{ entry.is_balanced ? 'Sim' : 'Não' }}
                                </div>
                            </div>

                            <div>
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Diferença
                                </div>
                                <div class="mt-1 text-sm text-gray-800 dark:text-gray-200">
                                    {{ formatCurrency(entry.balance_diff_cents) }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                Linhas do lançamento
                            </h2>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-900/40">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                            Conta
                                        </th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                            Tipo
                                        </th>
                                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                            Valor
                                        </th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                            Memo
                                        </th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr
                                        v-for="line in entry.lines"
                                        :key="line.id"
                                        :class="Number(line.chart_of_account_id) === Number(wallet.suspense_account_id)
                                            ? 'bg-yellow-50 dark:bg-yellow-900/10'
                                            : ''"
                                    >
                                        <td class="px-4 py-3 text-sm text-gray-800 dark:text-gray-200">
                                            <div class="font-medium">
                                                {{ line.chart_of_account?.code }} - {{ line.chart_of_account?.name }}
                                            </div>
                                            <div
                                                v-if="Number(line.chart_of_account_id) === Number(wallet.suspense_account_id)"
                                                class="mt-1 text-xs font-semibold text-yellow-700 dark:text-yellow-300"
                                            >
                                                Conta transitória / pendente de classificação
                                            </div>
                                        </td>

                                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                                            {{ line.type }}
                                        </td>

                                        <td class="px-4 py-3 text-right text-sm font-medium text-gray-800 dark:text-gray-200">
                                            {{ formatCurrency(line.amount_cents) }}
                                        </td>

                                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                                            {{ line.memo || '-' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <h2 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                            Ações
                        </h2>

                        <div class="space-y-3">
                            <button
                                type="button"
                                class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="entry.status === 'posted'"
                                @click="postEntry"
                            >
                                Postar lançamento
                            </button>

                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                O post exige lançamento balanceado e, por padrão, sem valores em “A classificar”.
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="canReclassify"
                        class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800"
                    >
                        <h2 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                            Reclassificar
                        </h2>

                        <div
                            v-if="suspenseLine"
                            class="mb-4 rounded-lg border border-yellow-200 bg-yellow-50 px-3 py-2 text-sm text-yellow-700 dark:border-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-300"
                        >
                            Valor pendente em “A classificar”:
                            <strong>{{ formatCurrency(suspenseLine.amount_cents) }}</strong>
                        </div>

                        <form class="space-y-4" @submit.prevent="submitReclassification">
                            <div>
                                <label
                                    for="reclassify-account"
                                    class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200"
                                >
                                    Conta destino
                                </label>
                                <select
                                    id="reclassify-account"
                                    v-model="selectedAccountId"
                                    name="chart_of_account_id"
                                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                                >
                                    <option value="">Selecione uma conta</option>
                                    <option
                                        v-for="account in classificationAccounts"
                                        :key="account.id"
                                        :value="account.id"
                                    >
                                        {{ account.code }} - {{ account.name }}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label
                                    for="reclassify-amount"
                                    class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200"
                                >
                                    Valor
                                </label>
                                <input
                                    id="reclassify-amount"
                                    v-model="selectedAmount"
                                    name="amount"
                                    type="text"
                                    inputmode="decimal"
                                    placeholder="0,00"
                                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                                >
                            </div>

                            <div>
                                <label
                                    for="reclassify-memo"
                                    class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200"
                                >
                                    Memo
                                </label>
                                <input
                                    id="reclassify-memo"
                                    v-model="selectedMemo"
                                    name="memo"
                                    type="text"
                                    maxlength="255"
                                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                                    placeholder="Ex.: Mercado"
                                >
                            </div>

                            <button
                                type="submit"
                                class="w-full rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="form.processing || !selectedAccountId || !selectedAmount"
                            >
                                Reclassificar
                            </button>
                        </form>
                    </div>

                    <div
                        v-else
                        class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800"
                    >
                        <h2 class="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">
                            Reclassificação
                        </h2>

                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            Este lançamento não possui valor pendente em “A classificar” ou já não está em draft.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>