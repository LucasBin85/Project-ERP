<script setup>
import { formatDate, formatDateTime } from '@/lib/formatters'

defineProps({
    entry: { type: Object, required: true },
    activeWallet: { type: Object, required: true },
})
</script>

<template>
    <div class="grid gap-4 rounded-xl border border-gray-700 bg-[#111827] p-5 shadow-sm md:grid-cols-5">
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
            <p class="mt-2 text-white">{{ activeWallet.name }}</p>
        </div>
    </div>
</template>
