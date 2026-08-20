<script setup lang="ts">
import ReportSection from '@/components/reports/ReportSection.vue';
import { formatAccount, formatCurrency, formatDate } from '@/lib/formatters';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { route } from 'ziggy-js';

const props = defineProps<{ rules: any[]; counterparties: any[]; accounts: any[]; type: 'payable' | 'receivable' }>();
const open = ref(false);
const editing = ref<number | null>(null);
const deactivating = ref<number | null>(null);
const form = useForm<any>({});
const labels: Record<string, string> = { monthly: 'Mensal', quarterly: 'Trimestral', semiannual: 'Semestral', annual: 'Anual', fixed: 'Fixo', variable: 'Variável' };
const prefix = () => props.type === 'payable' ? 'accounts-payable' : 'accounts-receivable';
const counterpartyKey = () => props.type === 'payable' ? 'supplier_id' : 'customer_id';

function edit(rule: any) {
    editing.value = rule.id; deactivating.value = null;
    form.defaults({ effective_from: rule.minimum_revision_period, description: rule.description, [counterpartyKey()]: rule.counterparty?.id,
        frequency: rule.frequency, amount_mode: rule.amount_mode, expected_amount_cents: rule.expected_amount_cents,
        due_day: rule.due_day, default_account_id: rule.default_account?.id, ends_on: rule.ends_on, notes: rule.notes });
    form.reset(); form.clearErrors();
}
function save(rule: any) { form.put(route(`${prefix()}.recurring.revise`, [rule.id]), { preserveScroll: true, onSuccess: () => editing.value = null }); }
function deactivate(rule: any) {
    editing.value = null; deactivating.value = rule.id; form.defaults({ effective_from: rule.minimum_revision_period }); form.reset(); form.clearErrors();
}
function confirmDeactivate(rule: any) { form.patch(route(`${prefix()}.recurring.deactivate`, [rule.id]), { preserveScroll: true, onSuccess: () => deactivating.value = null }); }
</script>

