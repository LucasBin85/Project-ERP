<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue';
import ReportTable from '@/components/reports/ReportTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useCashFlowIndex } from '@/composables/financial/useCashFlowIndex';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { Link } from '@inertiajs/vue3';

const props = defineProps<{
    wallet: Record<string, any>;
    filters: Record<string, string>;
    summary: Record<string, number>;
    items: Array<Record<string, any>>;
}>();

const cashFlow = useCashFlowIndex(props.filters);

function sourceClass(source: string): string {
    const classes: Record<string, string> = {
        bank_movement: 'bg-green-950 text-green-300',
        accounts_receivable: 'bg-blue-950 text-blue-300',
        accounts_payable: 'bg-red-950 text-red-300',
        credit_card_invoice: 'bg-purple-950 text-purple-300',
    };

    return classes[source] ?? 'bg-gray-800 text-gray-300';
}
</script>

<template>
    <AppLayout title="Fluxo de Caixa">
        <ReportPage title="Fluxo de Caixa" :subtitle="wallet.name">
            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Filtros</h2>
                        <p class="mt-1 text-sm text-gray-400">A visão é atualizada automaticamente ao alterar o período, tipo ou busca.</p>
                    </div>
                </template>

                <div class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2 xl:grid-cols-5">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Data inicial</label>
                        <input v-model="cashFlow.form.start_date" type="date" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]" />
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Data final</label>
                        <input v-model="cashFlow.form.end_date" type="date" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]" />
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Tipo</label>
                        <select v-model="cashFlow.form.mode" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="all">Realizado + Projetado</option>
                            <option value="realized">Somente realizado</option>
                            <option value="projected">Somente projetado</option>
                        </select>
                    </div>

                    <div class="xl:col-span-2">
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Busca</label>
                        <div class="flex gap-2">
                            <input v-model="cashFlow.form.search" type="text" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="Descrição, cliente, fornecedor..." />
                            <button type="button" class="rounded-lg border border-gray-700 px-3 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800" @click="cashFlow.clearFilters">
                                Limpar
                            </button>
                        </div>
                    </div>
                </div>
            </ReportSection>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <ReportSummaryCard label="Saldo inicial" :value="formatCurrency(summary.opening_balance_cents)" tone="neutral" />
                <ReportSummaryCard label="Saldo realizado" :value="formatCurrency(summary.realized_closing_balance_cents)" :tone="summary.realized_closing_balance_cents >= 0 ? 'green' : 'red'" />
                <ReportSummaryCard label="Saldo projetado" :value="formatCurrency(summary.projected_closing_balance_cents)" :tone="summary.projected_closing_balance_cents >= 0 ? 'green' : 'red'" />
                <ReportSummaryCard label="Resultado projetado" :value="formatCurrency(summary.projected_net_cents)" :tone="summary.projected_net_cents >= 0 ? 'green' : 'red'" />
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <ReportSummaryCard label="Entradas realizadas" :value="formatCurrency(summary.realized_inflows_cents)" tone="green" />
                <ReportSummaryCard label="Saídas realizadas" :value="formatCurrency(summary.realized_outflows_cents)" tone="red" />
                <ReportSummaryCard label="Entradas previstas" :value="formatCurrency(summary.projected_inflows_cents)" tone="blue" />
                <ReportSummaryCard label="Saídas previstas" :value="formatCurrency(summary.projected_outflows_cents)" tone="yellow" />
            </div>

            <ReportSection>
                <template #header>
                    <div class="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h2 class="text-lg font-bold text-white">Movimentos do fluxo</h2>
                            <p class="text-sm text-gray-400">Realizado vem do extrato bancário; projetado vem de contas a pagar, contas a receber e faturas de cartão.</p>
                        </div>

                        <div class="text-sm text-gray-400">{{ items.length }} item(ns)</div>
                    </div>
                </template>

                <ReportTable :empty="items.length === 0" empty-message="Nenhum item de fluxo encontrado para o período." :empty-colspan="9">
                    <template #head>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Data</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Origem</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Descrição</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Cliente/Fornecedor</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Entrada</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Saída</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Saldo realizado</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Saldo projetado</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Status</th>
                        </tr>
                    </template>

                    <tr v-for="item in items" :key="item.id" class="hover:bg-gray-800/50">
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">{{ formatDate(item.date) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm">
                            <span class="rounded px-2 py-1 text-xs font-semibold" :class="sourceClass(item.source)">
                                {{ item.source_label }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-200">
                            <Link v-if="item.url" :href="item.url" class="font-medium hover:text-indigo-300">
                                {{ item.description }}
                            </Link>
                            <span v-else>{{ item.description }}</span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-400">{{ item.counterparty ?? '-' }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-green-300">
                            {{ item.amount_cents > 0 ? formatCurrency(item.amount_cents) : '-' }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-red-300">
                            {{ item.amount_cents < 0 ? formatCurrency(Math.abs(item.amount_cents)) : '-' }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-300">{{ formatCurrency(item.running_realized_balance_cents) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold" :class="item.running_projected_balance_cents >= 0 ? 'text-green-300' : 'text-red-300'">
                            {{ formatCurrency(item.running_projected_balance_cents) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm"><StatusBadge :status="item.status" /></td>
                    </tr>
                </ReportTable>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
