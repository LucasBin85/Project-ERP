<script setup>
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue'
import { formatCurrency } from '@/lib/formatters'

defineProps({
    selectedAccount: Object,
    summary: Object,
    typeLabel: Function,
    normalBalanceLabel: Function,
})
</script>

<template>
    <div class="p-5">
        <h2 class="text-lg font-bold text-white">
            {{ selectedAccount.code }} - {{ selectedAccount.name }}
        </h2>

        <p class="mt-1 text-sm text-gray-400">
            Tipo: {{ typeLabel(selectedAccount.type) }}
            • Natureza: {{ normalBalanceLabel(selectedAccount.normal_balance_side) }}
        </p>

        <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            <ReportSummaryCard
                label="Saldo inicial"
                :value="formatCurrency(summary.opening_balance_cents)"
            />

            <ReportSummaryCard
                label="Débitos"
                :value="formatCurrency(summary.total_debits_cents)"
                tone="green"
            />

            <ReportSummaryCard
                label="Créditos"
                :value="formatCurrency(summary.total_credits_cents)"
                tone="blue"
            />

            <ReportSummaryCard
                label="Saldo final"
                :value="formatCurrency(summary.closing_balance_cents)"
            />
        </div>
    </div>
</template>
