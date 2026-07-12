<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue';
import ReportTable from '@/components/reports/ReportTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useBankAccountsIndex } from '@/composables/financial/useBankAccountsIndex';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatAccount, formatCurrency, formatDate } from '@/lib/formatters';
import { Link, router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

defineProps<{
    wallet: Record<string, any>;
    bankAccounts: Array<Record<string, any>>;
    summary: Record<string, number>;
}>();

const bankAccountsView = useBankAccountsIndex();

function openAccount(accountId: number | string) {
    router.visit(route('bank-accounts.show', [accountId]));
}
</script>

<template>
    <AppLayout title="Contas Bancárias">
        <ReportPage title="Contas Bancárias" :subtitle="wallet.name">
            <div class="flex flex-wrap justify-end gap-3">
                <Link
                    :href="route('bank-transfers.create')"
                    class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                >
                    Nova transferência
                </Link>

                <Link
                    :href="route('bank-accounts.create')"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500"
                >
                    Nova conta bancária
                </Link>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <ReportSummaryCard label="Saldo atual total" :value="formatCurrency(summary.total_current_balance_cents)" tone="green" />

                <ReportSummaryCard label="Saldo inicial total" :value="formatCurrency(summary.total_opening_balance_cents)" tone="blue" />

                <ReportSummaryCard label="Contas ativas" :value="String(summary.active_accounts ?? 0)" tone="neutral" />

                <ReportSummaryCard label="Total de contas" :value="String(summary.accounts_count ?? 0)" tone="neutral" />
            </div>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Contas cadastradas</h2>
                        <p class="text-sm text-gray-400">
                            Clique em uma conta para abrir o painel operacional. A importação OFX fica disponível no Extrato Bancário.
                        </p>
                    </div>
                </template>

                <ReportTable :empty="bankAccounts.length === 0" empty-message="Nenhuma conta bancária cadastrada." :empty-colspan="8">
                    <template #head>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Conta</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Banco</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Tipo</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Conta contábil</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase">Saldo inicial</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase">Saldo atual</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Último movimento</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase">Status</th>
                        </tr>
                    </template>

                    <tr
                        v-for="account in bankAccounts"
                        :key="account.id"
                        class="cursor-pointer hover:bg-gray-800/50"
                        @click="openAccount(account.id)"
                    >
                        <td class="px-4 py-3 text-sm">
                            <Link
                                :href="route('bank-accounts.show', [account.id])"
                                class="font-semibold text-white hover:text-indigo-300"
                                @click.stop
                            >
                                {{ account.name }}
                            </Link>
                            <div class="text-xs text-gray-500">{{ account.agency || '-' }} / {{ account.account_number || '-' }}</div>
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-300">
                            {{ account.bank_name || '-' }}
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-300">
                            {{ bankAccountsView.formatType(account.account_type) }}
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-300">
                            {{ formatAccount(account.chart_of_account?.code, account.chart_of_account?.name) }}
                        </td>

                        <td class="px-4 py-3 text-right text-sm font-semibold whitespace-nowrap text-gray-100">
                            {{ formatCurrency(account.opening_balance_cents) }}
                        </td>

                        <td
                            class="px-4 py-3 text-right text-sm font-bold whitespace-nowrap"
                            :class="Number(account.current_balance_cents) >= 0 ? 'text-green-300' : 'text-red-300'"
                        >
                            {{ formatCurrency(account.current_balance_cents) }}
                        </td>

                        <td class="px-4 py-3 text-sm whitespace-nowrap text-gray-300">
                            {{ formatDate(account.last_transaction_at) }}
                        </td>

                        <td class="px-4 py-3 text-right text-sm whitespace-nowrap">
                            <StatusBadge :status="account.is_active ? 'active' : 'cancelled'" />
                        </td>
                    </tr>
                </ReportTable>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
