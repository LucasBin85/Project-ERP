<script setup>
defineProps({
    canPost: Boolean,
    canReclassify: Boolean,
    isPosted: Boolean,
})

const emit = defineEmits(['post'])
</script>

<template>
    <div class="rounded-xl border border-gray-700 bg-[#111827] p-6">
        <h2 class="mb-5 text-xl font-bold text-white">Ações</h2>

        <div class="grid gap-4 md:grid-cols-3">
            <button
                type="button"
                class="rounded-lg bg-blue-600 px-4 py-3 text-sm font-bold text-white hover:bg-blue-500 disabled:opacity-50"
                :disabled="!canPost"
                @click="emit('post')"
            >
                Postar lançamento
            </button>

            <button
                type="button"
                class="rounded-lg border border-gray-600 px-4 py-3 text-sm font-bold text-gray-300 disabled:opacity-50"
                :disabled="!canReclassify"
            >
                Reclassificar
            </button>

            <button
                type="button"
                class="rounded-lg border border-gray-600 px-4 py-3 text-sm font-bold text-gray-300 disabled:opacity-50"
                :disabled="isPosted"
            >
                Editar
            </button>
        </div>
    </div>
</template>
