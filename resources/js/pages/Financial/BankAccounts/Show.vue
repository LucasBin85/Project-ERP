<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue';
import ReportTable from '@/components/reports/ReportTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { useBankAccountsIndex } from '@/composables/financial/useBankAccountsIndex';

const props = defineProps<{
    wallet: Record<string, any>;
    account: Record<string, any>;
    summary: Record<string, number>;
    recent_transactions: Array<Record<string, any>>;
    recent_transfers: Array<Record<string, any>>;
    credit_cards: Array<Record<string, any>>;
    actions: Record<string, string>;
}>();

const bankAccountsView = useBankAccountsIndex();

function invoiceLabel(invoice: Record<string, any> | null | undefined): string {
    if (!invoice) return 'Sem fatura';

    return `${String(invoice.reference_month).padStart(2, '0')}/${invoice.reference_year}`;
}
</script>

<template>
    <AppLayout :title="account.name">
        <ReportPage :title="account.name" :subtitle="wallet.name">
            <div class="flex flex-wrap justify-end gap-3">
                <Link
                    :href="route('bank-accounts.index')"
                    class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                >
                    Voltar
                </Link>

                <Link
                    :href="actions.statement_url"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500"
                >
                    Ver extrato
                </Link>

                <Link
                    :href="actions.transfer_url"
                    class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                >
                    Transferir
                </Link>
            </div>

            <ReportSection>
                <template #header>
                    <div class="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h2 class="text-lg font-bold text-white">Resumo da conta</h2>
                            <p class="text-sm text-gray-400">
                                {{ account.bank_name || '-' }} · {{ bankAccountsView.formatType(account.account_type) }} · Agência {{ account.agency || '-' }} · Conta {{ account.account_number || '-' }}
                            </p>
                        </div>

                        <StatusBadge :status="account.is_active ? 'active' : 'cancelled'" />
                    </div>
                </template>

                <div class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2 xl:grid-cols-5">
                    <ReportSummaryCard
                        label="Saldo atual"
                        :value="formatCurrency(summary.current_balance_cents)"
                        :tone="Number(summary.current_balance_cents) >= 0 ? 'green' : 'red'"
                    />

                    <ReportSummaryCard
                        label="Entradas no mês"
                        :value="formatCurrency(summary.month_inflows_cents)"
                        tone="green"
                    />

                    <ReportSummaryCard
                        label="Saídas no mês"
                        :value="formatCurrency(summary.month_outflows_cents)"
                        tone="red"
                    />

                    <ReportSummaryCard
                        label="Fatura atual cartão"
                        :value="formatCurrency(summary.current_card_invoice_cents ?? 0)"
                        :tone="Number(summary.current_card_invoice_cents ?? 0) > 0 ? 'yellow' : 'green'"
                    />

                    <ReportSummaryCard
                        label="OFX pendentes"
                        :value="String(summary.pending_ofx_transactions ?? 0)"
                        :tone="Number(summary.pending_ofx_transactions ?? 0) > 0 ? 'yellow' : 'green'"
                    />
                </div>

                <div class="grid grid-cols-1 gap-4 border-t border-gray-700 p-6 md:grid-cols-3">
                    <div>
                        <p class="text-xs uppercase text-gray-500">Saldo inicial</p>
                        <p class="mt-1 text-sm font-semibold text-gray-100">{{ formatCurrency(account.opening_balance_cents) }}</p>
                    </div>

                    <div>
                        <p class="text-xs uppercase text-gray-500">Resultado do mês</p>
                        <p class="mt-1 text-sm font-semibold" :class="Number(summary.month_result_cents) >= 0 ? 'text-green-300' : 'text-red-300'">
                            {{ formatCurrency(summary.month_result_cents) }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs uppercase text-gray-500">Último movimento</p>
                        <p class="mt-1 text-sm text-gray-200">{{ formatDate(account.last_transaction_at) }}</p>
                    </div>
                </div>
            </ReportSection>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <ReportSection>
                    <template #header>
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 class="text-lg font-bold text-white">Últimos movimentos</h2>
                                <p class="text-sm text-gray-400">Resumo rápido. O detalhamento completo fica no extrato.</p>
                            </div>

                            <Link :href="actions.statement_url" class="text-sm font-semibold text-indigo-300 hover:text-indigo-200">
                                Abrir extrato
                            </Link>
                        </div>
                    </template>

                    <ReportTable
                        :empty="recent_transactions.length === 0"
                        empty-message="Nenhum movimento postado nesta conta."
                        :empty-colspan="5"
                    >
                        <template #head>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Data</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Descrição</th>
                                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Valor</th>
                                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Saldo</th>
                                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Lançamento</th>
                            </tr>
                        </template>

                        <tr v-for="item in recent_transactions" :key="item.id" class="hover:bg-gray-800/50">
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">{{ formatDate(item.date) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-200">{{ item.description || '-' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold" :class="Number(item.amount_cents) >= 0 ? 'text-green-300' : 'text-red-300'">
                                {{ formatCurrency(item.amount_cents) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-gray-100">{{ formatCurrency(item.running_balance_cents) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                                <Link v-if="item.journal_entry_id" :href="route('journal-entries.show', [item.journal_entry_id])" class="text-indigo-300 hover:text-indigo-200">
                                    JE-{{ String(item.journal_entry_id).padStart(6, '0') }}
                                </Link>
                            </td>
                        </tr>
                    </ReportTable>
                </ReportSection>

                <ReportSection>
                    <template #header>
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 class="text-lg font-bold text-white">Cartões vinculados</h2>
                                <p class="text-sm text-gray-400">Fatura atual dos cartões ligados a esta conta.</p>
                            </div>

                            <Link :href="actions.credit_card_create_url" class="text-sm font-semibold text-indigo-300 hover:text-indigo-200">
                                Novo cartão
                            </Link>
                        </div>
                    </template>

                    <div v-if="credit_cards.length === 0" class="p-6 text-sm text-gray-400">
                        Nenhum cartão vinculado a esta conta.
                    </div>

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
                                        {{ card.issuer_name }} · vencimento dia {{ card.due_day }} · {{ card.child_cards?.length ?? 0 }} cartão(ões) adicional/virtual
                                    </p>
                                </div>

                                <StatusBadge v-if="card.current_invoice" :status="card.current_invoice.status" />
                            </div>

                            <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                                <div>
                                    <p class="text-xs uppercase text-gray-500">Fatura</p>
                                    <p class="mt-1 text-sm font-semibold text-gray-100">{{ invoiceLabel(card.current_invoice) }}</p>
                                </div>
                                <div>
                                    <p class="text-xs uppercase text-gray-500">Vencimento</p>
                                    <p class="mt-1 text-sm text-gray-200">{{ formatDate(card.current_invoice?.due_at) }}</p>
                                </div>
                                <div>
                                    <p class="text-xs uppercase text-gray-500">Saldo da fatura</p>
                                    <p class="mt-1 text-sm font-semibold text-yellow-300">{{ formatCurrency(card.current_invoice?.balance_cents ?? 0) }}</p>
                                </div>
                            </div>
                        </Link>
                    </div>
                </ReportSection>
            </div>

            <ReportSection>
                <template #header>
                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h2 class="text-lg font-bold text-white">Transferências recentes</h2>
                            <p class="text-sm text-gray-400">Últimas transferências envolvendo esta conta.</p>
                        </div>

                        <Link :href="actions.transfer_url" class="text-sm font-semibold text-indigo-300 hover:text-indigo-200">
                            Nova transferência
                        </Link>
                    </div>
                </template>

                <ReportTable
                    :empty="recent_transfers.length === 0"
                    empty-message="Nenhuma transferência recente envolvendo esta conta."
                    :empty-colspan="5"
                >
                    <template #head>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Data</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Descrição</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Origem/Destino</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Valor</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Lançamento</th>
                        </tr>
                    </template>

                    <tr v-for="transfer in recent_transfers" :key="transfer.id" class="hover:bg-gray-800/50">
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">{{ formatDate(transfer.transfer_date) }}</td>
                        <td class="px-4 py-3 text-sm text-gray-200">{{ transfer.description }}</td>
                        <td class="px-4 py-3 text-sm text-gray-400">
                            <span v-if="transfer.direction === 'in'">De {{ transfer.from_bank_account?.name }}</span>
                            <span v-else>Para {{ transfer.to_bank_account?.name }}</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold" :class="transfer.direction === 'in' ? 'text-green-300' : 'text-red-300'">
                            {{ transfer.direction === 'in' ? '+' : '-' }} {{ formatCurrency(transfer.amount_cents) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                            <Link v-if="transfer.journal_entry_id" :href="route('journal-entries.show', [transfer.journal_entry_id])" class="text-indigo-300 hover:text-indigo-200">
                                JE-{{ String(transfer.journal_entry_id).padStart(6, '0') }}
                            </Link>
                        </td>
                    </tr>
                </ReportTable>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
