<script setup lang="ts">
import DateRangeFilter from '@/components/filters/DateRangeFilter.vue';
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportTable from '@/components/reports/ReportTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useAccountsReceivableIndex } from '@/composables/financial/useAccountsReceivableIndex';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatAccount, formatCurrency, formatDate } from '@/lib/formatters';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps<{
    wallet: Record<string, any>;
    filters: Record<string, string>;
    accountsReceivable: Record<string, any>;
}>();

const accountsReceivableIndex = useAccountsReceivableIndex(props.filters);

function formatPaginationLabel(label: string): string {
    return label.replace(/&laquo;/g, '«').replace(/&raquo;/g, '»').replace(/&amp;/g, '&');
}
</script>

<template>
    <AppLayout title="Contas a Receber">
        <ReportPage title="Contas a Receber" :subtitle="wallet.name">
            <div class="flex justify-end">
                <Link :href="route('accounts-receivable.create')" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-500">
                    Nova conta a receber
                </Link>
            </div>

            <DateRangeFilter v-model:start="accountsReceivableIndex.form.start_date" v-model:end="accountsReceivableIndex.form.end_date" />

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Filtros</h2>
                        <p class="mt-1 text-sm text-gray-400">Os títulos são atualizados automaticamente ao alterar os filtros.</p>
                    </div>
                </template>

                <div class="grid grid-cols-1 gap-4 p-6 md:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Status</label>
                        <select v-model="accountsReceivableIndex.form.status" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="">Todos</option>
                            <option value="pending">Pendente</option>
                            <option value="received">Recebido</option>
                            <option value="cancelled">Cancelado</option>
                        </select>
                    </div>

                    <div class="md:col-span-2">
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Busca</label>
                        <div class="flex gap-2">
                            <input v-model="accountsReceivableIndex.form.search" type="text" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="Cliente ou descrição..." />
                            <button type="button" class="rounded-lg border border-gray-700 px-3 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800" @click="accountsReceivableIndex.clearFilters">
                                Limpar
                            </button>
                        </div>
                    </div>
                </div>
            </ReportSection>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Títulos a receber</h2>
                        <p class="text-sm text-gray-400">Controle financeiro de receitas pendentes e recebidas.</p>
                    </div>
                </template>

                <ReportTable :empty="accountsReceivable.data.length === 0" empty-message="Nenhuma conta a receber encontrada." :empty-colspan="8">
                    <template #head>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Vencimento</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Cliente</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Descrição</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Receita</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Valor</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Recebimento</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Ações</th>
                        </tr>
                    </template>

                    <tr v-for="item in accountsReceivable.data" :key="item.id" class="hover:bg-gray-800/50">
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">{{ formatDate(item.due_date) }}</td>
                        <td class="px-4 py-3 text-sm font-semibold text-white">{{ item.customer_name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-300">{{ item.description }}</td>
                        <td class="px-4 py-3 text-sm text-gray-400">{{ formatAccount(item.revenue_account?.code, item.revenue_account?.name) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-gray-100">{{ formatCurrency(item.amount_cents) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm"><StatusBadge :status="item.status" /></td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">{{ item.received_at ? formatDate(item.received_at) : '-' }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                            <Link :href="route('accounts-receivable.show', [item.id])" class="inline-flex items-center rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-medium text-gray-200 transition hover:bg-gray-700">
                                Ver
                            </Link>
                        </td>
                    </tr>
                </ReportTable>

                <div v-if="accountsReceivable.links?.length > 3" class="flex flex-wrap items-center justify-center gap-2 border-t border-gray-700 px-4 py-4">
                    <template v-for="link in accountsReceivable.links" :key="link.label">
                        <span v-if="!link.url" class="rounded-md px-3 py-1.5 text-sm text-gray-500">{{ formatPaginationLabel(link.label) }}</span>
                        <Link v-else :href="link.url" class="rounded-md px-3 py-1.5 text-sm transition" :class="link.active ? 'bg-indigo-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700'">
                            {{ formatPaginationLabel(link.label) }}
                        </Link>
                    </template>
                </div>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
