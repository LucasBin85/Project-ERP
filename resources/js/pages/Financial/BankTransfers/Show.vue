<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportTable from '@/components/reports/ReportTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatAccount, formatCurrency, formatDate } from '@/lib/formatters';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

defineProps<{
    wallet: Record<string, any>;
    transfer: Record<string, any>;
}>();

function formatLineType(type: string): string {
    return type === 'debit' ? 'Débito' : 'Crédito';
}
</script>

<template>
    <AppLayout title="Transferência">
        <ReportPage title="Transferência Bancária" :subtitle="wallet.name">
            <div class="flex justify-end gap-3">
                <Link
                    :href="route('bank-transfers.index')"
                    class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                >
                    Voltar
                </Link>

                <Link
                    :href="route('journal-entries.show', [transfer.journal_entry_id])"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500"
                >
                    Ver lançamento contábil
                </Link>
            </div>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">
                            Dados da transferência
                        </h2>

                        <p class="text-sm text-gray-400">
                            Registro financeiro vinculado ao lançamento contábil #{{ transfer.journal_entry_id }}.
                        </p>
                    </div>
                </template>

                <div class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg border border-gray-700 bg-gray-900/60 p-4">
                        <p class="text-xs uppercase text-gray-500">Data</p>
                        <p class="mt-1 text-lg font-semibold text-white">
                            {{ formatDate(transfer.transfer_date) }}
                        </p>
                    </div>

                    <div class="rounded-lg border border-gray-700 bg-gray-900/60 p-4">
                        <p class="text-xs uppercase text-gray-500">Valor</p>
                        <p class="mt-1 text-lg font-semibold text-white">
                            {{ formatCurrency(transfer.amount_cents) }}
                        </p>
                    </div>

                    <div class="rounded-lg border border-gray-700 bg-gray-900/60 p-4">
                        <p class="text-xs uppercase text-gray-500">Origem</p>
                        <p class="mt-1 text-lg font-semibold text-white">
                            {{ transfer.from_bank_account?.name }}
                        </p>
                    </div>

                    <div class="rounded-lg border border-gray-700 bg-gray-900/60 p-4">
                        <p class="text-xs uppercase text-gray-500">Destino</p>
                        <p class="mt-1 text-lg font-semibold text-white">
                            {{ transfer.to_bank_account?.name }}
                        </p>
                    </div>
                </div>

                <div class="border-t border-gray-700 p-6">
                    <p class="text-xs uppercase text-gray-500">Descrição</p>
                    <p class="mt-1 text-sm text-gray-200">
                        {{ transfer.description }}
                    </p>
                </div>
            </ReportSection>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">
                            Lançamento contábil gerado
                        </h2>

                        <div class="mt-1 text-sm text-gray-400">
                            Status:
                            <StatusBadge :status="transfer.journal_entry?.status" />
                        </div>
                    </div>
                </template>

                <ReportTable
                    :empty="!transfer.journal_entry?.lines?.length"
                    empty-message="Nenhuma linha contábil encontrada."
                    :empty-colspan="3"
                >
                    <template #head>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Tipo</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Conta</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Valor</th>
                        </tr>
                    </template>

                    <tr
                        v-for="line in transfer.journal_entry?.lines"
                        :key="line.id"
                        class="hover:bg-gray-800/50"
                    >
                        <td class="whitespace-nowrap px-4 py-3 text-sm font-semibold text-gray-200">
                            {{ formatLineType(line.type) }}
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-300">
                            {{ formatAccount(line.chart_of_account?.code, line.chart_of_account?.name) }}
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-gray-100">
                            {{ formatCurrency(line.amount_cents) }}
                        </td>
                    </tr>
                </ReportTable>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
