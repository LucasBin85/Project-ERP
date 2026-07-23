<script setup lang="ts">
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import ReportSummaryCard from '@/components/reports/ReportSummaryCard.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCurrency } from '@/lib/formatters';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { route } from 'ziggy-js';

const props = defineProps<{ wallet: { id: number; name: string }; closing: any }>();
const page = usePage();
const processing = ref(false);
const showClose = ref(false);
const showReopen = ref(false);
const closeNote = ref('');
const reopenReason = ref('');
const filters = reactive({ year: props.closing.period.year, month: props.closing.period.month });
const months = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
const years = Array.from({ length: 11 }, (_, index) => new Date().getFullYear() - 5 + index);
const flash = computed(() => (page.props.flash as any)?.success);
const checklist = computed(() => [
    ['Contas bancárias com pendências', props.closing.banks.filter((bank: any) => ['incomplete', 'partially_posted'].includes(bank.status)).length],
    ['Rascunhos prontos para postar', props.closing.accounting.draft_ready],
    ['Rascunhos incompletos', props.closing.accounting.draft_incomplete],
    ['Lançamentos em A classificar', props.closing.accounting.unclassified],
    ['Lançamentos desbalanceados', props.closing.accounting.unbalanced],
]);

function refresh() {
    router.get(route('monthly-closing.show'), filters, { preserveState: true });
}

function postReady() {
    processing.value = true;
    router.post(route('monthly-closing.post-ready'), filters, { preserveScroll: true, onFinish: () => processing.value = false });
}

function closeMonth() {
    processing.value = true;
    router.post(route('monthly-closing.close'), { ...filters, close_note: closeNote.value }, { preserveScroll: true, onSuccess: () => showClose.value = false, onFinish: () => processing.value = false });
}

function reopenMonth() {
    processing.value = true;
    router.post(route('monthly-closing.reopen'), { ...filters, reopen_reason: reopenReason.value }, { preserveScroll: true, onSuccess: () => showReopen.value = false, onFinish: () => processing.value = false });
}
</script>

