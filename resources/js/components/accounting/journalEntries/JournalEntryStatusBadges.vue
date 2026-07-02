<script setup>
defineProps({
    isPosted: { type: Boolean, required: true },
    isBalanced: { type: Boolean, required: true },
    statusLabel: { type: String, required: true },
})
</script>

<template>
    <div class="flex flex-wrap gap-3">
        <span
            class="rounded-lg border px-4 py-2 text-sm font-bold"
            :class="isPosted
                ? 'border-green-500 bg-green-950/40 text-green-300'
                : 'border-yellow-500 bg-yellow-950/40 text-yellow-300'"
        >
            {{ statusLabel }}
        </span>

        <span
            class="rounded-lg border px-4 py-2 text-sm font-bold"
            :class="isBalanced
                ? 'border-blue-500 bg-blue-950/40 text-blue-300'
                : 'border-red-500 bg-red-950/40 text-red-300'"
        >
            {{ isBalanced ? 'BALANCEADO' : 'DESBALANCEADO' }}
        </span>
    </div>
</template>
