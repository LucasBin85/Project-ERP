<script setup lang="ts">
import FinancialTitleCancellationDialog from '@/components/financial/FinancialTitleCancellationDialog.vue';
import FinancialTitleSettlementReversalDialog from '@/components/financial/FinancialTitleSettlementReversalDialog.vue';
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue';
import ReportTable from '@/components/reports/ReportTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useAccountPayablePayment } from '@/composables/financial/useAccountPayablePayment';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatAccount, formatCurrency, formatDate } from '@/lib/formatters';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps<{
    wallet: Record<string, any>;
    accountPayable: Record<string, any>;
    bankAccounts: Array<Record<string, any>>;
}>();

const payment = useAccountPayablePayment();

function submitPayment() {
    if (!payment.canSubmit.value) {
        return;
    }

    payment.form.post(route('accounts-payable.pay', [props.accountPayable.id]));
}
</script>

<template>
    <AppLayout title="Conta a Pagar">
        <ReportPage title="Conta a Pagar" :subtitle="wallet.name">
            <div class="flex justify-end gap-3">
                <Link
                    :href="route('accounts-payable.index')"
                    class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                >
                    Voltar
                </Link>

                <Link
                    v-if="accountPayable.payment_journal_entry_id"
                    :href="route('journal-entries.show', [accountPayable.payment_journal_entry_id])"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500"
                >
                    Ver lançamento contábil
                </Link>
            </div>

            <FinancialTitleCancellationDialog
                v-if="accountPayable.status === 'pending' && !accountPayable.payment_journal_entry_id"
                route-name="accounts-payable.cancel"
                :title-id="accountPayable.id"
                :requires-reversal="(accountPayable.series?.provision_journal_entry ?? accountPayable.provision_journal_entry)?.status === 'posted'"
            />
            <FinancialTitleSettlementReversalDialog
                v-if="
                    accountPayable.status === 'paid' &&
                    accountPayable.payment_journal_entry?.source === 'manual' &&
                    !accountPayable.payment_journal_entry.bank_statement_import_transaction
                "
                route-name="accounts-payable.reverse-settlement"
                :title-id="accountPayable.id"
                :settlement-status="accountPayable.payment_journal_entry.status"
                action-label="Reverter pagamento"
            />

            <ReportSection>
                <template #header>
                    <div class="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h2 class="text-lg font-bold text-white">
                                {{ accountPayable.description }}
                            </h2>

                            <p class="text-sm text-gray-400">
                                {{ accountPayable.payee_name }}
                            </p>
                        </div>

                        <StatusBadge :status="accountPayable.status" />
                    </div>
                </template>

                <div class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2 xl:grid-cols-4">
                    <ReportSummaryCard label="Valor" :value="formatCurrency(accountPayable.amount_cents)" tone="neutral" />

                    <ReportSummaryCard label="Vencimento" :value="formatDate(accountPayable.due_date)" tone="blue" />

                    <ReportSummaryCard
                        label="Pagamento"
                        :value="accountPayable.paid_at ? formatDate(accountPayable.paid_at) : '-'"
                        :tone="accountPayable.paid_at ? 'green' : 'yellow'"
                    />

                    <ReportSummaryCard label="Conta bancária" :value="accountPayable.bank_account?.name ?? '-'" tone="neutral" />
                </div>

                <div class="grid grid-cols-1 gap-4 border-t border-gray-700 p-6 md:grid-cols-2">
                    <div>
                        <p class="text-xs text-gray-500 uppercase">Conta de despesa</p>
                        <p class="mt-1 text-sm text-gray-200">
                            {{ formatAccount(accountPayable.expense_account?.code, accountPayable.expense_account?.name) }}
                        </p>
                    </div>

                    <div v-if="accountPayable.notes">
                        <p class="text-xs text-gray-500 uppercase">Observações</p>
                        <p class="mt-1 text-sm text-gray-200">
                            {{ accountPayable.notes }}
                        </p>
                    </div>
                </div>
            </ReportSection>

            <ReportSection v-if="accountPayable.status === 'cancelled'">
                <div class="p-6">
                    <p class="text-sm font-semibold text-white">Cancelamento</p>
                    <p class="mt-2 text-sm text-gray-200">{{ accountPayable.cancellation_reason }}</p>
                    <p class="mt-1 text-sm text-gray-400">
                        {{ formatDate(accountPayable.cancelled_at)
                        }}<span v-if="accountPayable.cancelled_by"> · {{ accountPayable.cancelled_by.name }}</span>
                    </p>
                    <p v-if="accountPayable.cancellation_journal_entry" class="mt-3 text-sm text-amber-200">
                        Estorno contábil em {{ formatDate(accountPayable.cancellation_journal_entry.entry_date) }} ·
                        <Link
                            :href="route('journal-entries.show', [accountPayable.cancellation_journal_entry.id])"
                            class="font-semibold underline hover:text-amber-100"
                            >Ver lançamento #{{ accountPayable.cancellation_journal_entry.id }}</Link
                        >
                    </p>
                </div>
            </ReportSection>

            <ReportSection v-if="accountPayable.status === 'pending'">
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Baixar pagamento</h2>

                        <p class="text-sm text-gray-400">
                            Ao baixar, o sistema gera um lançamento contábil postado: débito na despesa e crédito no banco.
                        </p>
                    </div>
                </template>

                <form class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2" @submit.prevent="submitPayment">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Conta bancária</label>
                        <select v-model="payment.form.bank_account_id" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="">Selecione uma conta</option>
                            <option v-for="account in bankAccounts" :key="account.id" :value="account.id">
                                {{ account.label }}
                            </option>
                        </select>
                        <p class="mt-1 text-sm text-red-400">{{ payment.form.errors.bank_account_id }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Data de pagamento</label>
                        <input
                            v-model="payment.form.paid_at"
                            type="date"
                            class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]"
                        />
                        <p class="mt-1 text-sm text-red-400">{{ payment.form.errors.paid_at }}</p>
                    </div>

                    <div class="flex justify-end md:col-span-2">
                        <button
                            type="submit"
                            :disabled="!payment.canSubmit.value || payment.form.processing"
                            class="rounded-lg bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-600 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Confirmar pagamento
                        </button>
                    </div>
                </form>
            </ReportSection>

            <ReportSection v-if="accountPayable.settlement_reversals?.length">
                <template #header><h2 class="text-lg font-bold text-white">Histórico de reversões de liquidação</h2></template>
                <div class="divide-y divide-gray-800">
                    <div v-for="item in accountPayable.settlement_reversals" :key="item.id" class="space-y-1 p-6 text-sm text-gray-300">
                        <p class="font-semibold text-white">{{ item.mode === 'draft_void' ? 'Rascunho desfeito' : 'Estorno contabilizado' }}</p>
                        <p>
                            Liquidação original #{{ item.settlement_journal_entry_id_snapshot }} · {{ formatDate(item.settlement_entry_date) }} ·
                            {{ formatCurrency(item.settlement_amount_cents) }}
                        </p>
                        <p>Conta bancária: {{ item.bank_account?.name ?? 'indisponível' }}</p>
                        <p>Motivo: {{ item.reason }}</p>
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
                    </div>
                </div>
            </ReportSection>

            <ReportSection v-if="accountPayable.payment_journal_entry">
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Lançamento contábil do pagamento</h2>

                        <p class="text-sm text-gray-400">Registro gerado automaticamente na baixa do título.</p>
                    </div>
                </template>

                <ReportTable
                    :empty="!accountPayable.payment_journal_entry?.lines?.length"
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

                    <tr v-for="line in accountPayable.payment_journal_entry.lines" :key="line.id" class="hover:bg-gray-800/50">
                        <td class="px-4 py-3 text-sm font-semibold whitespace-nowrap text-gray-200">
                            {{ line.type === 'debit' ? 'Débito' : 'Crédito' }}
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-300">
                            {{ formatAccount(line.chart_of_account?.code, line.chart_of_account?.name) }}
                        </td>

                        <td class="px-4 py-3 text-right text-sm font-semibold whitespace-nowrap text-gray-100">
                            {{ formatCurrency(line.amount_cents) }}
                        </td>
                    </tr>
                </ReportTable>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
