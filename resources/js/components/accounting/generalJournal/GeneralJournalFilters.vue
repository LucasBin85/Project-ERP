<script setup>
defineProps({
    source: String,
    status: String,
    search: String,
    sources: Array,
    statuses: Array,
})

const emit = defineEmits(['update:source', 'update:status', 'update:search'])
</script>

<template>
    <div class="grid w-full grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-4">
        <div>
            <label class="mb-1 block text-sm font-semibold text-gray-300">
                Origem
            </label>

            <select
                :value="source"
                class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                @change="emit('update:source', $event.target.value)"
            >
                <option value="">Todas</option>

                <option
                    v-for="sourceOption in sources"
                    :key="sourceOption.value"
                    :value="sourceOption.value"
                >
                    {{ sourceOption.label }}
                </option>
            </select>
        </div>

        <div>
            <label class="mb-1 block text-sm font-semibold text-gray-300">
                Status
            </label>

            <select
                :value="status"
                class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                @change="emit('update:status', $event.target.value)"
            >
                <option value="">Todos</option>

                <option
                    v-for="statusOption in statuses"
                    :key="statusOption.value"
                    :value="statusOption.value"
                >
                    {{ statusOption.label }}
                </option>
            </select>
        </div>

        <div>
            <label class="mb-1 block text-sm font-semibold text-gray-300">
                Busca
            </label>

            <input
                :value="search"
                type="text"
                placeholder="Descrição..."
                class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                @input="emit('update:search', $event.target.value)"
            />
        </div>
    </div>
</template>
