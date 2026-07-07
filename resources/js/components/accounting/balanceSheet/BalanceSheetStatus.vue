<script setup>
import { formatCurrency } from '@/lib/formatters'

defineProps({
    balanced: Boolean,
    difference: Number,
})
</script>

<template>
    <div
        class="rounded-xl border px-4 py-3 text-sm font-bold"
        :class="balanced
            ? 'border-green-500 bg-green-950/40 text-green-300'
            : 'border-red-500 bg-red-950/40 text-red-300'"
    >
        <template v-if="balanced">
            BALANÇO FECHADO
        </template>

        <template v-else>
            Diferença: {{ formatCurrency(Math.abs(difference)) }}
        </template>
    </div>
</template>
