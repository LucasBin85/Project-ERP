<script setup>
defineProps({
    form: Object,
    sources: Array,
    statuses: Array,
})
</script>

<template>
    <div class="grid w-full grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-4">
        <div>
            <label class="mb-1 block text-sm font-semibold text-gray-300">
                Origem
            </label>

            <select
                v-model="form.source"
                class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
            >
                <option value="">Todas</option>

                <option
                    v-for="source in sources"
                    :key="source.value"
                    :value="source.value"
                >
                    {{ source.label }}
                </option>
            </select>
        </div>

        <div>
            <label class="mb-1 block text-sm font-semibold text-gray-300">
                Status
            </label>

            <select
                v-model="form.status"
                class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
            >
                <option value="">Todos</option>

                <option
                    v-for="status in statuses"
                    :key="status.value"
                    :value="status.value"
                >
                    {{ status.label }}
                </option>
            </select>
        </div>

        <div>
            <label class="mb-1 block text-sm font-semibold text-gray-300">
                Busca
            </label>

            <input
                v-model="form.search"
                type="text"
                placeholder="Descrição..."
                class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
            />
        </div>
    </div>
</template>
