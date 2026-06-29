<script setup>
defineProps({
    label: {
        type: String,
        required: true,
    },
    value: {
        type: String,
        required: true,
    },
    tone: {
        type: String,
        default: 'neutral',
    },
})
</script>

<template>
    <div class="rounded-xl border border-gray-700 bg-[#111827] p-4">
        <p class="text-sm font-semibold text-gray-400">
            {{ label }}
        </p>

        <p
            class="mt-2 text-xl font-bold"
            :class="{
                'text-white': tone === 'neutral',
                'text-green-300': tone === 'green',
                'text-blue-300': tone === 'blue',
                'text-red-300': tone === 'red',
                'text-yellow-300': tone === 'yellow',
            }"
        >
            {{ value }}
        </p>
    </div>
</template>