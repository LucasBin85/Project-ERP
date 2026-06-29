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
    formatDate,
    formatMoneyOrDash,
} from '@/lib/formatters'
import { Link } from '@inertiajs/vue3'

const props = defineProps({
    wallet: Object,
    filters: { type: Object, default: () => ({}) },
    accounts: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    selectedAccount: Object,
    summary: Object,
    entries: { type: Array, default: () => [] },
    ledgerReady: Boolean,
})

const { form } = useDateRangeFilter(props.filters)

form.chart_of_account_id = props.filters?.chart_of_account_id || ''
form.status = props.filters?.status || ''

useAutoFilters(form, 'ledger.index', {
    beforeFilter: () => {
        return Boolean(
            form.chart_of_account_id &&
            form.start_date &&
            form.end_date &&
            form.start_date <= form.end_date,
        )
    },
})


function typeLabel(type) {
    const map = {
        ativo: 'Ativo',
        passivo: 'Passivo',
        receita: 'Receita',
        despesa: 'Despesa',
        patrimonio: 'Patrimônio',
    }

    return map[type] || type || '—'
}
</script>

<template>

    <AppLayout title="Livro Razão">
        <ReportPage
            title="Livro Razão"
            subtitle="Movimentações por conta contábil."
        >
                
            <DateRangeFilter
                v-model:start="form.start_date"
                v-model:end="form.end_date"
            />

            <ReportSection>
                <div class="grid w-full grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <label
                            for="chart_of_account_id"
                            class="mb-1 block text-sm font-semibold text-gray-300"
                        >
                            Conta
                        </label>

                        <select
                            id="chart_of_account_id"
                            v-model="form.chart_of_account_id"
                            class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                        >
                            <option value="">Selecione</option>

                            <option
                                v-for="account in accounts"
                                :key="account.id"
                                :value="String(account.id)"
                            >
                                {{ account.label }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label
                            for="status"
                            class="mb-1 block text-sm font-semibold text-gray-300"
                        >
                            Status
                        </label>

                        <select
                            id="status"
                            v-model="form.status"
                            class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"
                        >
                            <option value="">Todos</option>

                            <option
                                v-for="status in statuses"
                                :key="status.value"
                                :value="status.value"
                            >
                                {{ status.label }}
                            </option>
                        </select>
                    </div>
                </div>
            </ReportSection>
            

            <ReportSection v-if="selectedAccount && ledgerReady">
                <div class="p-5">
                    <h2 class="text-lg font-bold text-white">
                        {{ selectedAccount.code }} - {{ selectedAccount.name }}
                    </h2>

                    <p class="mt-1 text-sm text-gray-400">
                        Tipo: {{ typeLabel(selectedAccount.type) }}
                        • Natureza:
                        {{ selectedAccount.normal_balance_side === 'debit' ? 'Devedora' : 'Credora' }}
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
            </ReportSection>

            <ReportSection>
                <template #header>
                    <h2 class="text-lg font-bold text-white">
                        Movimentações
                    </h2>
                </template>

                <ReportTable
                    :empty="!entries || entries.length === 0"
                    empty-message="Nenhuma movimentação encontrada para os filtros informados."
                    :empty-colspan="6"
                >
                    <template #head>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">
                                Data
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">
                                Lançamento
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">
                                Descrição
                            </th>

                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">
                                Débito
                            </th>

                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">
                                Crédito
                            </th>

                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">
                                Saldo
                            </th>
                        </tr>
                    </template>

                    <tr
                        v-for="entry in entries"
                        :key="entry.id"
                        class="hover:bg-gray-800/50"
                    >
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                            {{ formatDate(entry.date) }}
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-sm">
                            <Link
                                v-if="entry.entry_show_url"
                                :href="entry.entry_show_url"
                                class="text-blue-400 hover:text-blue-300"
                            >
                                {{ entry.entry_label }}
                            </Link>

                            <span
                                v-else
                                class="text-gray-300"
                            >
                                {{ entry.entry_label }}
                            </span>
                        </td>

                        <td class="px-4 py-3 text-sm text-white">
                            {{ entry.description || '—' }}
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-green-300">
                            {{ formatMoneyOrDash(entry.debit_cents) }}
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-blue-300">
                            {{ formatMoneyOrDash(entry.credit_cents) }}
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-white">
                            {{ formatCurrency(entry.running_balance_cents) }}
                        </td>
                    </tr>

                    <tr v-if="ledgerReady && !entries.length">
                        <td
                            colspan="6"
                            class="px-4 py-8 text-center text-sm text-gray-400"
                        >
                            Nenhuma movimentação encontrada para os filtros informados.
                        </td>
                    </tr>

                    <tr v-if="!ledgerReady">
                        <td
                            colspan="6"
                            class="px-4 py-8 text-center text-sm text-gray-400"
                        >
                            Selecione a conta e o período para visualizar o Livro Razão.
                        </td>
                    </tr>
                </ReportTable>
            </ReportSection>
            
        </ReportPage>
    </AppLayout>
</template>