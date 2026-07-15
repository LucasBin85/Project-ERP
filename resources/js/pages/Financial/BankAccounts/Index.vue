<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue';
import ReportTable from '@/components/reports/ReportTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useBankAccountsIndex } from '@/composables/financial/useBankAccountsIndex';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatAccount, formatCurrency, formatDate } from '@/lib/formatters';
import type { BankAccountOverview, BankAccountsIndexSummary } from '@/types/financial/bankAccount';
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { route } from 'ziggy-js';

const props = defineProps<{
    wallet: { id: number; name: string };
    bankAccounts: BankAccountOverview[];
    summary: BankAccountsIndexSummary;
}>();

const bankAccountsView = useBankAccountsIndex();

const totalStatementBalanceCents = computed(() =>
    Number(props.summary.total_statement_balance_cents ?? props.summary.total_current_balance_cents ?? 0),
);
const totalAccountingBalanceCents = computed(() =>
    Number(props.summary.total_accounting_balance_cents ?? props.summary.total_current_balance_cents ?? 0),
);

function accountUrl(account: BankAccountOverview): string {
    return account.show_url ?? route('bank-accounts.show', [account.id]);
}

function statementBalanceCents(account: BankAccountOverview): number {
    return Number(account.statement_balance_cents ?? account.current_balance_cents ?? 0);
}

function accountingBalanceCents(account: BankAccountOverview): number {
    return Number(account.accounting_balance_cents ?? account.current_balance_cents ?? 0);
}

function openAccount(account: BankAccountOverview) {
    router.visit(accountUrl(account));
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
                <ReportSummaryCard label="Saldo do extrato total" :value="formatCurrency(totalStatementBalanceCents)" tone="green" />

                <ReportSummaryCard label="Saldo contábil total · somente postados" :value="formatCurrency(totalAccountingBalanceCents)" tone="blue" />

                <ReportSummaryCard label="Contas ativas" :value="String(summary.active_accounts ?? 0)" tone="neutral" />

                <ReportSummaryCard label="Total de contas" :value="String(summary.accounts_count ?? 0)" tone="neutral" />
            </div>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Contas cadastradas</h2>
                        <p class="text-sm text-gray-400">
                            Clique em uma conta para abrir o Resumo. O Extrato e a importação OFX ficam disponíveis no contexto da conta.
                        </p>
                    </div>
                </template>

                <ReportTable :empty="bankAccounts.length === 0" empty-message="Nenhuma conta bancária cadastrada." :empty-colspan="9">
                    <template #head>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Conta</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Banco</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Tipo</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Conta contábil</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase">Saldo inicial</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase">Saldo do extrato</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase">
                                <span class="block">Saldo contábil</span>
                                <span class="mt-0.5 block text-[10px] font-medium tracking-normal text-gray-500 normal-case">Somente postados</span>
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Último movimento</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase">Status</th>
                        </tr>
                    </template>

                    <tr v-for="account in bankAccounts" :key="account.id" class="cursor-pointer hover:bg-gray-800/50" @click="openAccount(account)">
                        <td class="px-4 py-3 text-sm">
                            <Link :href="accountUrl(account)" class="font-semibold text-white hover:text-indigo-300" @click.stop>
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
                            :class="statementBalanceCents(account) >= 0 ? 'text-green-300' : 'text-red-300'"
                        >
                            {{ formatCurrency(statementBalanceCents(account)) }}
                        </td>

                        <td
                            class="px-4 py-3 text-right text-sm font-semibold whitespace-nowrap"
                            :class="accountingBalanceCents(account) >= 0 ? 'text-blue-300' : 'text-red-300'"
                        >
                            {{ formatCurrency(accountingBalanceCents(account)) }}
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