<template>
    <button type="button" class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-200 hover:bg-gray-800" @click="open = !open">Gerenciar recorrências</button>
    <ReportSection v-if="open">
        <template #header><div><h2 class="text-lg font-bold text-white">Recorrências</h2><p class="text-sm text-gray-400">Regras vigentes; competências previstas continuam na seção própria.</p></div></template>
        <div v-if="!rules.length" class="p-6 text-sm text-gray-400">Nenhuma recorrência ativa.</div>
        <div v-for="rule in rules" :key="rule.id" class="border-t border-gray-700 p-6 first:border-t-0">
            <div class="flex flex-wrap justify-between gap-4">
                <div><h3 class="font-semibold text-white">{{ rule.description }}</h3><p class="text-sm text-gray-400">{{ labels[rule.frequency] }} · {{ labels[rule.amount_mode] }} · {{ rule.counterparty?.name }}</p><p class="text-sm text-gray-400">{{ type === 'payable' ? 'Despesa' : 'Receita' }}: {{ formatAccount(rule.default_account?.code, rule.default_account?.name) }}</p><p class="mt-1 text-sm text-gray-300">Próxima: {{ rule.next_due_date ? formatDate(rule.next_due_date) : '-' }} · Previsão: {{ rule.next_expected_amount_cents ? formatCurrency(rule.next_expected_amount_cents) : '-' }}</p></div>
                <div class="flex gap-2"><button class="text-sm text-indigo-300" @click="edit(rule)">Editar</button><button class="text-sm text-red-300" @click="deactivate(rule)">Desativar</button></div>
            </div>
            <form v-if="editing === rule.id" class="mt-5 grid gap-4 rounded-lg bg-gray-900 p-4 md:grid-cols-2" @submit.prevent="save(rule)">
                <p class="md:col-span-2 text-sm text-gray-400">As alterações serão aplicadas somente às competências futuras. Títulos e lançamentos já confirmados não serão modificados.</p>
                <label class="text-sm text-gray-300">Aplicar alterações a partir de<input v-model="form.effective_from" type="date" :min="rule.minimum_revision_period" class="mt-1 w-full rounded border border-gray-700 bg-black p-2" /></label>
                <label class="text-sm text-gray-300">Descrição<input v-model="form.description" class="mt-1 w-full rounded border border-gray-700 bg-black p-2" /></label>
                <label class="text-sm text-gray-300">{{ type === 'payable' ? 'Fornecedor' : 'Cliente' }}<select v-model="form[counterpartyKey()]" class="mt-1 w-full rounded border border-gray-700 bg-black p-2"><option v-for="item in counterparties" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                <label class="text-sm text-gray-300">Periodicidade<select v-model="form.frequency" class="mt-1 w-full rounded border border-gray-700 bg-black p-2"><option v-for="(label, value) in { monthly:'Mensal', quarterly:'Trimestral', semiannual:'Semestral', annual:'Anual' }" :key="value" :value="value">{{ label }}</option></select><small v-if="form.frequency !== rule.frequency">A nova periodicidade será iniciada a partir da competência escolhida.</small></label>
                <label class="text-sm text-gray-300">Comportamento do valor<select v-model="form.amount_mode" class="mt-1 w-full rounded border border-gray-700 bg-black p-2"><option value="fixed">Fixo</option><option value="variable">Variável</option></select></label>
                <label class="text-sm text-gray-300">{{ form.amount_mode === 'fixed' ? 'Valor fixo (centavos)' : 'Previsão base (centavos)' }}<input v-model.number="form.expected_amount_cents" type="number" min="1" class="mt-1 w-full rounded border border-gray-700 bg-black p-2" /><small v-if="form.amount_mode === 'variable'">As próximas previsões usarão o histórico das últimas competências confirmadas.</small></label>
                <label class="text-sm text-gray-300">Dia esperado de vencimento<input v-model.number="form.due_day" type="number" min="1" max="31" class="mt-1 w-full rounded border border-gray-700 bg-black p-2" /></label>
                <label class="text-sm text-gray-300">Conta<select v-model="form.default_account_id" class="mt-1 w-full rounded border border-gray-700 bg-black p-2"><option v-for="item in accounts" :key="item.id" :value="item.id">{{ item.label }}</option></select></label>
                <label class="text-sm text-gray-300">Encerrar em<input v-model="form.ends_on" type="date" class="mt-1 w-full rounded border border-gray-700 bg-black p-2" /></label>
                <label class="text-sm text-gray-300 md:col-span-2">Observações<textarea v-model="form.notes" class="mt-1 w-full rounded border border-gray-700 bg-black p-2" /></label>
                <div v-if="Object.keys(form.errors).length" class="md:col-span-2 text-sm text-red-300">{{ Object.values(form.errors)[0] }}</div>
                <div class="md:col-span-2 flex gap-2"><button :disabled="form.processing" class="rounded bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Salvar versão</button><button type="button" class="px-4 py-2 text-sm text-gray-300" @click="editing = null">Cancelar</button></div>
            </form>
            <form v-if="deactivating === rule.id" class="mt-5 rounded-lg bg-gray-900 p-4" @submit.prevent="confirmDeactivate(rule)"><label class="text-sm text-gray-300">Encerrar recorrência a partir de<input v-model="form.effective_from" type="date" :min="rule.minimum_revision_period" class="ml-3 rounded border border-gray-700 bg-black p-2" /></label><p class="my-3 text-sm text-gray-400">Competências já confirmadas permanecerão inalteradas.</p><p v-if="Object.keys(form.errors).length" class="mb-3 text-sm text-red-300">{{ Object.values(form.errors)[0] }}</p><button class="rounded bg-red-700 px-4 py-2 text-sm font-semibold text-white">Confirmar encerramento</button><button type="button" class="px-4 py-2 text-sm text-gray-300" @click="deactivating = null">Cancelar</button></form>
        </div>
    </ReportSection>
</template>
