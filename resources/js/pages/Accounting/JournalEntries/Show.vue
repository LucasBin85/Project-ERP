<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { computed, ref, watch } from 'vue'

const props = defineProps({
    wallet: { type: Object, required: true },
    entry: { type: Object, required: true },
    classificationAccounts: { type: Array, required: true },
})

const page = usePage()

const selectedAccountId = ref('')
const selectedAmount = ref('')
const selectedMemo = ref('')

const formatCurrency = cents => {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    }).format(Number(cents || 0) / 100)
}

const formatDate = date => {
    if (!date) return '-'

    return new Intl.DateTimeFormat('pt-BR').format(new Date(date))
}

const formatDateTime = date => {
    if (!date) return '-'

    return new Intl.DateTimeFormat('pt-BR', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(date))
}

const formatCentsToInput = cents => {
    return (Number(cents || 0) / 100).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })
}

const parseCurrencyToCents = value => {
    if (!value) return 0

    const normalized = String(value)
        .trim()
        .replace(/\s/g, '')
        .replace(/\./g, '')
        .replace(',', '.')

    const number = Number(normalized)

    return Number.isNaN(number) ? 0 : Math.round(number * 100)
}

const debitLines = computed(() => {
    return props.entry.lines?.filter(line => line.type === 'debit') || []
})

const creditLines = computed(() => {
    return props.entry.lines?.filter(line => line.type === 'credit') || []
})

const debitTotal = computed(() => {
    return debitLines.value.reduce((total, line) => {
        return total + Number(line.amount_cents || 0)
    }, 0)
})

const creditTotal = computed(() => {
    return creditLines.value.reduce((total, line) => {
        return total + Number(line.amount_cents || 0)
    }, 0)
})

const difference = computed(() => debitTotal.value - creditTotal.value)
const isBalanced = computed(() => difference.value === 0)
const isPosted = computed(() => props.entry.status === 'posted')

const statusLabel = computed(() => {
    return isPosted.value ? 'POSTADO' : 'RASCUNHO'
})

const hasSuspenseLine = computed(() => {
    return props.entry.lines?.some(line => {
        return Number(line.chart_of_account_id) === Number(props.wallet.suspense_account_id)
    })
})

const suspenseLine = computed(() => {
    return props.entry.lines?.find(line => {
        return Number(line.chart_of_account_id) === Number(props.wallet.suspense_account_id)
    }) || null
})

const canReclassify = computed(() => {
    return !isPosted.value && hasSuspenseLine.value
})

const canPost = computed(() => {
    return !isPosted.value && isBalanced.value && !hasSuspenseLine.value
})

