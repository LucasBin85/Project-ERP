<script setup>
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue'
import { formatCurrency } from '@/lib/formatters'

defineProps({
    totals: Object,
})
</script>

<template>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <ReportSummaryCard
            label="Receitas"
            :value="formatCurrency(totals.revenue_cents)"
            tone="green"
        />

        <ReportSummaryCard
            label="Despesas"
            :value="formatCurrency(totals.expense_cents)"
            tone="red"
        />

        <ReportSummaryCard
            label="Resultado Líquido"
            :value="formatCurrency(totals.net_income_cents)"
            :tone="totals.net_income_cents >= 0 ? 'green' : 'red'"
        />
    </div>
</template>
