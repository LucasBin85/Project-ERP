<script setup>
import { formatDate, formatDateTime } from '@/lib/formatters'

defineProps({
    entry: { type: Object, required: true },
    activeWallet: { type: Object, required: true },
})
</script>

<template>
    <section class="rounded-2xl border border-white/10 bg-[#111827] p-5 shadow">
        <div class="mb-5">
            <h2 class="text-xl font-bold text-white">Informações do lançamento</h2>
            <p class="mt-1 text-sm text-gray-500">Dados gerais do registro contábil.</p>
        </div>

        <div class="grid gap-4 md:grid-cols-5">
            <div class="rounded-xl border border-white/10 bg-gray-950/40 p-4">
                <p class="text-xs font-bold uppercase text-gray-500">Data</p>
                <p class="mt-2 font-semibold text-white">{{ formatDate(entry.entry_date) }}</p>
            </div>

            <div class="rounded-xl border border-white/10 bg-gray-950/40 p-4">
                <p class="text-xs font-bold uppercase text-gray-500">Origem</p>
                <p class="mt-2 font-semibold text-white">{{ entry.source || 'manual' }}</p>
            </div>

            <div class="rounded-xl border border-white/10 bg-gray-950/40 p-4">
                <p class="text-xs font-bold uppercase text-gray-500">Criado em</p>
                <p class="mt-2 font-semibold text-white">{{ formatDateTime(entry.created_at) }}</p>
            </div>

            <div class="rounded-xl border border-white/10 bg-gray-950/40 p-4">
                <p class="text-xs font-bold uppercase text-gray-500">Postado em</p>
                <p class="mt-2 font-semibold text-white">{{ formatDateTime(entry.posted_at) }}</p>
            </div>

            <div class="rounded-xl border border-white/10 bg-gray-950/40 p-4">
                <p class="text-xs font-bold uppercase text-gray-500">Carteira</p>
                <p class="mt-2 font-semibold text-white">{{ activeWallet.name }}</p>
            </div>
        </div>
    </section>
</template>