watch(
    () => suspenseLine.value?.amount_cents,
    value => {
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
    if (!canReclassify.value) return

    const amountCents = parseCurrencyToCents(selectedAmount.value)

    if (!selectedAccountId.value || amountCents <= 0) return

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
    if (!canPost.value) return

    router.post(route('journal-entries.post', props.entry.id), {}, {
        preserveScroll: true,
    })
}
</script>

<template>
    <AppLayout :title="`Lançamento #${entry.id}`">
        <Head :title="`Lançamento #${entry.id}`" />

        <div class="space-y-6">
            <div>
                <Link
                    :href="route('journal-entries.index')"
                    class="text-sm font-medium text-blue-400 hover:text-blue-300"
                >
                    ← Voltar para lançamentos
                </Link>
            </div>

            <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                <div>
                    <h1 class="text-3xl font-bold text-white">
                        Lançamento #{{ entry.id }}
                    </h1>

                    <p class="mt-2 text-lg text-gray-400">
                        {{ entry.description || 'Sem descrição' }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <span
                        class="rounded-lg border px-4 py-2 text-sm font-bold"
                        :class="isPosted
                            ? 'border-green-500 bg-green-950/40 text-green-300'
                            : 'border-yellow-500 bg-yellow-950/40 text-yellow-300'"
                    >
                        {{ statusLabel }}
                    </span>

                    <span
                        class="rounded-lg border px-4 py-2 text-sm font-bold"
                        :class="isBalanced
                            ? 'border-blue-500 bg-blue-950/40 text-blue-300'
                            : 'border-red-500 bg-red-950/40 text-red-300'"
                    >
                        {{ isBalanced ? 'BALANCEADO' : 'DESBALANCEADO' }}
                    </span>
                </div>
            </div>

            <div
                v-if="page.props.flash?.success"
                class="rounded-xl border border-green-700 bg-green-950/30 px-4 py-3 text-sm text-green-300"
            >
                {{ page.props.flash.success }}
            </div>

            <div
                class="grid gap-4 rounded-xl border border-gray-700 bg-[#111827] p-5 shadow-sm md:grid-cols-5"
            >
                <div>
                    <p class="text-xs font-semibold uppercase text-gray-400">Data</p>
                    <p class="mt-2 text-white">{{ formatDate(entry.entry_date) }}</p>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase text-gray-400">Origem</p>
                    <p class="mt-2 text-white">{{ entry.source || 'manual' }}</p>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase text-gray-400">Criado em</p>
                    <p class="mt-2 text-white">{{ formatDateTime(entry.created_at) }}</p>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase text-gray-400">Postado em</p>
                    <p class="mt-2 text-white">{{ formatDateTime(entry.posted_at) }}</p>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase text-gray-400">Carteira</p>
                    <p class="mt-2 text-white">{{ wallet.name }}</p>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-700 bg-[#111827]">
                <div class="border-b border-gray-700 px-6 py-5">
                    <h2 class="text-xl font-bold text-white">
                        Razonete do lançamento
                    </h2>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2">
                    <div class="border-b border-gray-700 md:border-r md:border-b-0">
                        <div class="bg-[#1f2937] px-5 py-4 text-center text-lg font-bold text-green-300">
                            DÉBITO
                        </div>

                        <div class="grid grid-cols-[1fr_140px] border-b border-gray-700 px-5 py-3 text-xs font-bold uppercase text-gray-400">
                            <div>Conta / Memo</div>
                            <div class="text-right">Valor</div>
                        </div>

                        <div class="min-h-[170px] space-y-3 p-5">
                            <div
                                v-for="line in debitLines"
                                :key="line.id"
                                class="grid grid-cols-[1fr_140px] gap-4 rounded-lg border border-gray-700 bg-[#0b1220] p-4"
                            >
                                <div>
                                    <p class="font-bold text-white">
                                        {{ line.chart_of_account?.code }} - {{ line.chart_of_account?.name }}
                                    </p>

                                    <p class="mt-2 text-sm text-gray-400">
                                        {{ line.memo || 'Sem memo' }}
                                    </p>
                                </div>

                                <div class="text-right font-bold text-white">
                                    {{ formatCurrency(line.amount_cents) }}
                                </div>
                            </div>
                        </div>

                        <div class="border-t border-gray-700 px-5 py-6 text-center">
                            <p class="text-sm font-bold uppercase text-green-300">
                                Total Débito
                            </p>

                            <p class="mt-2 text-3xl font-bold text-green-300">
                                {{ formatCurrency(debitTotal) }}
                            </p>
                        </div>
                    </div>

                    <div>
                        <div class="bg-[#1f2937] px-5 py-4 text-center text-lg font-bold text-blue-300">
                            CRÉDITO
                        </div>

                        <div class="grid grid-cols-[1fr_140px] border-b border-gray-700 px-5 py-3 text-xs font-bold uppercase text-gray-400">
                            <div>Conta / Memo</div>
                            <div class="text-right">Valor</div>
                        </div>

                        <div class="min-h-[170px] space-y-3 p-5">
                            <div
                                v-for="line in creditLines"
                                :key="line.id"
                                class="grid grid-cols-[1fr_140px] gap-4 rounded-lg border border-gray-700 bg-[#0b1220] p-4"
                            >
                                <div>
                                    <p class="font-bold text-white">
                                        {{ line.chart_of_account?.code }} - {{ line.chart_of_account?.name }}
                                    </p>

                                    <p
                                        v-if="Number(line.chart_of_account_id) === Number(wallet.suspense_account_id)"
                                        class="mt-1 text-xs font-semibold text-yellow-300"
                                    >
                                        Conta transitória / pendente de classificação
                                    </p>

                                    <p class="mt-2 text-sm text-gray-400">
                                        {{ line.memo || 'Sem memo' }}
                                    </p>
                                </div>

                                <div class="text-right font-bold text-white">
                                    {{ formatCurrency(line.amount_cents) }}
                                </div>
                            </div>
                        </div>

                        <div class="border-t border-gray-700 px-5 py-6 text-center">
                            <p class="text-sm font-bold uppercase text-blue-300">
                                Total Crédito
                            </p>

                            <p class="mt-2 text-3xl font-bold text-blue-300">
                                {{ formatCurrency(creditTotal) }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="border-t border-gray-700 p-5">
                    <div
                        class="rounded-lg px-5 py-4 text-sm font-bold"
                        :class="isBalanced
                            ? 'bg-green-950/40 text-green-300'
                            : 'bg-red-950/40 text-red-300'"
                    >
                        Diferença: {{ formatCurrency(Math.abs(difference)) }}
                        —
                        {{ isBalanced ? 'lançamento balanceado' : 'lançamento desbalanceado' }}
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-gray-700 bg-[#111827] p-6">
                <h2 class="mb-5 text-xl font-bold text-white">
                    Ações
                </h2>

                <div class="grid gap-4 md:grid-cols-4">
                    <button
                        type="button"
                        class="rounded-lg bg-blue-600 px-4 py-3 text-sm font-bold text-white hover:bg-blue-500 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="!canPost"
                        @click="postEntry"
                    >
                        Postar lançamento
                    </button>

                    <button
                        type="button"
                        class="rounded-lg border border-gray-600 px-4 py-3 text-sm font-bold text-gray-300 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="!canReclassify"
                    >
                        Reclassificar
                    </button>

                    <button
                        type="button"
                        class="rounded-lg border border-gray-600 px-4 py-3 text-sm font-bold text-gray-300 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="isPosted"
                    >
                        Editar
                    </button>

                    <button
                        type="button"
                        class="rounded-lg border border-red-500 px-4 py-3 text-sm font-bold text-red-400 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="isPosted"
                    >
                        Excluir
                    </button>
                </div>

                <p class="mt-4 text-sm text-gray-400">
                    <span v-if="isPosted">
                        Este lançamento já foi postado e está bloqueado para alterações.
                    </span>

                    <span v-else-if="hasSuspenseLine">
                        Para postar, primeiro reclassifique o valor em conta transitória.
                    </span>

                    <span v-else-if="!isBalanced">
                        Para postar, o lançamento precisa estar balanceado.
                    </span>

                    <span v-else>
                        O lançamento está pronto para ser postado.
                    </span>
                </p>
            </div>

            <div
                v-if="canReclassify"
                class="rounded-xl border border-gray-700 bg-[#111827] p-6"
            >
                <h2 class="mb-4 text-xl font-bold text-white">
                    Reclassificar conta transitória
                </h2>

                <form class="grid gap-4 md:grid-cols-[1fr_180px_1fr_auto]" @submit.prevent="submitReclassification">
                    <select
                        v-model="selectedAccountId"
                        class="rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
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

                    <input
                        v-model="selectedAmount"
                        type="text"
                        inputmode="decimal"
                        placeholder="0,00"
                        class="rounded-lg border border-gray-700 bg-black px-3 py-2 text-right text-white"
                    >

                    <input
                        v-model="selectedMemo"
                        type="text"
                        placeholder="Memo"
                        class="rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                    >

                    <button
                        type="submit"
                        class="rounded-lg bg-emerald-600 px-5 py-2 text-sm font-bold text-white hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="form.processing || !selectedAccountId || !selectedAmount"
                    >
                        Salvar
                    </button>
                </form>
            </div>
        </div>
    </AppLayout>
</template>