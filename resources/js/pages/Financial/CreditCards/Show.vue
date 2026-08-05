<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue';
import ReportTable from '@/components/reports/ReportTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useCreditCardTransactionForm } from '@/composables/financial/useCreditCardTransactionForm';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatAccount, formatCurrency, formatDate } from '@/lib/formatters';
import { Link, router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { computed, ref } from 'vue';
import CreditCardStatementImport from '@/components/financial/creditCards/CreditCardStatementImport.vue';
import InlineCreditCardClassification from '@/components/financial/creditCards/InlineCreditCardClassification.vue';

const props = defineProps<{
    wallet: Record<string, any>;
    creditCard: Record<string, any>;
    familyCards: Array<Record<string, any>>;
    summaryByCard: Array<Record<string, any>>;
    summary: Record<string, number>;
    purchaseClassificationSummary: Record<string, number>;
    invoices: Array<Record<string, any>>;
    transactions: Array<Record<string, any>>;
    payments: Array<Record<string, any>>;
    installmentPlans: Array<Record<string, any>>;
    expenseAccounts: Array<Record<string, any>>;
    creditCardStatementPreview?: Record<string, any> | null;
}>();

const transaction = useCreditCardTransactionForm(props.creditCard.id);
const classificationFilter = ref('all');
const bulkForm = useForm({ transaction_ids: [] as number[] });
const filteredTransactions = computed(() => props.transactions.filter((item) => {
    const pending = Number(item.expense_account_id) === Number(props.wallet.suspense_account_id);
    if (classificationFilter.value === 'unclassified') return pending && item.journal_entry?.status === 'draft';
    if (classificationFilter.value === 'classified') return !pending;
    if (classificationFilter.value === 'ready') return !pending && item.journal_entry?.status === 'draft';
    if (classificationFilter.value === 'posted') return item.journal_entry?.status === 'posted';
    return true;
}));
const highConfidenceIds = computed(() => props.transactions
    .filter((item) => item.classification_suggestion?.can_bulk_apply)
    .map((item) => Number(item.id)));

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

function applySuggestion(item: Record<string, any>) {
    router.post(route('credit-cards.transactions.apply-classification-suggestion', [props.creditCard.id, item.id]), {
        suggestion_key: item.classification_suggestion.suggestion_key,
    }, { preserveScroll: true });
}

function applyBulkSuggestions() {
    bulkForm.transaction_ids = highConfidenceIds.value;
    bulkForm.post(route('credit-cards.classification-suggestions.apply', props.creditCard.id), { preserveScroll: true });
}

</script>

<template>
    <AppLayout title="Fatura do Cartão">
        <ReportPage title="Fatura do Cartão" :subtitle="wallet.name">
            <div class="flex justify-end gap-3">
                <Link :href="route('credit-cards.index')" class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800">Voltar</Link>
                <Link :href="route('credit-cards.create')" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Adicionar virtual/adicional</Link>
            </div>

            <CreditCardStatementImport :credit-card-id="creditCard.id" :preview="creditCardStatementPreview" :expense-accounts="expenseAccounts" />

            <ReportSection>
                <template #header>
                    <div><h2 class="text-lg font-bold text-white">Parcelamentos do cartão</h2><p class="text-sm text-gray-400">Planos reconhecidos uma única vez na contabilidade e conciliados nas faturas.</p></div>
                </template>
                <div v-if="installmentPlans.length" class="grid gap-4 p-4 lg:grid-cols-2">
                    <article v-for="plan in installmentPlans" :key="plan.id" class="rounded-xl border border-gray-700 bg-gray-900/50 p-4">
                        <div class="flex justify-between gap-3"><div><h3 class="font-bold text-white">{{ plan.description_base }}</h3><span class="mt-1 inline-block rounded-full border border-indigo-500/40 px-2 py-0.5 text-xs font-semibold text-indigo-200">Status: {{ plan.status === 'active' ? 'Ativo' : plan.status === 'completed' ? 'Conciliado' : plan.status === 'ambiguous' ? 'Divergente' : 'Aguardando classificação' }}</span></div><Link v-if="plan.recognition_journal_entry_id" :href="route('journal-entries.show', plan.recognition_journal_entry_id)" class="text-xs font-semibold text-indigo-300">JE-{{ String(plan.recognition_journal_entry_id).padStart(6, '0') }}</Link></div>
                        <div class="mt-3 grid gap-1 text-xs text-gray-300 sm:grid-cols-2">
                            <p>Valor reconhecido: <b class="text-white">{{ formatCurrency(plan.recognized_total_cents) }}</b></p>
                            <p>Valor futuro: <b class="text-white">{{ formatCurrency(plan.items.filter((item) => ['expected', 'adjusted'].includes(item.status)).reduce((sum, item) => sum + Number(item.amount_cents), 0)) }}</b></p>
                            <p class="sm:col-span-2">Classificação: {{ plan.classification_account ? formatAccount(plan.classification_account.code, plan.classification_account.name) : 'Pendente' }}</p>
                            <p>{{ plan.items.filter((item) => item.status === 'matched').length }} conciliada(s) · {{ plan.items.filter((item) => ['expected', 'adjusted', 'possible_match', 'divergent'].includes(item.status)).length }} prevista(s)</p>
                            <p v-if="plan.started_before_erp">Começou antes do ERP</p>
                        </div>
                        <div class="mt-3 overflow-x-auto rounded border border-gray-700"><table class="w-full min-w-[680px] text-xs"><thead class="bg-gray-950 text-gray-400"><tr><th class="p-2 text-left">Parcela</th><th class="p-2 text-left">Status</th><th class="p-2 text-left">Fatura prevista</th><th class="p-2 text-left">Fatura vinculada</th><th class="p-2 text-right">Valor</th><th class="p-2 text-left">Observação</th></tr></thead><tbody class="divide-y divide-gray-800"><tr v-for="item in plan.items" :key="item.id"><td class="p-2">{{ item.installment_number }}/{{ plan.total_installments }}</td><td class="p-2">{{ item.status === 'previous_before_erp' ? 'Anterior ao ERP' : item.status === 'matched' ? 'Conciliada' : item.status === 'divergent' ? 'Divergente' : item.status === 'possible_match' ? 'Possível vínculo' : 'Prevista' }}</td><td class="p-2">{{ item.expected_invoice_month ? String(item.expected_invoice_month).padStart(2, '0') + '/' + item.expected_invoice_year : '—' }}</td><td class="p-2">{{ item.invoice ? String(item.invoice.reference_month).padStart(2, '0') + '/' + item.invoice.reference_year : '—' }}</td><td class="p-2 text-right">{{ formatCurrency(item.amount_cents) }}</td><td class="p-2">{{ item.status === 'previous_before_erp' ? 'Não gera contabilidade' : item.status === 'matched' ? 'Fatura conciliada' : item.status === 'divergent' ? 'Revisar valor importado' : item.status === 'possible_match' ? 'Aguardando confirmação do plano' : 'Aguardando fatura' }}</td></tr></tbody></table></div>
                    </article>
                </div>
                <p v-else class="p-4 text-sm text-gray-400">Nenhum parcelamento confirmado.</p>
            </ReportSection>

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

                <div class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2 xl:grid-cols-6">
                    <ReportSummaryCard label="Limite compartilhado" :value="formatCurrency(creditCard.credit_limit_cents)" tone="neutral" />
                    <ReportSummaryCard label="Saldo em aberto" :value="formatCurrency(summary.current_balance_cents)" tone="yellow" />
                    <ReportSummaryCard label="Parcelamentos futuros" :value="formatCurrency(summary.future_installments_cents)" tone="yellow" />
                    <ReportSummaryCard label="Limite disponível" :value="formatCurrency(summary.available_limit_cents)" :tone="summary.available_limit_cents >= 0 ? 'green' : 'red'" />
                    <ReportSummaryCard label="Fechamento" :value="creditCard.closing_day === 31 ? 'Último dia do mês' : `Dia ${creditCard.closing_day}`" tone="blue" />
                    <ReportSummaryCard label="Vencimento" :value="`Dia ${creditCard.due_day}`" tone="blue" />
                </div>

                <div class="grid grid-cols-1 gap-4 border-t border-gray-700 p-6 md:grid-cols-4">
                    <div>
                        <p class="text-xs uppercase text-gray-500">Melhor data de compra</p>
                        <p class="mt-1 text-sm font-semibold text-green-300">Dia {{ creditCard.best_purchase_day }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase text-gray-500">Instituição</p>
                        <p class="mt-1 text-sm text-gray-200">{{ creditCard.issuer_bank?.short_name ?? creditCard.issuer_name }}</p>
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
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                            {{ formatDate(invoice.due_at) }}
                            <span v-if="invoice.nominal_due_at && invoice.nominal_due_at !== invoice.due_at" class="block text-xs text-amber-300">
                                Nominal: {{ formatDate(invoice.nominal_due_at) }} · Vencimento ajustado para o próximo dia útil.
                            </span>
                        </td>
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
                        <h2 class="text-lg font-bold text-white">Lançamentos da fatura</h2>
                        <p class="text-sm text-gray-400">Compras à vista e parcelas do cartão principal, adicionais e virtuais.</p>
                    </div>
                </template>

                <div class="grid grid-cols-1 gap-3 border-b border-gray-700 p-4 sm:grid-cols-3">
                    <ReportSummaryCard label="Total exibido" :value="`${purchaseClassificationSummary.total_count} · ${formatCurrency(purchaseClassificationSummary.total_cents)}`" tone="neutral" />
                    <ReportSummaryCard label="Classificado" :value="`${purchaseClassificationSummary.classified_count} · ${formatCurrency(purchaseClassificationSummary.classified_cents)}`" tone="green" />
                    <ReportSummaryCard label="Pendente" :value="`${purchaseClassificationSummary.pending_count} · ${formatCurrency(purchaseClassificationSummary.pending_cents)}`" tone="yellow" />
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-700 p-4">
                    <select v-model="classificationFilter" class="rounded-lg border border-gray-700 bg-gray-950 px-3 py-2 text-sm text-white">
                        <option value="all">Todas</option>
                        <option value="unclassified">A classificar</option>
                        <option value="classified">Classificadas</option>
                        <option value="ready">Prontas para contabilizar</option>
                        <option value="posted">Contabilizadas</option>
                    </select>
                    <button v-if="highConfidenceIds.length" type="button" :disabled="bulkForm.processing" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50" @click="applyBulkSuggestions">
                        Aplicar sugestões seguras ({{ highConfidenceIds.length }})
                    </button>
                </div>
                <ReportTable :empty="filteredTransactions.length === 0" empty-message="Nenhuma compra para este filtro." :empty-colspan="9">
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

                    <tr v-for="item in filteredTransactions" :key="item.id" class="hover:bg-gray-800/50">
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
                            <div v-if="item.classification_suggestion?.status === 'suggested'" class="mb-2 rounded border border-indigo-500/30 bg-indigo-950/30 p-2 text-xs">
                                <p class="font-semibold text-indigo-200">{{ item.classification_suggestion.target_label }}</p>
                                <p class="text-gray-400">{{ item.classification_suggestion.history_count }} ocorrência(s) · confiança {{ item.classification_suggestion.confidence === 'high' ? 'alta' : 'média' }}</p>
                                <button type="button" class="mt-1 font-semibold text-indigo-300 hover:text-indigo-200" @click="applySuggestion(item)">Aplicar sugestão</button>
                            </div>
                            <p v-else-if="item.classification_suggestion?.status === 'ambiguous'" class="mb-2 text-xs text-amber-300">Histórico divergente; escolha manualmente.</p>
                            <span v-if="item.installment_plan_item?.status === 'matched'">Reconhecida no plano</span>
                            <InlineCreditCardClassification v-else-if="item.expense_account_id === wallet.suspense_account_id" :credit-card-id="creditCard.id" :transaction-id="item.id" :accounts="expenseAccounts" />
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
