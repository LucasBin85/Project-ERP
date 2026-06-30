<script setup>
defineProps({
    label: {
        type: String,
        default: 'Data de referência',
    },
})

const model = defineModel()
</script>

<template>
    <div class="rounded-xl border border-gray-700 bg-[#111827] p-4">
        <label class="mb-2 block text-sm font-semibold text-gray-300">
            {{ label }}
        </label>

        <input
            v-model="model"
            type="date"
            class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-white outline-none transition focus:border-indigo-500 md:w-64"
        />
    </div>
</template>