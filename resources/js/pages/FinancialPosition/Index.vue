<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import AccountNode from '@/components/accounting/AccountNode.vue'

defineProps({
    wallet: Object,
    position: Object,
})

const formatDate = (date) => {
    if (!date) return '-'

    return new Date(date).toLocaleDateString('pt-BR')
}

const positionDate = () => {
    return new Date().toISOString().slice(0, 10)
}

const formatCurrency = (value) => {
    return (value / 100).toLocaleString('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    })
}

const isNegative = (value) => value < 0

const projectedBalance = (position) => {
    const available = position.summary.available_cents || 0
    const investments = position.summary.investments_cents || 0
    const receivable = position.summary.accounts_receivable_cents || 0
    const payable = position.summary.accounts_payable_cents || 0

    return available + investments + receivable - payable
}

</script>

<template>
    <AppLayout title="Posição Financeira">
        <div class="space-y-6 p-6">
            <div>
                <h1 class="text-2xl font-bold text-white">
                    Posição Financeira
                </h1>
                <p class="text-sm text-gray-400">
                    {{ wallet.name }}
                </p>
                <p class="mt-1 text-sm text-gray-500">
                    Posição em: {{ formatDate(positionDate()) }}
                </p>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-6">
                <div class="rounded-xl border border-white/10 bg-[#152238] p-4 shadow">
                    <p class="text-sm text-gray-400">Disponível</p>
                    <p class="text-lg font-semibold text-white">
                        {{ formatCurrency(position.summary.available_cents) }}
                    </p>
                </div>

                <div class="rounded-xl border border-white/10 bg-[#152238] p-4 shadow">
                    <p class="text-sm text-gray-400">Investimentos</p>
                    <p class="text-lg font-semibold text-white">
                        {{ formatCurrency(position.summary.investments_cents) }}
                    </p>
                </div>

                <div class="rounded-xl border border-white/10 bg-[#152238] p-4 shadow">
                    <p class="text-sm text-gray-400">A Receber</p>
                    <p class="text-lg font-semibold text-white">
                        {{ formatCurrency(position.summary.accounts_receivable_cents) }}
                    </p>
                </div>

                <div class="rounded-xl border border-white/10 bg-[#152238] p-4 shadow">
                    <p class="text-sm text-gray-400">A Pagar</p>
                    <p class="text-lg font-semibold text-white">
                        {{ formatCurrency(position.summary.accounts_payable_cents) }}
                    </p>
                </div>

                <div class="rounded-xl border border-white/10 bg-[#1b2b46] p-4 shadow">
                    <p class="text-sm font-semibold text-gray-300">Posição Líquida</p>
                    <p
                        class="text-lg font-bold"
                        :class="isNegative(position.summary.net_position_cents) ? 'text-red-400' : 'text-green-400'"
                    >
                        {{ formatCurrency(position.summary.net_position_cents) }}
                    </p>
                </div>

                <div class="rounded-xl border border-blue-500/30 bg-[#1b2b46] p-4 shadow">
                    <p class="text-sm font-semibold text-gray-300">Saldo Projetado</p>
                    <p
                        class="text-lg font-bold"
                        :class="isNegative(projectedBalance(position)) ? 'text-red-400' : 'text-blue-300'"
                    >
                        {{ formatCurrency(projectedBalance(position)) }}
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <div
                    v-for="group in position.groups"
                    :key="group.key"
                    class="rounded-xl border border-white/10 bg-[#152238] p-4 shadow"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-white">
                            {{ group.label }}
                        </h2>
                        <span
                            class="font-bold"
                            :class="isNegative(group.total_cents) ? 'text-red-400' : 'text-green-400'"
                        >
                            {{ formatCurrency(group.total_cents) }}
                        </span>
                    </div>

                    <div class="space-y-2">
                        <AccountNode
                            v-for="account in group.accounts"
                            :key="account.id"
                            :account="account"
                        />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>