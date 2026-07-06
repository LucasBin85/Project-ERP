<script setup>
import AccountNode from '@/components/accounting/AccountNode.vue'
import { formatCurrency } from '@/lib/formatters'

defineProps({
    groups: Array,
})

function isNegative(value) {
    return Number(value || 0) < 0
}
</script>

<template>
    <section class="grid grid-cols-1 gap-6 md:grid-cols-2">
        <div
            v-for="group in groups"
            :key="group.key"
            class="rounded-2xl border border-white/10 bg-[#111827] p-5 shadow"
        >
            <div class="mb-4 flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-bold text-white">
                        {{ group.label }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-500">
                        Contas vinculadas ao grupo.
                    </p>
                </div>

                <span
                    class="font-bold"
                    :class="isNegative(group.total_cents) ? 'text-red-300' : 'text-green-300'"
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
    </section>
</template>
