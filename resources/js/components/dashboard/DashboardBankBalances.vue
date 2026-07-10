<script setup>
import { formatCurrency } from '@/lib/formatters'

defineProps({
    bankBalances: {
        type: Array,
        default: () => [],
    },
})
</script>

<template>
    <div class="rounded-2xl border border-white/10 bg-[#111827] p-5 shadow">
        <div class="mb-5 flex items-center justify-between">
            <div>
                <h2 class="text-xl font-bold text-white">
                    Saldos bancários
                </h2>
                <p class="mt-1 text-sm text-gray-500">
                    Saldos calculados pelos lançamentos postados até a data final.
                </p>
            </div>
        </div>

        <div v-if="bankBalances.length" class="space-y-3">
            <div
                v-for="account in bankBalances"
                :key="account.id"
                class="rounded-xl border border-white/10 bg-gray-950/40 p-4"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-white">
                            {{ account.name }}
                        </p>
                        <p class="text-xs text-gray-500">
                            {{ account.bank_name ?? '-' }} · {{ account.account_type }}
                        </p>
                    </div>

                    <p
                        class="whitespace-nowrap text-sm font-bold"
                        :class="Number(account.balance_cents || 0) >= 0 ? 'text-green-300' : 'text-red-300'"
                    >
                        {{ formatCurrency(account.balance_cents) }}
                    </p>
                </div>
            </div>
        </div>

        <div v-else class="rounded-xl border border-dashed border-gray-700 p-4 text-sm text-gray-500">
            Nenhuma conta bancária ativa cadastrada.
        </div>
    </div>
</template>
