<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useBankAccountsIndex } from '@/composables/financial/useBankAccountsIndex';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCurrency, formatDate } from '@/lib/formatters';
import type { BankAccountOverview, BankAccountShowSummary } from '@/types/financial/bankAccount';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { route } from 'ziggy-js';

const props = defineProps<{
    wallet: { id: number; name: string };
    account: BankAccountOverview;
    summary: BankAccountShowSummary;
    credit_cards: Array<Record<string, any>>;
    actions: Record<string, string>;
}>();

const bankAccountsView = useBankAccountsIndex();
const statementBalanceCents = computed(() =>
    Number(props.summary.statement_balance_cents ?? props.account.statement_balance_cents ?? props.summary.current_balance_cents ?? 0),
);
const accountingBalanceCents = computed(() =>
    Number(props.summary.accounting_balance_cents ?? props.account.accounting_balance_cents ?? props.summary.current_balance_cents ?? 0),
);

function invoiceLabel(invoice: Record<string, any> | null | undefined): string {
    if (!invoice) return 'Sem fatura';

    return `${String(invoice.reference_month).padStart(2, '0')}/${invoice.reference_year}`;
}
</script>

<template>
    <AppLayout title="Conta Bancária">
        <ReportPage title="Conta Bancária" :subtitle="`${account.name} · ${wallet.name}`">
            <div class="flex flex-wrap justify-end gap-3">
                <Link
                    :href="route('bank-accounts.index')"
                    class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                >
                    Voltar
                </Link>

                <Link :href="actions.statement_url" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                    Abrir Extrato
                </Link>
            </div>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <ReportSection>
                    <template #header>
                        <div class="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 class="text-lg font-bold text-white">Resumo da conta</h2>
                                <p class="text-sm text-gray-400">
                                    {{ account.bank_name || '-' }} · {{ bankAccountsView.formatType(account.account_type) }} · Agência
                                    {{ account.agency || '-' }} · Conta {{ account.account_number || '-' }}
                                </p>
                            </div>

                            <StatusBadge :status="account.is_active ? 'active' : 'cancelled'" />
                        </div>
                    </template>

                    <div class="flex min-h-[220px] flex-col justify-between">
                        <div class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2">
                            <div>
                                <p class="text-sm font-medium text-gray-400">Saldo do extrato</p>
                                <p class="mt-2 text-3xl font-bold" :class="statementBalanceCents >= 0 ? 'text-green-300' : 'text-red-300'">
                                    {{ formatCurrency(statementBalanceCents) }}
                                </p>
                                <p class="mt-2 text-xs leading-5 text-gray-500">Saldo conforme os movimentos disponíveis no Extrato.</p>
                            </div>

                            <div class="rounded-xl border border-gray-700 bg-gray-900/50 p-4">
                                <p class="text-sm font-medium text-gray-400">Saldo contábil</p>
                                <p class="mt-2 text-xl font-bold" :class="accountingBalanceCents >= 0 ? 'text-blue-300' : 'text-red-300'">
                                    {{ formatCurrency(accountingBalanceCents) }}
                                </p>
                                <p class="mt-2 text-xs leading-5 text-gray-500">Considera somente lançamentos postados.</p>
                            </div>
                        </div>

                        <div class="border-t border-gray-700 px-6 py-3 text-sm text-gray-400">
                            Última atualização: {{ formatDate(account.last_transaction_at) }}
                        </div>
                    </div>
                </ReportSection>

                <ReportSection>
                    <template #header>
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 class="text-lg font-bold text-white">Cartão de Crédito</h2>
                                <p class="text-sm text-gray-400">Fatura atual dos cartões ligados a esta conta.</p>
                            </div>

                            <Link :href="actions.credit_card_create_url" class="text-sm font-semibold text-indigo-300 hover:text-indigo-200">
                                Novo cartão
                            </Link>
                        </div>
                    </template>

                    <div class="min-h-[220px]">
                        <div v-if="credit_cards.length === 0" class="p-6 text-sm text-gray-400">Nenhum cartão vinculado a esta conta.</div>

                        <div v-else class="grid grid-cols-1 gap-4 p-6">
                            <Link
                                v-for="card in credit_cards"
                                :key="card.id"
                                :href="route('credit-cards.show', [card.id])"
                                class="rounded-xl border border-gray-700 bg-gray-900/40 p-4 transition hover:border-indigo-500 hover:bg-gray-800"
                            >
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="font-semibold text-white">{{ card.name }}</p>
                                        <p class="mt-1 text-xs text-gray-500">
                                            {{ card.issuer_name }} · vencimento dia {{ card.due_day }} ·
                                            {{ card.child_cards?.length ?? 0 }} cartão(ões) adicional/virtual
                                        </p>
                                    </div>

                                    <StatusBadge v-if="card.current_invoice" :status="card.current_invoice.status" />
                                </div>

                                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                                    <div>
                                        <p class="text-xs text-gray-500 uppercase">Fatura</p>
                                        <p class="mt-1 text-sm font-semibold text-gray-100">{{ invoiceLabel(card.current_invoice) }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-gray-500 uppercase">Vencimento</p>
                                        <p class="mt-1 text-sm text-gray-200">{{ formatDate(card.current_invoice?.due_at) }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-gray-500 uppercase">Saldo da fatura</p>
                                        <p class="mt-1 text-sm font-semibold text-yellow-300">
                                            {{ formatCurrency(card.current_invoice?.balance_cents ?? 0) }}
                                        </p>
                                    </div>
                                </div>
                            </Link>
                        </div>
                    </div>
                </ReportSection>
            </div>
        </ReportPage>
    </AppLayout>
</template>
