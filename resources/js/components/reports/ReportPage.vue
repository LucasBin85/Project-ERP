<script setup>
defineProps({
    title: String,
    subtitle: String,
})
</script>

<template>
    <div class="mx-auto w-full max-w-[1800px] px-8 py-6">
        <div class="mb-6">
            <h1 class="text-3xl font-bold text-white">
                {{ title }}
            </h1>

            <p
                v-if="subtitle"
                class="mt-1 text-gray-400"
            >
                {{ subtitle }}
            </p>
        </div>

        <div class="space-y-6 w-full">
            <slot />
        </div>
    </div>
</template>