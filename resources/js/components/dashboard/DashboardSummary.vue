<script setup>
defineProps({
    resultTone: String,
    resultMargin: String,
    latestEntriesCount: Number,
})

const emit = defineEmits(['open-journal'])
</script>

<template>
    <div class="rounded-2xl border border-white/10 bg-[#111827] p-5 shadow">
        <div class="mb-5 flex items-center justify-between">
            <div>
                <h2 class="text-xl font-bold text-white">
                    Resumo do período
                </h2>
                <p class="mt-1 text-sm text-gray-500">
                    Indicadores rápidos da carteira.
                </p>
            </div>
        </div>

        <div class="space-y-4">
            <div class="rounded-xl border border-white/10 bg-gray-950/40 p-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-400">Margem de resultado</span>
                    <span
                        class="text-sm font-bold"
                        :class="resultTone === 'positive' ? 'text-green-300' : 'text-red-300'"
                    >
                        {{ resultMargin }}
                    </span>
                </div>
            </div>

            <div class="rounded-xl border border-white/10 bg-gray-950/40 p-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-400">Total de lançamentos recentes</span>
                    <span class="text-sm font-bold text-blue-300">
                        {{ latestEntriesCount }}
                    </span>
                </div>
            </div>

            <button
                type="button"
                class="w-full rounded-xl border border-blue-500/40 bg-blue-950/30 px-4 py-3 text-left text-sm font-bold text-blue-200 hover:bg-blue-950/50"
                @click="emit('open-journal')"
            >
                Abrir Livro Diário →
            </button>
        </div>
    </div>
</template>
