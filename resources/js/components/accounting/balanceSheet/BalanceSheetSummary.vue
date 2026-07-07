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
            label="Ativo"
            :value="formatCurrency(totals.assets_cents)"
            tone="green"
        />

        <ReportSummaryCard
            label="Passivo"
            :value="formatCurrency(totals.liabilities_cents)"
            tone="blue"
        />

        <ReportSummaryCard
            label="Patrimônio Líquido"
            :value="formatCurrency(totals.equity_cents)"
            tone="blue"
        />
    </div>
</template>
