<script setup>
import BalanceSheetSectionTable from '@/components/accounting/balanceSheet/BalanceSheetSectionTable.vue'
import ReportSection from '@/components/reports/ReportSection.vue'
import { formatCurrency } from '@/lib/formatters'

defineProps({
    balanceSheet: Object,
    rowPadding: Function,
    sectionTone: Function,
})
</script>

<template>
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <ReportSection>
            <template #header>
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-bold text-white">Ativo</h2>
                    <div class="text-sm font-bold text-green-300">
                        {{ formatCurrency(balanceSheet.totals.assets_cents) }}
                    </div>
                </div>
            </template>

            <BalanceSheetSectionTable
                :section="balanceSheet.sections[0]"
                :row-padding="rowPadding"
                amount-class="text-green-300"
                empty-message="Nenhuma conta de ativo encontrada."
            />
        </ReportSection>

        <ReportSection>
            <template #header>
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-bold text-white">
                        Passivo + Patrimônio Líquido
                    </h2>
                    <div class="text-sm font-bold text-blue-300">
                        {{ formatCurrency(balanceSheet.totals.liabilities_and_equity_cents) }}
                    </div>
                </div>
            </template>

            <div class="space-y-6">
                <div
                    v-for="section in balanceSheet.sections.slice(1)"
                    :key="section.key"
                >
                    <div class="flex items-center justify-between border-b border-gray-700 px-4 py-3">
                        <h3 class="text-base font-bold text-white">
                            {{ section.title }}
                        </h3>

                        <span class="text-sm font-bold" :class="sectionTone(section.key)">
                            {{ formatCurrency(section.total_cents) }}
                        </span>
                    </div>

                    <BalanceSheetSectionTable
                        :section="section"
                        :row-padding="rowPadding"
                        :amount-class="sectionTone(section.key)"
                        :empty-message="`Nenhuma conta de ${section.title.toLowerCase()} encontrada.`"
                    />
                </div>
            </div>
        </ReportSection>
    </div>
</template>
