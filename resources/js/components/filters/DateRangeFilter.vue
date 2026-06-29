<script setup>
const props = defineProps({
    start: {
        type: String,
        required: true,
    },
    end: {
        type: String,
        required: true,
    },
    startLabel: {
        type: String,
        default: 'Data inicial',
    },
    endLabel: {
        type: String,
        default: 'Data final',
    },
})

const emit = defineEmits([
    'update:start',
    'update:end',
])

const updateStart = (value) => {
    if (props.end && value > props.end) return

    emit('update:start', value)
}

const updateEnd = (value) => {
    if (props.start && value < props.start) return

    emit('update:end', value)
}
</script>

<template>
    <div class="rounded-xl border border-gray-700 bg-[#111827] p-4">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label
                    class="mb-1 block text-sm font-semibold text-gray-300"
                >
                    {{ startLabel }}
                </label>

                <input
                    :value="start"
                    type="date"
                    :max="end"
                    class="w-full cursor-pointer rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]"
                    @input="updateStart($event.target.value)"
                />
            </div>

            <div>
                <label
                    class="mb-1 block text-sm font-semibold text-gray-300"
                >
                    {{ endLabel }}
                </label>

                <input
                    :value="end"
                    type="date"
                    :min="start"
                    class="w-full cursor-pointer rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]"
                    @input="updateEnd($event.target.value)"
                />
            </div>
        </div>
    </div>
</template>