<template>
    <AppLayout title="Fechamento Mensal">
        <ReportPage title="Fechamento Mensal" :subtitle="`${closing.period.label} · ${wallet.name}`">
            <div v-if="flash" class="rounded-xl border border-green-500/30 bg-green-950/30 p-3 text-sm text-green-300">{{ flash }}</div>
            <div class="flex flex-wrap items-end gap-3 rounded-xl border border-gray-700 bg-gray-950 p-4">
                <label class="text-sm text-gray-300">Mês<select v-model="filters.month" class="mt-1 block rounded border border-gray-700 bg-black p-2"><option v-for="(name, index) in months" :key="name" :value="index + 1">{{ name }}</option></select></label>
                <label class="text-sm text-gray-300">Ano<select v-model="filters.year" class="mt-1 block rounded border border-gray-700 bg-black p-2"><option v-for="year in years" :key="year" :value="year">{{ year }}</option></select></label>
                <button class="rounded bg-indigo-600 px-4 py-2 font-semibold text-white" @click="refresh">Conferir mês</button>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-indigo-500/30 bg-indigo-950/20 p-4">
                <div><p class="text-xs uppercase text-gray-400">Status geral</p><h2 class="text-2xl font-bold text-white">{{ closing.status_label }}</h2><p class="text-sm text-gray-400">Painel de conferência; o período continua editável.</p></div>
                <StatusBadge :status="closing.status" />
            </div>

            <div class="rounded-xl border p-4" :class="closing.formal_closing.status === 'closed' ? 'border-red-500/40 bg-red-950/20' : 'border-gray-700 bg-gray-950'">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs uppercase text-gray-400">Status formal do mês</p><h2 class="text-xl font-bold text-white">{{ closing.formal_closing.status_label }}</h2><p v-if="closing.formal_closing.closed_at" class="text-sm text-gray-300">Fechado em {{ new Date(closing.formal_closing.closed_at).toLocaleString('pt-BR') }} por {{ closing.formal_closing.closed_by }}.</p><p v-if="closing.formal_closing.close_note" class="text-sm text-gray-400">Observação: {{ closing.formal_closing.close_note }}</p><p v-if="closing.formal_closing.reopened_at" class="text-sm text-amber-300">Reaberto em {{ new Date(closing.formal_closing.reopened_at).toLocaleString('pt-BR') }} por {{ closing.formal_closing.reopened_by }}: {{ closing.formal_closing.reopen_reason }}</p></div><button v-if="closing.formal_closing.status === 'closed'" class="rounded bg-amber-600 px-4 py-2 font-semibold text-white" @click="showReopen = true">Reabrir mês</button><button v-else :disabled="!closing.can_close" class="rounded bg-red-700 px-4 py-2 font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40" @click="showClose = true">Fechar mês</button></div>
                <div v-if="closing.formal_closing.status !== 'closed' && closing.closing_blockers.length" class="mt-3 rounded border border-amber-500/30 bg-amber-950/20 p-3"><p class="font-semibold text-amber-200">O mês ainda não pode ser fechado:</p><ul class="mt-1 list-disc pl-5 text-sm text-amber-100"><li v-for="reason in closing.closing_blockers" :key="reason">{{ reason }}</li></ul></div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <ReportSummaryCard label="Entradas bancárias" :value="formatCurrency(closing.summary.inflows_cents)" tone="green" />
                <ReportSummaryCard label="Saídas bancárias" :value="formatCurrency(closing.summary.outflows_cents)" tone="red" />
                <ReportSummaryCard label="Variação líquida de caixa" :value="formatCurrency(closing.summary.net_cash_change_cents)" />
                <ReportSummaryCard label="Saldo inicial operacional" :value="formatCurrency(closing.summary.opening_operational_cents)" />
                <ReportSummaryCard label="Saldo final operacional" :value="formatCurrency(closing.summary.closing_operational_cents)" />
                <ReportSummaryCard label="Saldo contábil postado" :value="formatCurrency(closing.summary.posted_accounting_cents)" tone="blue" />
                <ReportSummaryCard label="Diferença operacional x contábil" :value="formatCurrency(closing.summary.difference_cents)" :tone="closing.summary.difference_cents === 0 ? 'green' : 'yellow'" />
                <ReportSummaryCard label="Pendências contábeis" :value="String(closing.summary.accounting_pending_count)" tone="yellow" />
            </div>

            <ReportSection><template #header><div><h2 class="font-bold text-white">O que falta para fechar o mês</h2><p class="text-sm text-gray-400">Resolva as pendências antes de considerar o mês conferido.</p></div></template><div class="grid gap-2 p-4 sm:grid-cols-2 lg:grid-cols-5"><div v-for="item in checklist" :key="item[0]" class="rounded-lg border border-gray-700 p-3"><span class="block text-xs text-gray-400">{{ item[0] }}</span><b class="text-2xl text-white">{{ item[1] }}</b></div></div></ReportSection>

            <ReportSection><template #header><h2 class="font-bold text-white">Contas bancárias</h2></template><div v-if="!closing.banks.length" class="p-5 text-gray-400">Nenhuma conta bancária ativa.</div><div v-else class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-gray-950 text-gray-400"><tr><th class="p-3 text-left">Conta</th><th>Inicial</th><th>Entradas</th><th>Saídas</th><th>Final</th><th>Status</th><th>Pendências</th><th></th></tr></thead><tbody class="divide-y divide-gray-800"><tr v-for="bank in closing.banks" :key="bank.id"><td class="p-3"><b class="block text-white">{{ bank.name }}</b><span class="text-gray-400">{{ bank.bank_name }}</span></td><td class="p-3 text-right">{{ formatCurrency(bank.balances.opening_operational_cents) }}</td><td class="p-3 text-right">{{ formatCurrency(bank.balances.inflows_cents) }}</td><td class="p-3 text-right">{{ formatCurrency(bank.balances.outflows_cents) }}</td><td class="p-3 text-right">{{ formatCurrency(bank.balances.closing_operational_cents) }}</td><td class="p-3"><StatusBadge :status="bank.status" /></td><td class="p-3 text-center">{{ bank.pending_count }}</td><td class="p-3 whitespace-nowrap"><Link :href="bank.closing_url" class="text-indigo-300">Fechamento</Link><span class="mx-2 text-gray-600">·</span><Link :href="bank.statement_url" class="text-indigo-300">Extrato</Link></td></tr></tbody></table></div></ReportSection>

            <ReportSection><template #header><div class="flex w-full justify-between"><h2 class="font-bold text-white">Cartões</h2><Link :href="route('credit-cards.index')" class="text-sm text-indigo-300">Abrir cartões</Link></div></template><div v-if="!closing.cards.length" class="p-5 text-gray-400">Nenhum cartão ativo.</div><div v-else class="grid gap-3 p-4 md:grid-cols-2 lg:grid-cols-3"><Link v-for="card in closing.cards" :key="card.id" :href="card.url" class="rounded-lg border border-gray-700 p-4"><b class="text-white">{{ card.name }}</b><p class="text-xs text-gray-400">{{ card.issuer_name }} · {{ card.status_label }}</p><p class="mt-3 text-sm">Compras: {{ card.total_cents === null ? 'Não disponível' : formatCurrency(card.total_cents) }}</p><p class="text-sm">Pago: {{ card.paid_cents === null ? 'Não disponível' : formatCurrency(card.paid_cents) }}</p><p class="text-sm">Em aberto: {{ card.balance_cents === null ? 'Não disponível' : formatCurrency(card.balance_cents) }}</p></Link></div></ReportSection>

            <div class="grid gap-4 lg:grid-cols-2">
                <ReportSection><template #header><div class="flex w-full justify-between"><h2 class="font-bold text-white">Contas a pagar</h2><Link :href="closing.payables.url" class="text-sm text-indigo-300">Abrir AP</Link></div></template><div class="grid grid-cols-2 gap-2 p-4"><div v-for="(item, key) in { 'Vencem no mês': closing.payables.due, 'Pagas no mês': closing.payables.paid, 'Em aberto': closing.payables.open, 'Vencidas': closing.payables.overdue }" :key="key" class="rounded border border-gray-700 p-3"><span class="text-xs text-gray-400">{{ key }} ({{ item.count }})</span><b class="block text-white">{{ formatCurrency(item.amount_cents) }}</b></div></div></ReportSection>
                <ReportSection><template #header><div class="flex w-full justify-between"><h2 class="font-bold text-white">Contas a receber</h2><Link :href="closing.receivables.url" class="text-sm text-indigo-300">Abrir AR</Link></div></template><div class="grid grid-cols-2 gap-2 p-4"><div v-for="(item, key) in { 'Previstas no mês': closing.receivables.expected, 'Recebidas no mês': closing.receivables.received, 'Em aberto': closing.receivables.open, 'Vencidas': closing.receivables.overdue }" :key="key" class="rounded border border-gray-700 p-3"><span class="text-xs text-gray-400">{{ key }} ({{ item.count }})</span><b class="block text-white">{{ formatCurrency(item.amount_cents) }}</b></div></div></ReportSection>
            </div>

            <ReportSection><template #header><div class="flex w-full flex-wrap items-center justify-between gap-2"><h2 class="font-bold text-white">Pendências contábeis</h2><div class="flex gap-2"><Link :href="closing.links.pending" class="rounded border border-gray-600 px-3 py-2 text-sm">Abrir Pendências Contábeis</Link><button v-if="closing.accounting.draft_ready" :disabled="processing" class="rounded bg-green-600 px-3 py-2 text-sm font-semibold text-white" @click="postReady">Postar prontos ({{ closing.accounting.draft_ready }})</button></div></div></template><div class="grid gap-2 p-4 sm:grid-cols-3 lg:grid-cols-5"><div v-for="(value, label) in { 'Prontos': closing.accounting.draft_ready, 'Incompletos': closing.accounting.draft_incomplete, 'A classificar': closing.accounting.unclassified, 'Desbalanceados': closing.accounting.unbalanced, 'Postados no mês': closing.accounting.posted }" :key="label" class="rounded border border-gray-700 p-3"><span class="block text-xs text-gray-400">{{ label }}</span><b class="text-2xl text-white">{{ value }}</b></div></div></ReportSection>

            <ReportSection><template #header><h2 class="font-bold text-white">Relatórios</h2></template><div class="flex flex-wrap gap-2 p-4"><Link v-for="(url, label) in { 'Livro Diário': closing.links.journal, 'Razão': closing.links.ledger, 'Balancete': closing.links.trial_balance, 'DRE': closing.links.income_statement, 'Balanço Patrimonial': closing.links.balance_sheet, 'Posição Financeira': closing.links.financial_position }" :key="label" :href="url" class="rounded border border-indigo-500/50 px-4 py-2 text-indigo-200">{{ label }}</Link></div></ReportSection>

            <div v-if="showClose" class="fixed inset-0 z-50 grid place-items-center bg-black/75 p-4"><div class="w-full max-w-lg rounded-xl border border-gray-700 bg-gray-950 p-6"><h2 class="text-xl font-bold text-white">Fechar {{ closing.period.label }}?</h2><p class="mt-2 text-sm text-gray-300">Status atual: {{ closing.status_label }}. Saldo operacional: {{ formatCurrency(closing.summary.closing_operational_cents) }}. Saldo contábil: {{ formatCurrency(closing.summary.posted_accounting_cents) }}.</p><p class="mt-3 rounded border border-red-500/30 bg-red-950/20 p-3 text-sm text-red-200">Alterações financeiras e contábeis neste período serão bloqueadas até que o mês seja reaberto.</p><label class="mt-4 block text-sm text-gray-300">Observação (opcional)<textarea v-model="closeNote" class="mt-1 w-full rounded border border-gray-700 bg-black p-2" rows="3" /></label><div class="mt-4 flex justify-end gap-2"><button class="rounded border border-gray-600 px-4 py-2" @click="showClose = false">Cancelar</button><button :disabled="processing" class="rounded bg-red-700 px-4 py-2 font-semibold text-white" @click="closeMonth">Confirmar fechamento</button></div></div></div>
            <div v-if="showReopen" class="fixed inset-0 z-50 grid place-items-center bg-black/75 p-4"><div class="w-full max-w-lg rounded-xl border border-gray-700 bg-gray-950 p-6"><h2 class="text-xl font-bold text-white">Reabrir {{ closing.period.label }}?</h2><p class="mt-2 text-sm text-gray-300">Informe por que o período precisa voltar a aceitar alterações.</p><label class="mt-4 block text-sm text-gray-300">Justificativa obrigatória<textarea v-model="reopenReason" class="mt-1 w-full rounded border border-gray-700 bg-black p-2" rows="3" required /></label><div class="mt-4 flex justify-end gap-2"><button class="rounded border border-gray-600 px-4 py-2" @click="showReopen = false">Cancelar</button><button :disabled="processing || reopenReason.trim().length < 3" class="rounded bg-amber-600 px-4 py-2 font-semibold text-white disabled:opacity-40" @click="reopenMonth">Reabrir mês</button></div></div></div>
        </ReportPage>
    </AppLayout>
</template>
