<script setup lang="ts">
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue';
import { formatCurrency } from '@/lib/formatters';

defineProps<{
    summary: Record<string, number>;
}>();
</script>

<template>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
        <ReportSummaryCard
            label="Saldo inicial"
            :value="formatCurrency(summary.opening_balance_cents)"
            tone="blue"
        />

        <ReportSummaryCard
            label="Entradas"
            :value="formatCurrency(summary.total_inflows_cents)"
            tone="green"
        />

        <ReportSummaryCard
            label="Saídas"
            :value="formatCurrency(summary.total_outflows_cents)"
            tone="red"
        />

        <ReportSummaryCard
            label="Saldo final"
            :value="formatCurrency(summary.closing_balance_cents)"
            tone="neutral"
        />
    </div>
</template>
