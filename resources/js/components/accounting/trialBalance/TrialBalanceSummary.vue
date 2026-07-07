<script setup>
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue'
import { formatCurrency } from '@/lib/formatters'

defineProps({
    totals: Object,
})
</script>

<template>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
        <ReportSummaryCard
            label="Total Débitos"
            :value="formatCurrency(totals.debit_cents)"
            tone="green"
        />

        <ReportSummaryCard
            label="Total Créditos"
            :value="formatCurrency(totals.credit_cents)"
            tone="blue"
        />

        <ReportSummaryCard
            label="Saldo Devedor"
            :value="formatCurrency(totals.debit_balance_cents)"
            tone="green"
        />

        <ReportSummaryCard
            label="Saldo Credor"
            :value="formatCurrency(totals.credit_balance_cents)"
            tone="blue"
        />
    </div>
</template>
