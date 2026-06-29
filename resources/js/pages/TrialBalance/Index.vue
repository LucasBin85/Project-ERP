<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import DateRangeFilter from '@/components/filters/DateRangeFilter.vue'
import ReportPage from '@/components/reports/ReportPage.vue'
import ReportSection from '@/components/reports/ReportSection.vue'
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue'
import ReportTable from '@/components/reports/ReportTable.vue'
import { useAutoFilters } from '@/composables/useAutoFilters'
import { useDateRangeFilter } from '@/composables/useDateRangeFilter'
import {
    formatCurrency,
    formatMoneyOrDash,
} from '@/lib/formatters'

const props = defineProps({
    wallet: Object,
    trialBalance: Object,
    filters: {
        type: Object,
        default: () => ({}),
    },
})

const { form } = useDateRangeFilter(props.filters)

useAutoFilters(form, 'trial-balance.index')

function isBalanced(trialBalance) {
    return Number(trialBalance.totals.difference_cents || 0) === 0
        && Number(trialBalance.totals.balance_difference_cents || 0) === 0
}

function differenceValue(trialBalance) {
    const movementDifference = Number(trialBalance.totals.difference_cents || 0)
    const balanceDifference = Number(trialBalance.totals.balance_difference_cents || 0)

    return Math.abs(movementDifference || balanceDifference)
}
</script>

<template>
    <AppLayout title="Balancete de Verificação">
        <ReportPage
            title="Balancete de Verificação"
            :subtitle="wallet.name"
        >

            <div class="flex flex-col justify-between gap-4 md:flex-row md:items-start">
                <div
                    class="rounded-xl border px-4 py-3 text-sm font-bold"
                    :class="isBalanced(trialBalance)
                        ? 'border-green-500 bg-green-950/40 text-green-300'
                        : 'border-red-500 bg-red-950/40 text-red-300'"
                >
                    <template v-if="isBalanced(trialBalance)">
                        BALANCEADO
                    </template>

                    <template v-else>
                        Diferença:
                        {{ formatCurrency(differenceValue(trialBalance)) }}
                    </template>
                </div>
            </div>

            <DateRangeFilter
                v-model:start="form.start_date"
                v-model:end="form.end_date"
            />

            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <ReportSummaryCard
                    label="Total Débitos"
                    :value="formatCurrency(trialBalance.totals.debit_cents)"
                    tone="green"
                />

                <ReportSummaryCard
                    label="Total Créditos"
                    :value="formatCurrency(trialBalance.totals.credit_cents)"
                    tone="blue"
                />

                <ReportSummaryCard
                    label="Saldo Devedor"
                    :value="formatCurrency(trialBalance.totals.debit_balance_cents)"
                    tone="green"
                />

                <ReportSummaryCard
                    label="Saldo Credor"
                    :value="formatCurrency(trialBalance.totals.credit_balance_cents)"
                    tone="blue"
                />
            </div>

            <ReportSection>
                <template #header>
                    <h2 class="text-lg font-bold text-white">
                        Contas movimentadas
                    </h2>
                </template>

                <ReportTable>
                    <template #head>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">
                                Código
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">
                                Conta
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">
                                Tipo
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">
                                Natureza
                            </th>

                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">
                                Débitos
                            </th>

                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">
                                Créditos
                            </th>

                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">
                                Saldo Devedor
                            </th>

                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">
                                Saldo Credor
                            </th>
                        </tr>
                    </template>

                    <tr
                        v-for="row in trialBalance.rows"
                        :key="row.account_id"
                        class="hover:bg-gray-800/50"
                    >
                        <td class="whitespace-nowrap px-4 py-3 text-sm font-mono text-gray-300">
                            {{ row.code }}
                        </td>

                        <td class="px-4 py-3 text-sm text-white">
                            {{ row.name }}
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-400">
                            {{ row.type }}
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-sm">
                            <span
                                class="rounded px-2 py-1 text-xs font-semibold"
                                :class="row.nature === 'devedora'
                                    ? 'bg-green-950 text-green-300'
                                    : 'bg-blue-950 text-blue-300'"
                            >
                                {{ row.nature }}
                            </span>
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-green-300">
                            {{ formatMoneyOrDash(row.debit_cents) }}
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-blue-300">
                            {{ formatMoneyOrDash(row.credit_cents) }}
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-green-300">
                            {{ formatMoneyOrDash(row.debit_balance_cents) }}
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-blue-300">
                            {{ formatMoneyOrDash(row.credit_balance_cents) }}
                        </td>
                    </tr>

                    <tr v-if="trialBalance.rows.length === 0">
                        <td
                            colspan="8"
                            class="px-4 py-8 text-center text-sm text-gray-400"
                        >
                            Nenhuma conta movimentada em lançamentos postados.
                        </td>
                    </tr>

                    <template #foot>
                        <tr>
                            <td
                                colspan="4"
                                class="px-4 py-4 text-right text-sm font-bold text-white"
                            >
                                Totais
                            </td>

                            <td class="whitespace-nowrap px-4 py-4 text-right text-sm font-bold text-green-300">
                                {{ formatCurrency(trialBalance.totals.debit_cents) }}
                            </td>

                            <td class="whitespace-nowrap px-4 py-4 text-right text-sm font-bold text-blue-300">
                                {{ formatCurrency(trialBalance.totals.credit_cents) }}
                            </td>

                            <td class="whitespace-nowrap px-4 py-4 text-right text-sm font-bold text-green-300">
                                {{ formatCurrency(trialBalance.totals.debit_balance_cents) }}
                            </td>

                            <td class="whitespace-nowrap px-4 py-4 text-right text-sm font-bold text-blue-300">
                                {{ formatCurrency(trialBalance.totals.credit_balance_cents) }}
                            </td>
                        </tr>
                    </template>
                </ReportTable>
            </ReportSection>
            
        </ReportPage>
    </AppLayout>
</template>