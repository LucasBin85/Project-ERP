<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue';
import ReportTable from '@/components/reports/ReportTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatAccount, formatCurrency } from '@/lib/formatters';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { computed } from 'vue';

const props = defineProps<{ creditCard: Record<string, any>; plan: Record<string, any> }>();
const billed = computed(() => props.plan.items.filter((item: any) => item.status === 'matched').reduce((sum: number, item: any) => sum + Number(item.amount_cents), 0));
const future = computed(() => props.plan.items.filter((item: any) => ['expected', 'adjusted'].includes(item.status)).reduce((sum: number, item: any) => sum + Number(item.amount_cents), 0));
const reference = (item: any) => item.invoice ? `${String(item.invoice.reference_month).padStart(2, '0')}/${item.invoice.reference_year}` : '—';
const status = (value: string) => ({ matched: 'Conciliada', previous_before_erp: 'Anterior ao ERP', expected: 'Prevista', adjusted: 'Prevista', possible_match: 'Possível vínculo', divergent: 'Divergente' }[value] ?? value);
</script>

<template>
    <AppLayout title="Detalhe do parcelamento">
        <ReportPage :title="plan.description_base" subtitle="Detalhe do parcelamento">
            <div class="flex justify-end"><Link :href="route('credit-cards.show', creditCard.id)" class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300">Voltar ao cartão</Link></div>
            <ReportSection>
                <div class="grid gap-4 p-6 md:grid-cols-3 xl:grid-cols-6">
                    <ReportSummaryCard label="Valor reconhecido" :value="formatCurrency(plan.recognized_total_cents)" tone="neutral" />
                    <ReportSummaryCard label="Classificação" :value="plan.classification_account ? formatAccount(plan.classification_account.code, plan.classification_account.name) : 'A classificar'" tone="yellow" />
                    <ReportSummaryCard label="Parcelas" :value="String(plan.total_installments)" tone="blue" />
                    <ReportSummaryCard label="Já faturado" :value="formatCurrency(billed)" tone="green" />
                    <ReportSummaryCard label="Valor futuro" :value="formatCurrency(future)" tone="yellow" />
                    <ReportSummaryCard label="Status" :value="plan.recognition_journal_entry?.status ?? plan.status" tone="neutral" />
                </div>
                <div v-if="plan.recognition_journal_entry_id" class="px-6 pb-5"><Link :href="route('journal-entries.show', plan.recognition_journal_entry_id)" class="font-semibold text-indigo-300">JE-{{ String(plan.recognition_journal_entry_id).padStart(6, '0') }}</Link></div>
            </ReportSection>
            <ReportSection>
                <template #header><h2 class="text-lg font-bold text-white">Parcelas</h2></template>
                <ReportTable :empty="!plan.items.length" empty-message="Nenhuma parcela." :empty-colspan="4">
                    <template #head><tr><th class="px-4 py-3 text-left">Parcela</th><th class="px-4 py-3 text-left">Fatura</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-right">Valor</th></tr></template>
                    <tr v-for="item in plan.items" :key="item.id" class="hover:bg-gray-800/50"><td class="px-4 py-3">{{ item.installment_number }}/{{ plan.total_installments }}</td><td class="px-4 py-3"><Link v-if="item.invoice" :href="route('credit-cards.invoices.show', [creditCard.id, item.invoice.id])" class="text-indigo-300">{{ reference(item) }}</Link><span v-else>—</span></td><td class="px-4 py-3">{{ status(item.status) }}</td><td class="px-4 py-3 text-right">{{ formatCurrency(item.amount_cents) }}</td></tr>
                </ReportTable>
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
