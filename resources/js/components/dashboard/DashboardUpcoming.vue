<script setup>
import { formatCurrency, formatDate } from '@/lib/formatters'

defineProps({
    upcoming: {
        type: Array,
        default: () => [],
    },
})

const emit = defineEmits(['open-item'])
</script>

<template>
    <div class="rounded-2xl border border-white/10 bg-[#111827] p-5 shadow">
        <div class="mb-5 flex items-center justify-between">
            <div>
                <h2 class="text-xl font-bold text-white">
                    Próximos vencimentos
                </h2>
                <p class="mt-1 text-sm text-gray-500">
                    Contas a receber, contas a pagar e faturas de cartão no período.
                </p>
            </div>
        </div>

        <div v-if="upcoming.length" class="space-y-3">
            <button
                v-for="item in upcoming"
                :key="item.id"
                type="button"
                class="w-full rounded-xl border border-white/10 bg-gray-950/40 p-4 text-left transition hover:border-blue-500/50 hover:bg-gray-900"
                @click="emit('open-item', item.url)"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm font-semibold text-white">
                                {{ item.description }}
                            </p>

                            <span
                                v-if="item.is_overdue"
                                class="rounded-full bg-red-950/60 px-2 py-0.5 text-xs font-bold text-red-300"
                            >
                                Vencido
                            </span>
                        </div>

                        <p class="mt-1 text-xs text-gray-500">
                            {{ item.source }} · {{ item.person }} · {{ formatDate(item.date) }}
                        </p>
                    </div>

                    <p
                        class="whitespace-nowrap text-sm font-bold"
                        :class="item.type === 'inflow' ? 'text-green-300' : 'text-red-300'"
                    >
                        {{ item.type === 'inflow' ? '+' : '-' }} {{ formatCurrency(item.amount_cents) }}
                    </p>
                </div>
            </button>
        </div>

        <div v-else class="rounded-xl border border-dashed border-gray-700 p-4 text-sm text-gray-500">
            Nenhum vencimento encontrado para o período.
        </div>
    </div>
</template>
