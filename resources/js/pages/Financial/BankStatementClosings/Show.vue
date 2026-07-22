<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { route } from 'ziggy-js';

const props=defineProps<{wallet:{id:number;name:string};summary:any;postingResult?:any;classificationResult?:any}>();
const page=usePage(); const processing=ref(false); const active=ref<string|null>(null);
const form=reactive({start_date:props.summary.period.start_date,end_date:props.summary.period.end_date});
const checklist=[['pending_classification','Pendentes de classificação'],['pending_links','Vínculos pendentes AP/AR'],['pending_transfers','Transferências aguardando contraparte'],['investments','Investimentos classificados'],['ready_for_accounting','Prontos para contabilidade'],['posted','Já postados'],['inconsistencies','Erros ou inconsistências']];
const visibleItems=computed(()=>!active.value?props.summary.problems:props.summary.items.filter((item:any)=>{
 if(active.value==='investments') return item.operation_type==='investment';
 if(active.value==='pending_transfers') return item.transfer_status==='pending_counterpart_ofx';
 if(active.value==='pending_links') return item.workflow_status==='pending_link';
 if(active.value==='pending_classification') return item.workflow_status==='pending_classification';
 if(active.value==='ready_for_accounting') return item.workflow_status==='ready_for_accounting';
 if(active.value==='posted') return item.workflow_status==='posted';
 return item.workflow_status==='classified';
}));
function refresh(){router.get(route('bank-accounts.closing.show',props.summary.bank_account.id),form,{preserveState:true});}
function post(name:string){processing.value=true;router.post(route(name,props.summary.bank_account.id),form,{preserveScroll:true,onFinish:()=>processing.value=false});}
const flash=computed(()=>(page.props.flash as any)?.success);
</script>
<template>
 <AppLayout title="Fechamento Bancário"><ReportPage title="Fechamento Bancário" :subtitle="`${summary.bank_account.name} · ${wallet.name}`">
  <div v-if="flash" class="rounded-xl border border-green-500/30 bg-green-950/30 p-3 text-sm text-green-300">{{ flash }}</div>
  <div class="flex flex-wrap items-end gap-3 rounded-xl border border-gray-700 bg-gray-950 p-4"><label class="text-sm text-gray-300">Data inicial<input v-model="form.start_date" type="date" class="mt-1 block rounded border border-gray-700 bg-black p-2"></label><label class="text-sm text-gray-300">Data final<input v-model="form.end_date" type="date" class="mt-1 block rounded border border-gray-700 bg-black p-2"></label><button class="rounded bg-gray-700 px-4 py-2 font-semibold" @click="refresh">Atualizar período</button><Link :href="route('bank-accounts.statement',{bankAccount:summary.bank_account.id,...form})" class="ml-auto rounded border border-gray-600 px-4 py-2">Voltar ao Extrato</Link></div>
  <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4" :class="summary.status==='closed'?'border-green-500/40 bg-green-950/20':'border-amber-500/30 bg-amber-950/20'"><div><p class="text-xs uppercase text-gray-400">Status do período</p><h2 class="text-xl font-bold text-white">{{ summary.status_label }}</h2><p v-if="summary.status==='closed'" class="text-sm text-green-300">Período conferido e contabilizado.</p><p v-else class="text-sm text-gray-400">Confira abaixo o que falta para fechar.</p></div><div class="flex gap-2"><button v-if="summary.counts.applicable_suggestions" :disabled="processing" class="rounded bg-indigo-600 px-4 py-2 font-semibold text-white" @click="post('bank-accounts.closing.apply-suggestions')">Aplicar sugestões ({{ summary.counts.applicable_suggestions }})</button><button v-if="summary.counts.ready_for_accounting" :disabled="processing" class="rounded bg-green-600 px-4 py-2 font-semibold text-white" @click="post('bank-accounts.closing.post-ready')">Postar prontos ({{ summary.counts.ready_for_accounting }})</button></div></div>
  <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"><ReportSummaryCard label="Saldo inicial operacional" :value="formatCurrency(summary.balances.opening_operational_cents)"/><ReportSummaryCard label="Entradas" :value="formatCurrency(summary.balances.inflows_cents)" tone="green"/><ReportSummaryCard label="Saídas" :value="formatCurrency(summary.balances.outflows_cents)" tone="red"/><ReportSummaryCard label="Saldo final operacional" :value="formatCurrency(summary.balances.closing_operational_cents)"/><ReportSummaryCard label="Saldo contábil postado" :value="formatCurrency(summary.balances.posted_accounting_cents)" tone="blue"/><ReportSummaryCard label="Diferença operacional x contábil" :value="formatCurrency(summary.balances.difference_cents)" :tone="summary.balances.difference_cents===0?'green':'yellow'"/></div>
  <ReportSection><template #header><div><h2 class="font-bold text-white">O que falta para fechar</h2><p class="text-sm text-gray-400">Selecione um contador para listar os lançamentos correspondentes.</p></div></template><div class="grid gap-2 p-4 sm:grid-cols-2 lg:grid-cols-4"><button v-for="entry in checklist" :key="entry[0]" class="rounded-lg border p-3 text-left" :class="active===entry[0]?'border-indigo-400 bg-indigo-950/30':'border-gray-700'" @click="active=active===entry[0]?null:entry[0]"><span class="block text-xs text-gray-400">{{ entry[1] }}</span><b class="text-2xl text-white">{{ summary.counts[entry[0]] }}</b></button></div></ReportSection>
  <ReportSection><template #header><h2 class="font-bold text-white">{{ active ? checklist.find(entry=>entry[0]===active)?.[1] : 'Pendências do período' }}</h2></template><div v-if="!visibleItems.length" class="p-6 text-center text-gray-400">Nenhum lançamento nesta categoria.</div><div v-else class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-gray-950 text-gray-400"><tr><th class="p-3 text-left">Data</th><th class="p-3 text-left">Descrição</th><th class="p-3 text-right">Valor</th><th class="p-3">Status</th><th></th></tr></thead><tbody class="divide-y divide-gray-800"><tr v-for="item in visibleItems" :key="item.journal_entry_id"><td class="p-3">{{ formatDate(item.date) }}</td><td class="p-3 text-white">{{ item.description }}</td><td class="p-3 text-right">{{ formatCurrency(item.amount_cents) }}</td><td class="p-3"><StatusBadge :status="item.status??item.workflow_status"/></td><td class="p-3"><Link :href="item.journal_entry_url" class="text-indigo-300">Abrir</Link></td></tr></tbody></table></div></ReportSection>
 </ReportPage></AppLayout>
</template>
