<script setup>
import ReportSection from '@/components/reports/ReportSection.vue'
import ReportTable from '@/components/reports/ReportTable.vue'
import { formatCurrency } from '@/lib/formatters'

defineProps({
    section: Object,
    revenueCents: Number,
    formatPercent: Function,
    rowPadding: Function,
    amountClass: Function,
})
</script>

<template>
    <ReportSection>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-bold text-white">
                    {{ section.title }}
                </h2>

                <div class="text-sm font-bold" :class="amountClass(section.key)">
                    {{ formatCurrency(section.total_cents) }}
                </div>
            </div>
        </template>

        <ReportTable
            :empty="section.rows.length === 0"
            :empty-message="`Nenhum lançamento encontrado em ${section.title.toLowerCase()} no período.`"
            :empty-colspan="4"
        >
            <template #head>
                <tr>
                    <th class="w-[160px] px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Código</th>
                    <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Conta</th>
                    <th class="w-[160px] px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">% Receita</th>
                    <th class="w-[180px] px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Valor</th>
                </tr>
            </template>

            <tr
                v-for="row in section.rows"
                :key="row.account_id"
                class="hover:bg-gray-800/50"
                :class="row.is_summary ? 'bg-gray-900/40' : ''"
            >
                <td class="w-[160px] whitespace-nowrap px-4 py-3 text-sm font-mono text-gray-300">
                    {{ row.code }}
                </td>

                <td
                    class="px-4 py-3 text-sm text-white"
                    :class="row.is_summary ? 'font-bold' : ''"
                >
                    <span :style="{ paddingLeft: rowPadding(row.level) }">
                        {{ row.name }}
                    </span>
                </td>

                <td class="w-[160px] whitespace-nowrap px-4 py-3 text-right text-sm text-gray-400">
                    {{ formatPercent(row.amount_cents, revenueCents) }}
                </td>

                <td
                    class="w-[180px] whitespace-nowrap px-4 py-3 text-right text-sm font-semibold"
                    :class="amountClass(section.key)"
                >
                    {{ formatCurrency(row.amount_cents) }}
                </td>
            </tr>

            <template #foot>
                <tr>
                    <td colspan="2" class="px-4 py-4 text-right text-sm font-bold text-white">
                        Total {{ section.title }}
                    </td>

                    <td class="w-[160px] whitespace-nowrap px-4 py-4 text-right text-sm font-bold text-gray-300">
                        {{ formatPercent(section.total_cents, revenueCents) }}
                    </td>

                    <td
                        class="w-[180px] whitespace-nowrap px-4 py-4 text-right text-sm font-bold"
                        :class="amountClass(section.key)"
                    >
                        {{ formatCurrency(section.total_cents) }}
                    </td>
                </tr>
            </template>
        </ReportTable>
    </ReportSection>
</template>
