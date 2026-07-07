<script setup>
import { formatCurrency } from '@/lib/formatters'

defineProps({
    balanced: Boolean,
    difference: Number,
})
</script>

<template>
    <div class="flex flex-col justify-between gap-4 md:flex-row md:items-start">
        <div
            class="rounded-xl border px-4 py-3 text-sm font-bold"
            :class="balanced
                ? 'border-green-500 bg-green-950/40 text-green-300'
                : 'border-red-500 bg-red-950/40 text-red-300'"
        >
            <template v-if="balanced">
                BALANCEADO
            </template>

            <template v-else>
                Diferença: {{ formatCurrency(difference) }}
            </template>
        </div>
    </div>
</template>
