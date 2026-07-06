<script setup>
defineProps({
    chartWidth: Number,
    chartHeight: Number,
    padding: Number,
    pointsRevenue: String,
    pointsExpense: String,
    revenuePoints: Array,
    expensePoints: Array,
    chartTicks: Array,
})

const emit = defineEmits(['go-to-date'])
</script>

<template>
    <div class="rounded-2xl border border-white/10 bg-[#111827] p-5 shadow">
        <div class="mb-5 flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-bold text-white">
                    Receitas x Despesas
                </h2>
                <p class="mt-1 text-sm text-gray-500">
                    Evolução diária no período filtrado.
                </p>
            </div>

            <div class="flex items-center gap-4 text-sm">
                <div class="flex items-center gap-2">
                    <span class="inline-block h-3 w-3 rounded-full bg-emerald-500"></span>
                    <span class="text-gray-400">Receitas</span>
                </div>

                <div class="flex items-center gap-2">
                    <span class="inline-block h-3 w-3 rounded-full bg-red-500"></span>
                    <span class="text-gray-400">Despesas</span>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <svg :viewBox="`0 0 ${chartWidth} ${chartHeight}`" class="min-w-[900px] w-full">
                <line
                    :x1="padding"
                    :y1="chartHeight - padding"
                    :x2="chartWidth - padding"
                    :y2="chartHeight - padding"
                    stroke="currentColor"
                    class="text-gray-700"
                />

                <line
                    :x1="padding"
                    :y1="padding"
                    :x2="padding"
                    :y2="chartHeight - padding"
                    stroke="currentColor"
                    class="text-gray-700"
                />

                <polyline
                    :points="pointsRevenue"
                    fill="none"
                    stroke="#10b981"
                    stroke-width="3"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />

                <polyline
                    :points="pointsExpense"
                    fill="none"
                    stroke="#ef4444"
                    stroke-width="3"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />

                <template v-for="(point, index) in revenuePoints" :key="`revenue-${index}`">
                    <circle
                        :cx="point.x"
                        :cy="point.y"
                        r="5"
                        fill="#10b981"
                        class="cursor-pointer"
                        @click="emit('go-to-date', point.date)"
                    />
                </template>

                <template v-for="(point, index) in expensePoints" :key="`expense-${index}`">
                    <circle
                        :cx="point.x"
                        :cy="point.y"
                        r="5"
                        fill="#ef4444"
                        class="cursor-pointer"
                        @click="emit('go-to-date', point.date)"
                    />
                </template>

                <template v-for="(tick, index) in chartTicks" :key="`tick-${index}`">
                    <text
                        :x="tick.x"
                        :y="chartHeight - 8"
                        text-anchor="middle"
                        font-size="11"
                        fill="currentColor"
                        class="text-gray-500"
                    >
                        {{ tick.label }}
                    </text>
                </template>
            </svg>
        </div>
    </div>
</template>
