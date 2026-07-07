<script setup>
defineProps({
    wallet: Object,
    startDate: String,
    endDate: String,
    periodLabel: String,
})

const emit = defineEmits(['clear-filters', 'open-date-picker', 'update:startDate', 'update:endDate'])
</script>

<template>
    <section class="rounded-2xl border border-white/10 bg-gradient-to-br from-[#10213a] to-[#0b1220] p-6 shadow">
        <div class="flex flex-col justify-between gap-6 lg:flex-row lg:items-start">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-blue-300">
                    Dashboard financeiro
                </p>

                <h1 class="mt-2 text-3xl font-bold text-white">
                    {{ wallet.name }}
                </h1>

                <p class="mt-2 text-sm text-gray-400">
                    Visão consolidada de receitas, despesas, resultado e últimos lançamentos.
                </p>

                <p class="mt-3 text-sm text-gray-500">
                    Período: {{ periodLabel }}
                </p>
            </div>

            <div class="grid grid-cols-1 gap-3 md:grid-cols-3 lg:min-w-[520px]">
                <div>
                    <label class="mb-1 block text-xs font-bold uppercase text-gray-400">
                        Data inicial
                    </label>

                    <input
                        :value="startDate"
                        type="date"
                        :max="endDate || undefined"
                        class="w-full rounded-lg border border-gray-700 bg-gray-950 px-3 py-2 text-sm text-white outline-none focus:border-blue-500"
                        @input="emit('update:startDate', $event.target.value)"
                        @click="emit('open-date-picker', $event)"
                    >
                </div>

                <div>
                    <label class="mb-1 block text-xs font-bold uppercase text-gray-400">
                        Data final
                    </label>

                    <input
                        :value="endDate"
                        type="date"
                        :min="startDate || undefined"
                        class="w-full rounded-lg border border-gray-700 bg-gray-950 px-3 py-2 text-sm text-white outline-none focus:border-blue-500"
                        @input="emit('update:endDate', $event.target.value)"
                        @click="emit('open-date-picker', $event)"
                    >
                </div>

                <div class="flex items-end">
                    <button
                        type="button"
                        class="w-full rounded-lg border border-gray-700 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                        @click="emit('clear-filters')"
                    >
                        Limpar
                    </button>
                </div>
            </div>
        </div>
    </section>
</template>
