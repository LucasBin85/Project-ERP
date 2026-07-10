<script setup>
defineProps({
    cards: Array,
})
</script>

<template>
    <section class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
        <button
            v-for="card in cards"
            :key="card.label"
            type="button"
            class="rounded-2xl border border-white/10 bg-[#111827] p-5 text-left shadow transition hover:border-blue-500/60 hover:bg-[#152238]"
            @click="card.action"
        >
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold text-gray-400">
                        {{ card.label }}
                    </p>

                    <p
                        class="mt-3 text-2xl font-bold"
                        :class="{
                            'text-green-300': card.tone === 'positive',
                            'text-red-300': card.tone === 'negative',
                            'text-yellow-300': card.tone === 'warning',
                            'text-white': !['positive', 'negative', 'warning'].includes(card.tone),
                        }"
                    >
                        {{ card.value }}
                    </p>
                </div>

                <span
                    class="rounded-full px-3 py-1 text-xs font-bold"
                    :class="{
                        'bg-green-950/60 text-green-300': card.tone === 'positive',
                        'bg-red-950/60 text-red-300': card.tone === 'negative',
                        'bg-yellow-950/60 text-yellow-300': card.tone === 'warning',
                        'bg-blue-950/60 text-blue-300': !['positive', 'negative', 'warning'].includes(card.tone),
                    }"
                >
                    {{ card.badge ?? (card.tone === 'negative' ? 'Saída' : 'Entrada') }}
                </span>
            </div>

            <p class="mt-4 text-xs text-gray-500">
                {{ card.helper }}
            </p>
        </button>
    </section>
</template>
