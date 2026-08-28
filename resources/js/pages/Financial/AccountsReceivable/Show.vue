<script setup lang="ts">
import FinancialTitleCancellationDialog from '@/components/financial/FinancialTitleCancellationDialog.vue';
import FinancialTitleSettlementReversalDialog from '@/components/financial/FinancialTitleSettlementReversalDialog.vue';
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue';
import ReportTable from '@/components/reports/ReportTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useAccountReceivableReceipt } from '@/composables/financial/useAccountReceivableReceipt';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatAccount, formatCurrency, formatDate } from '@/lib/formatters';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps<{
    wallet: Record<string, any>;
    accountReceivable: Record<string, any>;
    bankAccounts: Array<Record<string, any>>;
}>();

const receipt = useAccountReceivableReceipt();

function submitReceipt() {
    if (!receipt.canSubmit.value) return;
    receipt.form.post(route('accounts-receivable.receive', [props.accountReceivable.id]));
}
</script>

<template>
    <AppLayout title="Conta a Receber">
        <ReportPage title="Conta a Receber" :subtitle="wallet.name">
            <div class="flex justify-end gap-3">
                <Link
                    :href="route('accounts-receivable.index')"
                    class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                    >Voltar</Link
                >
                <Link
                    v-if="accountReceivable.receipt_journal_entry_id"
                    :href="route('journal-entries.show', [accountReceivable.receipt_journal_entry_id])"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500"
                    >Ver lançamento contábil</Link
                >
            </div>

            <FinancialTitleCancellationDialog
                v-if="accountReceivable.status === 'pending' && !accountReceivable.receipt_journal_entry_id"
                route-name="accounts-receivable.cancel"
                :title-id="accountReceivable.id"
                :requires-reversal="
                    (accountReceivable.series?.provision_journal_entry ?? accountReceivable.provision_journal_entry)?.status === 'posted'
                "
            />
            <FinancialTitleSettlementReversalDialog
                v-if="
                    accountReceivable.status === 'received' &&
                    accountReceivable.receipt_journal_entry &&
                    (accountReceivable.receipt_journal_entry.source === 'manual' ||
                        accountReceivable.receipt_journal_entry.bank_statement_import_transaction)
                "
                route-name="accounts-receivable.reverse-settlement"
                :title-id="accountReceivable.id"
                :settlement-status="accountReceivable.receipt_journal_entry.status"
                action-label="Reverter recebimento"
                :bank-settlement="!!accountReceivable.receipt_journal_entry.bank_statement_import_transaction"
            />

            <ReportSection>
                <template #header>
                    <div class="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h2 class="text-lg font-bold text-white">{{ accountReceivable.description }}</h2>
                            <p class="text-sm text-gray-400">{{ accountReceivable.customer_name }}</p>
                        </div>
                        <StatusBadge :status="accountReceivable.status" />
                    </div>
                </template>

                <div class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2 xl:grid-cols-4">
                    <ReportSummaryCard label="Valor" :value="formatCurrency(accountReceivable.amount_cents)" tone="neutral" />
                    <ReportSummaryCard label="Vencimento" :value="formatDate(accountReceivable.due_date)" tone="blue" />
                    <ReportSummaryCard
                        label="Recebimento"
                        :value="accountReceivable.received_at ? formatDate(accountReceivable.received_at) : '-'"
                        :tone="accountReceivable.received_at ? 'green' : 'yellow'"
                    />
                    <ReportSummaryCard label="Conta bancária" :value="accountReceivable.bank_account?.name ?? '-'" tone="neutral" />
                </div>

                <div class="grid grid-cols-1 gap-4 border-t border-gray-700 p-6 md:grid-cols-2">
                    <div>
                        <p class="text-xs text-gray-500 uppercase">Conta de receita</p>
                        <p class="mt-1 text-sm text-gray-200">
                            {{ formatAccount(accountReceivable.revenue_account?.code, accountReceivable.revenue_account?.name) }}
                        </p>
                    </div>
                    <div v-if="accountReceivable.notes">
                        <p class="text-xs text-gray-500 uppercase">Observações</p>
                        <p class="mt-1 text-sm text-gray-200">{{ accountReceivable.notes }}</p>
                    </div>
                </div>
            </ReportSection>

            <ReportSection v-if="accountReceivable.status === 'cancelled'">
                <div class="p-6">
                    <p class="text-sm font-semibold text-white">Cancelamento</p>
                    <p class="mt-2 text-sm text-gray-200">{{ accountReceivable.cancellation_reason }}</p>
                    <p class="mt-1 text-sm text-gray-400">
                        {{ formatDate(accountReceivable.cancelled_at)
                        }}<span v-if="accountReceivable.cancelled_by"> · {{ accountReceivable.cancelled_by.name }}</span>
                    </p>
                    <p v-if="accountReceivable.cancellation_journal_entry" class="mt-3 text-sm text-amber-200">
                        Estorno contábil em {{ formatDate(accountReceivable.cancellation_journal_entry.entry_date) }} ·
                        <Link
                            :href="route('journal-entries.show', [accountReceivable.cancellation_journal_entry.id])"
                            class="font-semibold underline hover:text-amber-100"
                            >Ver lançamento #{{ accountReceivable.cancellation_journal_entry.id }}</Link
                        >
                    </p>
                </div>
            </ReportSection>

            <ReportSection v-if="accountReceivable.status === 'pending'">
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Baixar recebimento</h2>
                        <p class="text-sm text-gray-400">
                            Ao baixar, o sistema gera um lançamento contábil postado: débito no banco e crédito na receita.
                        </p>
                    </div>
                </template>

                <form class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2" @submit.prevent="submitReceipt">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Conta bancária</label>
                        <select v-model="receipt.form.bank_account_id" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="">Selecione uma conta</option>
                            <option v-for="account in bankAccounts" :key="account.id" :value="account.id">{{ account.label }}</option>
                        </select>
                        <p class="mt-1 text-sm text-red-400">{{ receipt.form.errors.bank_account_id }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Data de recebimento</label>
                        <input
                            v-model="receipt.form.received_at"
                            type="date"
                            class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]"
                        />
                        <p class="mt-1 text-sm text-red-400">{{ receipt.form.errors.received_at }}</p>
                    </div>

                    <div class="flex justify-end md:col-span-2">
                        <button
                            type="submit"
                            :disabled="!receipt.canSubmit.value || receipt.form.processing"
                            class="rounded-lg bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-600 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Confirmar recebimento
                        </button>
                    </div>
                </form>
            </ReportSection>

            <ReportSection v-if="accountReceivable.settlement_reversals?.length">
                <template #header><h2 class="text-lg font-bold text-white">Histórico de reversões de liquidação</h2></template>
                <div class="divide-y divide-gray-800">
                    <div v-for="item in accountReceivable.settlement_reversals" :key="item.id" class="space-y-1 p-6 text-sm text-gray-300">
                        <p class="font-semibold text-white">
                            {{
                                item.mode === 'draft_void'
                                    ? 'Rascunho desfeito'
                                    : item.mode === 'posted_reversal'
                                      ? 'Estorno contabilizado'
                                      : item.mode === 'bank_draft_unlink'
                                        ? 'Classificação bancária desfeita'
                                        : 'Reclassificação bancária contabilizada'
                            }}
                        </p>
                        <p>
                            Liquidação original #{{ item.settlement_journal_entry_id_snapshot }} · {{ formatDate(item.settlement_entry_date) }} ·
                            {{ formatCurrency(item.settlement_amount_cents) }}
                        </p>
                        <p>Conta bancária: {{ item.bank_account?.name ?? 'indisponível' }}</p>
                        <p>Motivo: {{ item.reason }}</p>
                        <p v-if="item.bank_statement_import_transaction">Movimento importado #{{ item.bank_statement_import_transaction.id }}</p>
                        <p>
                            {{ formatDate(item.reversed_at) }}<span v-if="item.reversed_by"> · {{ item.reversed_by.name }}</span>
                        </p>
                        <p v-if="item.reversal_journal_entry">
                            Reversão em {{ formatDate(item.reversal_date) }} ·
                            <Link
                                :href="route('journal-entries.show', [item.reversal_journal_entry.id])"
                                class="font-semibold text-amber-200 underline"
                                >Ver lançamento #{{ item.reversal_journal_entry.id }}</Link
                            >
                        </p>
                        <p v-if="item.classification_adjustment_journal_entry">
                            Ajuste em {{ formatDate(item.reversal_date) }} ·
                            <Link
                                :href="route('journal-entries.show', [item.classification_adjustment_journal_entry.id])"
                                class="font-semibold text-amber-200 underline"
                                >Ver ajuste #{{ item.classification_adjustment_journal_entry.id }}</Link
                            >
                        </p>
                    </div>
                </div>
            </ReportSection>

            <ReportSection v-if="accountReceivable.receipt_journal_entry">
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Lançamento contábil do recebimento</h2>
                        <p class="text-sm text-gray-400">Registro gerado automaticamente na baixa do título.</p>
                    </div>
                </template>

                <ReportTable
                    :empty="!accountReceivable.receipt_journal_entry?.lines?.length"
                    empty-message="Nenhuma linha contábil encontrada."
                    :empty-colspan="3"
                >
                    <template #head>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Tipo</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Conta</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-gray-400 uppercase">Valor</th>
                        </tr>
                    </template>
                    <tr v-for="line in accountReceivable.receipt_journal_entry.lines" :key="line.id" class="hover:bg-gray-800/50">
                        <td class="px-4 py-3 text-sm font-semibold whitespace-nowrap text-gray-200">
                            {{ line.type === 'debit' ? 'Débito' : 'Crédito' }}
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-300">{{ formatAccount(line.chart_of_account?.code, line.chart_of_account?.name) }}</td>
                        <td class="px-4 py-3 text-right text-sm font-semibold whitespace-nowrap text-gray-100">
                            {{ formatCurrency(line.amount_cents) }}
                        </td>
                    </tr>
                </ReportTable>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
