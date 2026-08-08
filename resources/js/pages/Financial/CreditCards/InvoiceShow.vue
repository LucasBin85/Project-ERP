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
import { computed } from 'vue';

const props = defineProps<{ creditCard: Record<string, any>; invoice: Record<string, any> }>();
const reference = computed(() => `${String(props.invoice.reference_month).padStart(2, '0')}/${props.invoice.reference_year}`);
const items = computed(() => {
    const transactions = props.invoice.transactions.map((item: any) => ({ ...item, row_type: item.installments_total > 1 ? `Parcela ${item.installment_number}/${item.installments_total}` : 'Compra' }));
    const transactionIds = new Set(transactions.map((item: any) => Number(item.id)));
    const predictions = props.invoice.installment_plan_items.filter((item: any) => !item.credit_card_purchase_id || !transactionIds.has(Number(item.credit_card_purchase_id))).map((item: any) => ({ id: `plan-${item.id}`, purchase_date: null, description: item.plan.description_base, row_type: `Parcela ${item.installment_number}/${item.plan.total_installments} · Prevista`, amount_cents: item.amount_cents, journal_entry_id: item.plan.recognition_journal_entry_id }));
    return [...transactions, ...predictions];
});
</script>
<template>
    <AppLayout :title="`Fatura ${reference}`"><ReportPage :title="`Fatura ${reference}`" :subtitle="creditCard.name">
        <div class="flex justify-end"><Link :href="route('credit-cards.show', creditCard.id)" class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300">Voltar ao cartão</Link></div>
        <ReportSection><div class="grid gap-4 p-6 md:grid-cols-3 xl:grid-cols-5"><ReportSummaryCard label="Período" :value="`${formatDate(invoice.starts_at)} a ${formatDate(invoice.closes_at)}`" tone="neutral"/><ReportSummaryCard label="Vencimento nominal" :value="formatDate(invoice.nominal_due_at)" tone="blue"/><ReportSummaryCard label="Vencimento efetivo" :value="formatDate(invoice.due_at)" tone="blue"/><ReportSummaryCard label="Total" :value="formatCurrency(invoice.total_cents)" tone="yellow"/><ReportSummaryCard label="Pago / saldo" :value="`${formatCurrency(invoice.paid_cents)} / ${formatCurrency(invoice.balance_cents)}`" tone="green"/></div><div class="flex items-center gap-3 px-6 pb-5"><StatusBadge :status="invoice.status"/><span class="text-sm text-gray-400">{{ items.length }} item(ns)</span></div></ReportSection>
        <ReportSection><template #header><h2 class="text-lg font-bold text-white">Lançamentos da fatura</h2></template><ReportTable :empty="!items.length" empty-message="Nenhum lançamento nesta fatura." :empty-colspan="5"><template #head><tr><th class="px-4 py-3 text-left">Data</th><th class="px-4 py-3 text-left">Descrição</th><th class="px-4 py-3 text-left">Tipo</th><th class="px-4 py-3 text-right">Valor</th><th class="px-4 py-3 text-right">Contabilidade</th></tr></template><tr v-for="item in items" :key="item.id" class="hover:bg-gray-800/50"><td class="px-4 py-3">{{ item.purchase_date ? formatDate(item.purchase_date) : '—' }}</td><td class="px-4 py-3 font-semibold text-white">{{ item.description }}</td><td class="px-4 py-3">{{ item.row_type }}</td><td class="px-4 py-3 text-right">{{ formatCurrency(item.amount_cents) }}</td><td class="px-4 py-3 text-right"><Link v-if="item.journal_entry_id" :href="route('journal-entries.show', item.journal_entry_id)" class="text-indigo-300">JE-{{ String(item.journal_entry_id).padStart(6, '0') }}</Link><span v-else>—</span></td></tr></ReportTable></ReportSection>
    </ReportPage></AppLayout>
</template>
