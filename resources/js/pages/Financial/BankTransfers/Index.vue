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
    transfers: Record<string, any>;
}>();

function formatPaginationLabel(label: string): string {
    return label
        .replace(/&laquo;/g, '«')
        .replace(/&raquo;/g, '»')
        .replace(/&amp;/g, '&');
}
</script>

<template>
    <AppLayout title="Transferências">
        <ReportPage title="Transferências" :subtitle="wallet.name">
            <div class="flex justify-end">
                <Link
                    :href="route('bank-transfers.create')"
                    class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-500"
                >
                    Nova transferência
                </Link>
            </div>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">
                            Transferências entre contas bancárias
                        </h2>

                        <p class="text-sm text-gray-400">
                            Movimentações financeiras que geram lançamentos contábeis automaticamente.
                        </p>
                    </div>
                </template>

                <ReportTable
                    :empty="transfers.data.length === 0"
                    empty-message="Nenhuma transferência encontrada."
                    :empty-colspan="6"
                >
                    <template #head>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Data</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Descrição</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Origem</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Destino</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Valor</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Ações</th>
                        </tr>
                    </template>

                    <tr
                        v-for="transfer in transfers.data"
                        :key="transfer.id"
                        class="hover:bg-gray-800/50"
                    >
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                            {{ formatDate(transfer.transfer_date) }}
                        </td>

                        <td class="px-4 py-3 text-sm">
                            <div class="font-semibold text-white">
                                {{ transfer.description }}
                            </div>

                            <div class="text-xs text-gray-500">
                                #{{ transfer.id }} · Lançamento #{{ transfer.journal_entry_id }}
                            </div>
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-300">
                            {{ transfer.from_bank_account?.name ?? '-' }}
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-300">
                            {{ transfer.to_bank_account?.name ?? '-' }}
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-gray-100">
                            {{ formatCurrency(transfer.amount_cents) }}
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                            <Link
                                :href="route('bank-transfers.show', [transfer.id])"
                                class="inline-flex items-center rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-medium text-gray-200 transition hover:bg-gray-700"
                            >
                                Ver
                            </Link>
                        </td>
                    </tr>
                </ReportTable>

                <div
                    v-if="transfers.links?.length > 3"
                    class="flex flex-wrap items-center justify-center gap-2 border-t border-gray-700 px-4 py-4"
                >
                    <template v-for="link in transfers.links" :key="link.label">
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
