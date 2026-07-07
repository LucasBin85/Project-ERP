<script setup>
import ReportSection from '@/components/reports/ReportSection.vue'
import { formatCurrency } from '@/lib/formatters'

defineProps({
    totals: Object,
    formatPercent: Function,
})
</script>

<template>
    <ReportSection>
        <div class="flex justify-between px-4 py-4 text-lg font-bold">
            <span class="text-white">
                Resultado Líquido
            </span>

            <div class="text-right">
                <div
                    :class="totals.net_income_cents >= 0
                        ? 'text-green-300'
                        : 'text-red-300'"
                >
                    {{ formatCurrency(totals.net_income_cents) }}
                </div>

                <div class="text-sm text-gray-400">
                    {{ formatPercent(totals.net_income_cents, totals.revenue_cents) }} da receita
                </div>
            </div>
        </div>
    </ReportSection>
</template>
