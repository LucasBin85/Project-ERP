<script setup>
import { formatDate } from '@/lib/formatters'

defineProps({
    wallet: Object,
    positionDate: String,
})
</script>

<template>
    <section class="rounded-2xl border border-white/10 bg-gradient-to-br from-[#10213a] to-[#0b1220] p-6 shadow">
        <p class="text-sm font-semibold uppercase tracking-wide text-blue-300">
            Financeiro
        </p>

        <h1 class="mt-2 text-3xl font-bold text-white">
            Posição Financeira
        </h1>

        <p class="mt-2 text-sm text-gray-400">
            {{ wallet.name }}
        </p>

        <p class="mt-3 text-sm text-gray-500">
            Posição em: {{ formatDate(positionDate) }}
        </p>
    </section>
</template>
