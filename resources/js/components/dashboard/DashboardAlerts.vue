<script setup>
import { formatCurrency } from '@/lib/formatters'

defineProps({
    alerts: {
        type: Array,
        default: () => [],
    },
})
</script>

<template>
    <section v-if="alerts.length" class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div
            v-for="alert in alerts"
            :key="alert.title"
            class="rounded-2xl border p-4"
            :class="{
                'border-red-500/30 bg-red-950/20': alert.tone === 'red',
                'border-yellow-500/30 bg-yellow-950/20': alert.tone === 'yellow',
                'border-blue-500/30 bg-blue-950/20': alert.tone === 'blue',
            }"
        >
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3
                        class="text-sm font-bold"
                        :class="{
                            'text-red-300': alert.tone === 'red',
                            'text-yellow-300': alert.tone === 'yellow',
                            'text-blue-300': alert.tone === 'blue',
                        }"
                    >
                        {{ alert.title }}
                    </h3>

                    <p class="mt-1 text-sm text-gray-400">
                        {{ alert.message }}
                    </p>
                </div>

                <p
                    class="whitespace-nowrap text-sm font-bold"
                    :class="{
                        'text-red-300': alert.tone === 'red',
                        'text-yellow-300': alert.tone === 'yellow',
                        'text-blue-300': alert.tone === 'blue',
                    }"
                >
                    {{ formatCurrency(alert.amount_cents) }}
                </p>
            </div>
        </div>
    </section>
</template>
