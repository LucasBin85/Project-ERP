<script setup lang="ts">
defineProps<{
    start: string;
    end: string;
}>();

const emit = defineEmits<{
    'update:start': [value: string];
    'update:end': [value: string];
}>();
</script>

<template>
    <div class="rounded-xl border border-gray-700 bg-[#111827] p-4">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-semibold text-gray-300">
                    Data inicial
                </label>

                <input
                    :value="start"
                    type="date"
                    :max="end"
                    class="w-full cursor-pointer rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]"
                    @input="emit('update:start', $event.target.value)"
                />
            </div>

            <div>
                <label class="mb-1 block text-sm font-semibold text-gray-300">
                    Data final
                </label>

                <input
                    :value="end"
                    type="date"
                    :min="start"
                    class="w-full cursor-pointer rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]"
                    @input="emit('update:end', $event.target.value)"
                />
            </div>
        </div>
    </div>
</template>
