<script setup>
defineProps({
    canPost: Boolean,
    canReclassify: Boolean,
    isPosted: Boolean,
})

const emit = defineEmits(['post'])
</script>

<template>
    <section class="rounded-2xl border border-white/10 bg-[#111827] p-5 shadow">
        <div class="mb-5">
            <h2 class="text-xl font-bold text-white">Ações</h2>
            <p class="mt-1 text-sm text-gray-500">Operações disponíveis para este lançamento.</p>
        </div>

        <div class="grid gap-3 md:grid-cols-3">
            <button
                type="button"
                class="rounded-xl bg-blue-600 px-4 py-3 text-sm font-bold text-white hover:bg-blue-500 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="!canPost"
                @click="emit('post')"
            >
                Postar lançamento
            </button>

            <button
                type="button"
                class="rounded-xl border border-white/10 px-4 py-3 text-sm font-bold text-gray-300 hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="!canReclassify"
            >
                Reclassificar
            </button>

            <button
                type="button"
                class="rounded-xl border border-white/10 px-4 py-3 text-sm font-bold text-gray-300 hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="isPosted"
            >
                Editar
            </button>
        </div>
    </section>
</template>
