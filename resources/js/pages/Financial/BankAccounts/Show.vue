<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue';
import ReportTable from '@/components/reports/ReportTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatAccount, formatCurrency, formatDate } from '@/lib/formatters';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { useBankAccountsIndex } from '@/composables/financial/useBankAccountsIndex';

const props = defineProps<{
    wallet: Record<string, any>;
    account: Record<string, any>;
    summary: Record<string, number>;
    recent_transactions: Array<Record<string, any>>;
    recent_imports: Array<Record<string, any>>;
    recent_reconciliations: Array<Record<string, any>>;
    recent_transfers: Array<Record<string, any>>;
    credit_cards: Array<Record<string, any>>;
    actions: Record<string, string>;
}>();

const bankAccountsView = useBankAccountsIndex();
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
                    class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                >
                    Ver extrato completo
                </Link>

                <Link
                    :href="actions.ofx_import_url"
                    class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                >
                    Importar OFX
                </Link>

                <Link
                    :href="actions.reconciliation_url"
                    class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                >
                    Conciliar
                </Link>

                <Link
                    :href="actions.transfer_url"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500"
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

                <div class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2 xl:grid-cols-6">
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
                        label="Resultado do mês"
                        :value="formatCurrency(summary.month_result_cents)"
                        :tone="Number(summary.month_result_cents) >= 0 ? 'green' : 'red'"
                    />

                    <ReportSummaryCard
                        label="OFX pendentes"
                        :value="String(summary.pending_ofx_transactions ?? 0)"
                        :tone="Number(summary.pending_ofx_transactions ?? 0) > 0 ? 'yellow' : 'green'"
                    />

                    <ReportSummaryCard
                        label="Cartões vinculados"
                        :value="String(summary.linked_credit_cards ?? 0)"
                        tone="blue"
                    />
                </div>

                <div class="grid grid-cols-1 gap-4 border-t border-gray-700 p-6 md:grid-cols-3">
                    <div>
                        <p class="text-xs uppercase text-gray-500">Conta contábil</p>
                        <p class="mt-1 text-sm text-gray-200">
                            {{ formatAccount(account.chart_of_account?.code, account.chart_of_account?.name) }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs uppercase text-gray-500">Saldo inicial</p>
                        <p class="mt-1 text-sm font-semibold text-gray-100">
                            {{ formatCurrency(account.opening_balance_cents) }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs uppercase text-gray-500">Último movimento</p>
                        <p class="mt-1 text-sm text-gray-200">
                            {{ formatDate(account.last_transaction_at) }}
                        </p>
                    </div>
                </div>
            </ReportSection>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                <ReportSection class="xl:col-span-2">
                    <template #header>
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 class="text-lg font-bold text-white">Extrato recente</h2>
                                <p class="text-sm text-gray-400">Últimos movimentos postados desta conta.</p>
                            </div>

                            <Link :href="actions.statement_url" class="text-sm font-semibold text-indigo-300 hover:text-indigo-200">
                                Ver extrato completo
                            </Link>
                        </div>
                    </template>

                    <ReportTable
                        :empty="recent_transactions.length === 0"
                        empty-message="Nenhum movimento postado nesta conta."
                        :empty-colspan="6"
                    >
                        <template #head>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Data</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Descrição</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Origem</th>
                                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Valor</th>
                                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Saldo</th>
                                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Lançamento</th>
                            </tr>
                        </template>

                        <tr v-for="item in recent_transactions" :key="item.id" class="hover:bg-gray-800/50">
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">{{ formatDate(item.date) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-200">{{ item.description || '-' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-400">{{ item.source || '-' }}</td>
                            <td
                                class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold"
                                :class="Number(item.amount_cents) >= 0 ? 'text-green-300' : 'text-red-300'"
                            >
                                {{ formatCurrency(item.amount_cents) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-gray-100">
                                {{ formatCurrency(item.running_balance_cents) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                                <Link
                                    v-if="item.journal_entry_id"
                                    :href="route('journal-entries.show', [item.journal_entry_id])"
                                    class="inline-flex items-center rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-medium text-gray-200 transition hover:bg-gray-700"
                                >
                                    JE-{{ String(item.journal_entry_id).padStart(6, '0') }}
                                </Link>
                            </td>
                        </tr>
                    </ReportTable>
                </ReportSection>

                <ReportSection>
                    <template #header>
                        <div>
                            <h2 class="text-lg font-bold text-white">Atalhos da conta</h2>
                            <p class="text-sm text-gray-400">Operações mais usadas nesta conta bancária.</p>
                        </div>
                    </template>

                    <div class="grid grid-cols-1 gap-3 p-6">
                        <Link :href="actions.statement_url" class="rounded-xl border border-gray-700 bg-gray-900/40 p-4 transition hover:border-indigo-500 hover:bg-gray-800">
                            <p class="font-semibold text-white">Extrato bancário</p>
                            <p class="mt-1 text-sm text-gray-400">Ver movimentos postados, entradas, saídas e saldo.</p>
                        </Link>

                        <Link :href="actions.ofx_import_url" class="rounded-xl border border-gray-700 bg-gray-900/40 p-4 transition hover:border-indigo-500 hover:bg-gray-800">
                            <p class="font-semibold text-white">Importar OFX</p>
                            <p class="mt-1 text-sm text-gray-400">Enviar extrato do banco para gerar lançamentos.</p>
                        </Link>

                        <Link :href="actions.reconciliation_url" class="rounded-xl border border-gray-700 bg-gray-900/40 p-4 transition hover:border-indigo-500 hover:bg-gray-800">
                            <p class="font-semibold text-white">Conciliação</p>
                            <p class="mt-1 text-sm text-gray-400">Comparar OFX com lançamentos internos.</p>
                        </Link>

                        <Link :href="actions.transfer_url" class="rounded-xl border border-gray-700 bg-gray-900/40 p-4 transition hover:border-indigo-500 hover:bg-gray-800">
                            <p class="font-semibold text-white">Transferência</p>
                            <p class="mt-1 text-sm text-gray-400">Transferir valores entre contas bancárias.</p>
                        </Link>

                        <Link :href="actions.credit_card_create_url" class="rounded-xl border border-gray-700 bg-gray-900/40 p-4 transition hover:border-indigo-500 hover:bg-gray-800">
                            <p class="font-semibold text-white">Cartão de crédito</p>
                            <p class="mt-1 text-sm text-gray-400">Cadastrar cartão vinculado a esta conta.</p>
                        </Link>
                    </div>
                </ReportSection>
            </div>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <ReportSection>
                    <template #header>
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 class="text-lg font-bold text-white">Importações OFX</h2>
                                <p class="text-sm text-gray-400">Últimos arquivos importados para esta conta.</p>
                            </div>
                            <Link :href="actions.ofx_import_url" class="text-sm font-semibold text-indigo-300 hover:text-indigo-200">Importar novo</Link>
                        </div>
                    </template>

                    <ReportTable
                        :empty="recent_imports.length === 0"
                        empty-message="Nenhuma importação OFX para esta conta."
                        :empty-colspan="6"
                    >
                        <template #head>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Arquivo</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Período</th>
                                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Transações</th>
                                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Importadas</th>
                                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Duplicadas</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Status</th>
                            </tr>
                        </template>

                        <tr v-for="item in recent_imports" :key="item.id" class="hover:bg-gray-800/50">
                            <td class="px-4 py-3 text-sm font-semibold text-white">{{ item.original_filename }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">{{ formatDate(item.statement_started_at) }} até {{ formatDate(item.statement_ended_at) }}</td>
                            <td class="px-4 py-3 text-right text-sm text-gray-300">{{ item.total_transactions }}</td>
                            <td class="px-4 py-3 text-right text-sm text-green-300">{{ item.imported_transactions }}</td>
                            <td class="px-4 py-3 text-right text-sm text-yellow-300">{{ item.skipped_duplicates }}</td>
                            <td class="px-4 py-3 text-sm"><StatusBadge :status="item.status" /></td>
                        </tr>
                    </ReportTable>
                </ReportSection>

                <ReportSection>
                    <template #header>
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 class="text-lg font-bold text-white">Conciliações</h2>
                                <p class="text-sm text-gray-400">Histórico recente de conciliações desta conta.</p>
                            </div>
                            <Link :href="actions.reconciliation_url" class="text-sm font-semibold text-indigo-300 hover:text-indigo-200">Nova conciliação</Link>
                        </div>
                    </template>

                    <ReportTable
                        :empty="recent_reconciliations.length === 0"
                        empty-message="Nenhuma conciliação para esta conta."
                        :empty-colspan="5"
                    >
                        <template #head>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Período</th>
                                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Extrato</th>
                                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Contábil</th>
                                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Diferença</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Status</th>
                            </tr>
                        </template>

                        <tr v-for="item in recent_reconciliations" :key="item.id" class="hover:bg-gray-800/50">
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">{{ formatDate(item.period_start) }} até {{ formatDate(item.period_end) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-300">{{ formatCurrency(item.statement_balance_cents) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-300">{{ formatCurrency(item.book_balance_cents) }}</td>
                            <td
                                class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold"
                                :class="Number(item.difference_cents) === 0 ? 'text-green-300' : 'text-yellow-300'"
                            >
                                {{ formatCurrency(item.difference_cents) }}
                            </td>
                            <td class="px-4 py-3 text-sm"><StatusBadge :status="item.status" /></td>
                        </tr>
                    </ReportTable>
                </ReportSection>
            </div>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <ReportSection>
                    <template #header>
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 class="text-lg font-bold text-white">Transferências</h2>
                                <p class="text-sm text-gray-400">Últimas transferências envolvendo esta conta.</p>
                            </div>
                            <Link :href="actions.transfer_url" class="text-sm font-semibold text-indigo-300 hover:text-indigo-200">Nova transferência</Link>
                        </div>
                    </template>

                    <ReportTable
                        :empty="recent_transfers.length === 0"
                        empty-message="Nenhuma transferência envolvendo esta conta."
                        :empty-colspan="5"
                    >
                        <template #head>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Data</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Descrição</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Contraparte</th>
                                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Valor</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Status</th>
                            </tr>
                        </template>

                        <tr v-for="item in recent_transfers" :key="item.id" class="hover:bg-gray-800/50">
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">{{ formatDate(item.transfer_date) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-200">{{ item.description }}</td>
                            <td class="px-4 py-3 text-sm text-gray-300">
                                {{ item.direction === 'in' ? item.from_bank_account?.name : item.to_bank_account?.name }}
                            </td>
                            <td
                                class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold"
                                :class="item.direction === 'in' ? 'text-green-300' : 'text-red-300'"
                            >
                                {{ formatCurrency(item.direction === 'in' ? item.amount_cents : Number(item.amount_cents) * -1) }}
                            </td>
                            <td class="px-4 py-3 text-sm"><StatusBadge :status="item.status" /></td>
                        </tr>
                    </ReportTable>
                </ReportSection>

                <ReportSection>
                    <template #header>
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 class="text-lg font-bold text-white">Cartões vinculados</h2>
                                <p class="text-sm text-gray-400">Cartões de crédito associados a esta conta bancária.</p>
                            </div>
                            <Link :href="actions.credit_card_create_url" class="text-sm font-semibold text-indigo-300 hover:text-indigo-200">Novo cartão</Link>
                        </div>
                    </template>

                    <ReportTable
                        :empty="credit_cards.length === 0"
                        empty-message="Nenhum cartão vinculado a esta conta."
                        :empty-colspan="5"
                    >
                        <template #head>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Cartão</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Bandeira</th>
                                <th class="px-4 py-3 text-center text-xs font-bold uppercase text-gray-400">Fechamento</th>
                                <th class="px-4 py-3 text-center text-xs font-bold uppercase text-gray-400">Vencimento</th>
                                <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Limite</th>
                            </tr>
                        </template>

                        <tr v-for="card in credit_cards" :key="card.id" class="hover:bg-gray-800/50">
                            <td class="px-4 py-3 text-sm">
                                <Link :href="route('credit-cards.show', [card.id])" class="font-semibold text-white hover:text-indigo-300">
                                    {{ card.name }}
                                </Link>
                                <div class="text-xs text-gray-500">
                                    {{ card.last_four ? '•••• ' + card.last_four : '' }} · {{ card.child_cards?.length ?? 0 }} adicionais/virtuais
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-300">{{ card.network }}</td>
                            <td class="px-4 py-3 text-center text-sm text-gray-300">dia {{ card.closing_day }}</td>
                            <td class="px-4 py-3 text-center text-sm text-gray-300">dia {{ card.due_day }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-gray-100">{{ formatCurrency(card.credit_limit_cents) }}</td>
                        </tr>
                    </ReportTable>
                </ReportSection>
            </div>
        </ReportPage>
    </AppLayout>
</template>
