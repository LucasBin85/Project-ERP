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
import CreditCardStatementImport from '@/components/financial/creditCards/CreditCardStatementImport.vue';
import InlineCreditCardClassification from '@/components/financial/creditCards/InlineCreditCardClassification.vue';

const props = defineProps<{
    wallet: Record<string, any>;
    creditCard: Record<string, any>;
    familyCards: Array<Record<string, any>>;
    summaryByCard: Array<Record<string, any>>;
    summary: Record<string, number>;
    invoices: Array<Record<string, any>>;
    transactions: Array<Record<string, any>>;
    payments: Array<Record<string, any>>;
    expenseAccounts: Array<Record<string, any>>;
    bankAccounts: Array<Record<string, any>>;
    creditCardStatementPreview?: Record<string, any> | null;
}>();

const transaction = useCreditCardTransactionForm(props.creditCard.id);
const payment = useCreditCardPaymentForm(props.invoices?.[0]?.id ?? null);

const cardTypes: Record<string, string> = {
    main: 'Principal',
    additional: 'Adicional',
    virtual: 'Virtual',
};

function invoiceLabel(invoice: Record<string, any>): string {
    return `${String(invoice.reference_month).padStart(2, '0')}/${invoice.reference_year}`;
}

function submitTransaction() {
    if (!transaction.canSubmit.value) return;
    transaction.form.installment_number = 1;
    transaction.form.post(route('credit-cards.transactions.store', [props.creditCard.id]));
}

function submitPayment() {
    if (!payment.canSubmit.value) return;
    payment.form.post(route('credit-cards.payments.store', [props.creditCard.id]));
}
</script>

