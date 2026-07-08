<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportTable from '@/components/reports/ReportTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

defineProps<{
    wallet: Record<string, any>;
    reconciliations: Record<string, any>;
}>();

function formatPaginationLabel(label: string): string {
    return label
        .replace(/&laquo;/g, '«')
        .replace(/&raquo;/g, '»')
        .replace(/&amp;/g, '&');
}
</script>

<template>
    <AppLayout title="Conciliação Bancária">
        <ReportPage title="Conciliação Bancária" :subtitle="wallet.name">
            <div class="flex justify-end">
                <Link
                    :href="route('bank-reconciliations.create')"
                    class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-500"
                >
                    Nova conciliação
                </Link>
            </div>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">
                            Conciliações registradas
                        </h2>

                        <p class="text-sm text-gray-400">
                            Comparação manual entre o saldo informado pelo banco e as movimentações postadas no ERP.
                        </p>
                    </div>
                </template>

                <ReportTable
                    :empty="reconciliations.data.length === 0"
                    empty-message="Nenhuma conciliação encontrada."
                    :empty-colspan="7"
                >
                    <template #head>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Período</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Conta</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Saldo banco</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Saldo conciliado</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Diferença</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Ações</th>
                        </tr>
                    </template>

                    <tr
                        v-for="reconciliation in reconciliations.data"
                        :key="reconciliation.id"
                        class="hover:bg-gray-800/50"
                    >
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                            {{ formatDate(reconciliation.period_start) }} até {{ formatDate(reconciliation.period_end) }}
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-300">
                            {{ reconciliation.bank_account?.name ?? '-' }}
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-gray-100">
                            {{ formatCurrency(reconciliation.statement_balance_cents) }}
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-gray-100">
                            {{ formatCurrency(reconciliation.reconciled_balance_cents) }}
                        </td>

                        <td
                            class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold"
                            :class="Number(reconciliation.difference_cents) === 0 ? 'text-green-300' : 'text-yellow-300'"
                        >
                            {{ formatCurrency(reconciliation.difference_cents) }}
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-sm">
                            <StatusBadge :status="reconciliation.status" />
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                            <Link
                                :href="route('bank-reconciliations.show', [reconciliation.id])"
                                class="inline-flex items-center rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-medium text-gray-200 transition hover:bg-gray-700"
                            >
                                Ver
                            </Link>
                        </td>
                    </tr>
                </ReportTable>

                <div
                    v-if="reconciliations.links?.length > 3"
                    class="flex flex-wrap items-center justify-center gap-2 border-t border-gray-700 px-4 py-4"
                >
                    <template v-for="link in reconciliations.links" :key="link.label">
                        <span
                            v-if="!link.url"
                            class="rounded-md px-3 py-1.5 text-sm text-gray-500"
                        >
                            {{ formatPaginationLabel(link.label) }}
                        </span>

                        <Link
                            v-else
                            :href="link.url"
                            class="rounded-md px-3 py-1.5 text-sm transition"
                            :class="link.active
                                ? 'bg-indigo-600 text-white'
                                : 'bg-gray-800 text-gray-300 hover:bg-gray-700'"
                        >
                            {{ formatPaginationLabel(link.label) }}
                        </Link>
                    </template>
                </div>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
