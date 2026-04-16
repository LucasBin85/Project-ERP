<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'

const props = defineProps({
    account: {
        type: Object,
        required: true,
    },
})

const isOpen = ref(true)

const toggle = () => {
    isOpen.value = !isOpen.value
}

const formatCurrency = (value) => {
    return (value / 100).toLocaleString('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    })
}

const isNegative = (value) => value < 0

const canNavigateToLedger = () => {
    return !!props.account.allows_posting
}

const goToLedger = () => {
    if (!canNavigateToLedger()) {
        return
    }

    router.visit(route('ledger.index', {
        chart_of_account_id: props.account.id,
    }))
}
</script>

<template>
    <div class="ml-1">
        <div class="flex items-center justify-between rounded-md px-2 py-1 hover:bg-white/5">
            <div class="flex items-center gap-2">
                <button
                    v-if="account.children && account.children.length"
                    type="button"
                    @click.stop="toggle"
                    class="text-gray-400 hover:text-white"
                >
                    <span v-if="isOpen">▼</span>
                    <span v-else>▶</span>
                </button>

                <span v-else class="w-4"></span>

                <button
                    v-if="canNavigateToLedger()"
                    type="button"
                    class="text-left text-sm text-gray-200 hover:text-blue-400"
                    @click="goToLedger"
                >
                    {{ account.code }} - {{ account.name }}
                </button>

                <span
                    v-else
                    class="text-sm font-semibold text-white"
                >
                    {{ account.code }} - {{ account.name }}
                </span>
            </div>

            <span
                class="text-sm font-medium"
                :class="isNegative(account.total_balance_cents) ? 'text-red-400' : 'text-gray-300'"
            >
                {{ formatCurrency(account.total_balance_cents) }}
            </span>
        </div>

        <div
            v-if="isOpen && account.children && account.children.length"
            class="ml-4 space-y-1 border-l border-white/10 pl-3"
        >
            <AccountNode
                v-for="child in account.children"
                :key="child.id"
                :account="child"
            />
        </div>
    </div>
</template>