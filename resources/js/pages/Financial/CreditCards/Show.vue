<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue';
import ReportTable from '@/components/reports/ReportTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useCreditCardPaymentForm } from '@/composables/financial/useCreditCardPaymentForm';
import { useCreditCardTransactionForm } from '@/composables/financial/useCreditCardTransactionForm';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatAccount, formatCurrency, formatDate } from '@/lib/formatters';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps<{
    wallet: Record<string, any>;
    creditCard: Record<string, any>;
    summary: Record<string, number>;
    transactions: Array<Record<string, any>>;
    payments: Array<Record<string, any>>;
    expenseAccounts: Array<Record<string, any>>;
    bankAccounts: Array<Record<string, any>>;
}>();

const transaction = useCreditCardTransactionForm();
const payment = useCreditCardPaymentForm();

const cardTypes: Record<string, string> = {
    main: 'Principal',
    additional: 'Adicional',
    virtual: 'Virtual',
};

function submitTransaction() {
    if (!transaction.canSubmit.value) return;
    transaction.form.post(route('credit-cards.transactions.store', [props.creditCard.id]));
}

function submitPayment() {
    if (!payment.canSubmit.value) return;
    payment.form.post(route('credit-cards.payments.store', [props.creditCard.id]));
}
</script>

<template>
    <AppLayout title="Cartão de Crédito">
        <ReportPage title="Cartão de Crédito" :subtitle="wallet.name">
            <div class="flex justify-end gap-3">
                <Link :href="route('credit-cards.index')" class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800">Voltar</Link>
            </div>

            <ReportSection>
                <template #header>
                    <div class="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h2 class="text-lg font-bold text-white">{{ creditCard.name }}</h2>
                            <p class="text-sm text-gray-400">
                                {{ creditCard.issuer_name }} · {{ creditCard.network }} · {{ cardTypes[creditCard.card_type] ?? creditCard.card_type }}
                                <span v-if="creditCard.last_four"> · •••• {{ creditCard.last_four }}</span>
                            </p>
                        </div>

                        <StatusBadge :status="creditCard.is_active ? 'active' : 'cancelled'" />
                    </div>
                </template>

                <div class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2 xl:grid-cols-5">
                    <ReportSummaryCard label="Limite" :value="formatCurrency(creditCard.credit_limit_cents)" tone="neutral" />
                    <ReportSummaryCard label="Utilizado" :value="formatCurrency(summary.current_balance_cents)" tone="yellow" />
                    <ReportSummaryCard label="Disponível" :value="formatCurrency(summary.available_limit_cents)" :tone="summary.available_limit_cents >= 0 ? 'green' : 'red'" />
                    <ReportSummaryCard label="Fechamento" :value="`Dia ${creditCard.closing_day}`" tone="blue" />
                    <ReportSummaryCard label="Vencimento" :value="`Dia ${creditCard.due_day}`" tone="blue" />
                </div>

                <div class="grid grid-cols-1 gap-4 border-t border-gray-700 p-6 md:grid-cols-3">
                    <div>
                        <p class="text-xs uppercase text-gray-500">Melhor data de compra</p>
                        <p class="mt-1 text-sm font-semibold text-green-300">Dia {{ creditCard.best_purchase_day }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase text-gray-500">Conta contábil do cartão</p>
                        <p class="mt-1 text-sm text-gray-200">{{ formatAccount(creditCard.liability_account?.code, creditCard.liability_account?.name) }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase text-gray-500">Cartão principal</p>
                        <p class="mt-1 text-sm text-gray-200">{{ creditCard.parent_card?.name ?? '-' }}</p>
                    </div>
                </div>
            </ReportSection>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Registrar compra</h2>
                        <p class="text-sm text-gray-400">Gera lançamento contábil: débito na despesa e crédito no cartão.</p>
                    </div>
                </template>

                <form class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2 xl:grid-cols-3" @submit.prevent="submitTransaction">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Data da compra</label>
                        <input v-model="transaction.form.purchase_date" type="date" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]" />
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Estabelecimento</label>
                        <input v-model="transaction.form.merchant_name" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="Ex: Mercado" />
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Valor</label>
                        <input :value="transaction.form.amount" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="R$ 0,00" inputmode="numeric" @input="transaction.updateAmount" />
                    </div>

                    <div class="md:col-span-2">
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Descrição</label>
                        <input v-model="transaction.form.description" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="Ex: Compra de materiais" />
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Conta de despesa</label>
                        <select v-model="transaction.form.expense_account_id" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="">Selecione uma despesa</option>
                            <option v-for="account in expenseAccounts" :key="account.id" :value="account.id">{{ account.label }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Parcelas</label>
                        <input v-model="transaction.form.installments_total" type="number" min="1" max="60" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" />
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Parcela atual</label>
                        <input v-model="transaction.form.installment_number" type="number" min="1" :max="transaction.form.installments_total" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" />
                    </div>

                    <div class="md:col-span-2 xl:col-span-3 flex justify-end">
                        <button type="submit" :disabled="!transaction.canSubmit.value || transaction.form.processing" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50">
                            Registrar compra
                        </button>
                    </div>
                </form>
            </ReportSection>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Pagar fatura</h2>
                        <p class="text-sm text-gray-400">Gera lançamento contábil: débito no cartão e crédito no banco.</p>
                    </div>
                </template>

                <form class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2 xl:grid-cols-4" @submit.prevent="submitPayment">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Conta bancária</label>
                        <select v-model="payment.form.bank_account_id" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="">Selecione uma conta</option>
                            <option v-for="account in bankAccounts" :key="account.id" :value="account.id">{{ account.label }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Data do pagamento</label>
                        <input v-model="payment.form.payment_date" type="date" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]" />
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Valor pago</label>
                        <input :value="payment.form.amount" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="R$ 0,00" inputmode="numeric" @input="payment.updateAmount" />
                    </div>

                    <div class="flex items-end justify-end">
                        <button type="submit" :disabled="!payment.canSubmit.value || payment.form.processing" class="rounded-lg bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-600 disabled:cursor-not-allowed disabled:opacity-50">
                            Pagar fatura
                        </button>
                    </div>
                </form>
            </ReportSection>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Compras registradas</h2>
                        <p class="text-sm text-gray-400">Últimas compras lançadas neste cartão.</p>
                    </div>
                </template>

                <ReportTable :empty="transactions.length === 0" empty-message="Nenhuma compra registrada." :empty-colspan="7">
                    <template #head>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Data</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Estabelecimento</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Descrição</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Despesa</th>
                            <th class="px-4 py-3 text-center text-xs font-bold uppercase text-gray-400">Parcela</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Valor</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Lançamento</th>
                        </tr>
                    </template>

                    <tr v-for="item in transactions" :key="item.id" class="hover:bg-gray-800/50">
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">{{ formatDate(item.purchase_date) }}</td>
                        <td class="px-4 py-3 text-sm font-semibold text-white">{{ item.merchant_name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-300">{{ item.description }}</td>
                        <td class="px-4 py-3 text-sm text-gray-400">{{ formatAccount(item.expense_account?.code, item.expense_account?.name) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-center text-sm text-gray-300">{{ item.installment_number }}/{{ item.installments_total }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-red-300">{{ formatCurrency(item.amount_cents) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                            <Link v-if="item.journal_entry_id" :href="route('journal-entries.show', [item.journal_entry_id])" class="inline-flex items-center rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-medium text-gray-200 transition hover:bg-gray-700">
                                JE-{{ String(item.journal_entry_id).padStart(6, '0') }}
                            </Link>
                        </td>
                    </tr>
                </ReportTable>
            </ReportSection>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Pagamentos de fatura</h2>
                        <p class="text-sm text-gray-400">Pagamentos registrados contra contas bancárias.</p>
                    </div>
                </template>

                <ReportTable :empty="payments.length === 0" empty-message="Nenhum pagamento registrado." :empty-colspan="5">
                    <template #head>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Data</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Descrição</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Banco</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Valor</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Lançamento</th>
                        </tr>
                    </template>

                    <tr v-for="item in payments" :key="item.id" class="hover:bg-gray-800/50">
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">{{ formatDate(item.payment_date) }}</td>
                        <td class="px-4 py-3 text-sm text-gray-300">{{ item.description }}</td>
                        <td class="px-4 py-3 text-sm text-gray-300">{{ item.bank_account?.name ?? '-' }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-green-300">{{ formatCurrency(item.amount_cents) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                            <Link v-if="item.journal_entry_id" :href="route('journal-entries.show', [item.journal_entry_id])" class="inline-flex items-center rounded-lg border border-gray-600 px-3 py-1.5 text-sm font-medium text-gray-200 transition hover:bg-gray-700">
                                JE-{{ String(item.journal_entry_id).padStart(6, '0') }}
                            </Link>
                        </td>
                    </tr>
                </ReportTable>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