<template>
    <AppLayout title="Fatura do Cartão">
        <ReportPage title="Fatura do Cartão" :subtitle="wallet.name">
            <div class="flex justify-end gap-3">
                <Link :href="route('credit-cards.index')" class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800">Voltar</Link>
                <Link :href="route('credit-cards.create')" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Adicionar virtual/adicional</Link>
            </div>

            <CreditCardStatementImport :credit-card-id="creditCard.id" :preview="creditCardStatementPreview" />

            <ReportSection>
                <template #header>
                    <div class="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h2 class="text-lg font-bold text-white">{{ creditCard.name }}</h2>
                            <p class="text-sm text-gray-400">
                                {{ creditCard.issuer_name }} · {{ creditCard.network }} · Fatura única
                                <span v-if="creditCard.last_four"> · •••• {{ creditCard.last_four }}</span>
                            </p>
                        </div>

                        <StatusBadge :status="creditCard.is_active ? 'active' : 'cancelled'" />
                    </div>
                </template>

                <div class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2 xl:grid-cols-5">
                    <ReportSummaryCard label="Limite compartilhado" :value="formatCurrency(creditCard.credit_limit_cents)" tone="neutral" />
                    <ReportSummaryCard label="Saldo em aberto" :value="formatCurrency(summary.current_balance_cents)" tone="yellow" />
                    <ReportSummaryCard label="Limite disponível" :value="formatCurrency(summary.available_limit_cents)" :tone="summary.available_limit_cents >= 0 ? 'green' : 'red'" />
                    <ReportSummaryCard label="Fechamento" :value="`Dia ${creditCard.closing_day}`" tone="blue" />
                    <ReportSummaryCard label="Vencimento" :value="`Dia ${creditCard.due_day}`" tone="blue" />
                </div>

                <div class="grid grid-cols-1 gap-4 border-t border-gray-700 p-6 md:grid-cols-4">
                    <div>
                        <p class="text-xs uppercase text-gray-500">Melhor data de compra</p>
                        <p class="mt-1 text-sm font-semibold text-green-300">Dia {{ creditCard.best_purchase_day }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase text-gray-500">Conta bancária vinculada</p>
                        <p class="mt-1 text-sm text-gray-200">{{ creditCard.bank_account?.name ?? '-' }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase text-gray-500">Conta contábil da fatura</p>
                        <p class="mt-1 text-sm text-gray-200">{{ formatAccount(creditCard.liability_account?.code, creditCard.liability_account?.name) }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase text-gray-500">Cartões vinculados</p>
                        <p class="mt-1 text-sm text-gray-200">{{ familyCards.length }}</p>
                    </div>
                </div>
            </ReportSection>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Faturas mensais</h2>
                        <p class="text-sm text-gray-400">As compras entram automaticamente na fatura conforme a data de fechamento do cartão.</p>
                    </div>
                </template>

                <ReportTable :empty="invoices.length === 0" empty-message="Nenhuma fatura mensal gerada ainda." :empty-colspan="8">
                    <template #head>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Referência</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Período</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Vencimento</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Total</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Pago</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Saldo</th>
                            <th class="px-4 py-3 text-center text-xs font-bold uppercase text-gray-400">Itens</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Status</th>
                        </tr>
                    </template>

                    <tr v-for="invoice in invoices" :key="invoice.id" class="hover:bg-gray-800/50">
                        <td class="whitespace-nowrap px-4 py-3 text-sm font-semibold text-white">{{ invoiceLabel(invoice) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">{{ formatDate(invoice.starts_at) }} até {{ formatDate(invoice.closes_at) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">{{ formatDate(invoice.due_at) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-gray-100">{{ formatCurrency(invoice.total_cents) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-green-300">{{ formatCurrency(invoice.paid_cents) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-yellow-300">{{ formatCurrency(invoice.balance_cents) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-center text-sm text-gray-300">{{ invoice.transactions_count }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm"><StatusBadge :status="invoice.status" /></td>
                    </tr>
                </ReportTable>
            </ReportSection>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Cartões desta fatura</h2>
                        <p class="text-sm text-gray-400">Todos compartilham limite, fechamento, vencimento e conta passiva.</p>
                    </div>
                </template>

                <div class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2 xl:grid-cols-4">
                    <div v-for="card in summaryByCard" :key="card.id" class="rounded-xl border border-gray-700 bg-gray-900/40 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-white">{{ card.name }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ cardTypes[card.card_type] ?? card.card_type }} {{ card.last_four ? '· •••• ' + card.last_four : '' }}
                                </p>
                            </div>
                            <p class="text-sm font-semibold text-yellow-300">{{ formatCurrency(card.amount_cents) }}</p>
                        </div>
                    </div>
                </div>
            </ReportSection>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Registrar compra</h2>
                        <p class="text-sm text-gray-400">Informe o valor total da compra. Se houver parcelamento, cada parcela será lançada em sua fatura mensal.</p>
                    </div>
                </template>

                <form class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2 xl:grid-cols-3" @submit.prevent="submitTransaction">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Cartão usado</label>
                        <select v-model="transaction.form.credit_card_id" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option v-for="card in familyCards" :key="card.id" :value="card.id">
                                {{ cardTypes[card.card_type] ?? card.card_type }} · {{ card.name }} {{ card.last_four ? '•••• ' + card.last_four : '' }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Data da compra</label>
                        <input v-model="transaction.form.purchase_date" type="date" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]" />
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Estabelecimento</label>
                        <input v-model="transaction.form.merchant_name" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="Ex: Mercado" />
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Valor total da compra</label>
                        <input :value="transaction.form.amount" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="R$ 0,00" inputmode="numeric" @input="transaction.updateAmount" />
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Conta de classificação</label>
                        <select v-model="transaction.form.expense_account_id" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="">Selecione despesa, ativo ou investimento</option>
                            <option v-for="account in expenseAccounts" :key="account.id" :value="account.id">{{ account.label }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Número de parcelas</label>
                        <input v-model="transaction.form.installments_total" type="number" min="1" max="60" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" />
                        <p class="mt-1 text-xs text-gray-500">Ex: R$ 900 em 3x cria R$ 300 nas próximas 3 faturas.</p>
                    </div>

                    <div class="md:col-span-2 xl:col-span-3">
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Descrição</label>
                        <input v-model="transaction.form.description" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="Ex: Compra de materiais" />
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
                        <p class="text-sm text-gray-400">Selecione a fatura mensal que será baixada contra a conta bancária.</p>
                    </div>
                </template>

                <form class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2 xl:grid-cols-5" @submit.prevent="submitPayment">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-300">Fatura</label>
                        <select v-model="payment.form.credit_card_invoice_id" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white">
                            <option value="">Selecione uma fatura</option>
                            <option v-for="invoice in invoices" :key="invoice.id" :value="invoice.id">
                                {{ invoiceLabel(invoice) }} · saldo {{ formatCurrency(invoice.balance_cents) }}
                            </option>
                        </select>
                    </div>

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
                            Registrar pagamento
                        </button>
                    </div>
                </form>
            </ReportSection>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Lançamentos da fatura</h2>
                        <p class="text-sm text-gray-400">Compras à vista e parcelas do cartão principal, adicionais e virtuais.</p>
                    </div>
                </template>

                <ReportTable :empty="transactions.length === 0" empty-message="Nenhuma compra registrada." :empty-colspan="9">
                    <template #head>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Data</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Fatura</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Cartão</th>
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
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                            <span v-if="item.credit_card_invoice">
                                {{ String(item.credit_card_invoice.reference_month).padStart(2, '0') }}/{{ item.credit_card_invoice.reference_year }}
                            </span>
                            <span v-else>-</span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-300">
                            {{ item.credit_card?.name ?? '-' }}
                            <span v-if="item.credit_card?.last_four" class="text-xs text-gray-500">•••• {{ item.credit_card.last_four }}</span>
                        </td>
                        <td class="px-4 py-3 text-sm font-semibold text-white">{{ item.merchant_name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-300">{{ item.description }}</td>
                        <td class="px-4 py-3 text-sm text-gray-400">
                            <InlineCreditCardClassification v-if="item.expense_account_id === wallet.suspense_account_id" :credit-card-id="creditCard.id" :transaction-id="item.id" :accounts="expenseAccounts" />
                            <span v-else>{{ formatAccount(item.expense_account?.code, item.expense_account?.name) }}</span>
                        </td>
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
                        <h2 class="text-lg font-bold text-white">Pagamentos da fatura</h2>
                        <p class="text-sm text-gray-400">Pagamentos registrados contra contas bancárias.</p>
                    </div>
                </template>

                <ReportTable :empty="payments.length === 0" empty-message="Nenhum pagamento registrado." :empty-colspan="6">
                    <template #head>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Data</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Fatura</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Descrição</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-gray-400">Banco</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Valor</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase text-gray-400">Lançamento</th>
                        </tr>
                    </template>

                    <tr v-for="item in payments" :key="item.id" class="hover:bg-gray-800/50">
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">{{ formatDate(item.payment_date) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                            <span v-if="item.credit_card_invoice">
                                {{ String(item.credit_card_invoice.reference_month).padStart(2, '0') }}/{{ item.credit_card_invoice.reference_year }}
                            </span>
                            <span v-else>-</span>
                        </td>
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
