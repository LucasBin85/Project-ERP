<script setup>
defineProps({
    cards: Array,
})
</script>

<template>
    <section class="grid grid-cols-1 gap-4 md:grid-cols-3 xl:grid-cols-6">
        <div
            v-for="card in cards"
            :key="card.label"
            class="rounded-2xl border border-white/10 bg-[#111827] p-4 shadow"
        >
            <p class="text-sm text-gray-400">{{ card.label }}</p>
            <p
                class="mt-2 text-lg font-bold"
                :class="{
                    'text-white': card.tone === 'neutral',
                    'text-green-300': card.tone === 'positive',
                    'text-red-300': card.tone === 'negative',
                    'text-blue-300': card.tone === 'info',
                }"
            >
                {{ card.value }}
            </p>
        </div>
    </section>
</template>
