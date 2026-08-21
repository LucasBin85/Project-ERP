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
const performanceOpen = ref<number | null>(null);
const backtestOpen = ref<number | null>(null);
const form = useForm<any>({});
const labels: Record<string, string> = { monthly: 'Mensal', quarterly: 'Trimestral', semiannual: 'Semestral', annual: 'Anual', fixed: 'Fixo', variable: 'Variável' };
const prefix = () => props.type === 'payable' ? 'accounts-payable' : 'accounts-receivable';
const counterpartyKey = () => props.type === 'payable' ? 'supplier_id' : 'customer_id';

function edit(rule: any) {
    editing.value = rule.id; deactivating.value = null;
    form.defaults({ effective_from: rule.minimum_revision_period, description: rule.description, [counterpartyKey()]: rule.counterparty?.id,
        frequency: rule.frequency, amount_mode: rule.amount_mode, expected_amount_cents: rule.expected_amount_cents,
        forecast_strategy: rule.forecast_strategy,
        due_day: rule.due_day, default_account_id: rule.default_account?.id, ends_on: rule.ends_on, notes: rule.notes });
    form.reset(); form.clearErrors();
}
function save(rule: any) { form.put(route(`${prefix()}.recurring.revise`, [rule.id]), { preserveScroll: true, onSuccess: () => editing.value = null }); }
function deactivate(rule: any) {
    editing.value = null; deactivating.value = rule.id; form.defaults({ effective_from: rule.minimum_revision_period }); form.reset(); form.clearErrors();
}
function confirmDeactivate(rule: any) { form.patch(route(`${prefix()}.recurring.deactivate`, [rule.id]), { preserveScroll: true, onSuccess: () => deactivating.value = null }); }
function amountModeChanged() { form.forecast_strategy = form.amount_mode === 'variable' ? (form.forecast_strategy ?? 'mean_last_3') : null; }
function formatPercentBps(value: number | null): string { return value === null ? '-' : `${(value / 100).toFixed(2).replace('.', ',')}%`; }
function formatPeriod(value: string): string { const [year, month] = value.split('-'); return `${month}/${year}`; }
function signedCurrency(value: number): string { return `${value > 0 ? '+ ' : value < 0 ? '- ' : ''}${formatCurrency(Math.abs(value))}`; }
function biasTone(value: number): string {
    if (value === 0) return 'text-gray-300';
    const favorable = props.type === 'payable' ? value < 0 : value > 0;
    return favorable ? 'text-green-300' : 'text-red-300';
}
function biasText(value: number): string {
    if (value === 0) return 'Em média, realizado igual à previsão';
    return `Em média ${formatCurrency(Math.abs(value))} ${value > 0 ? 'acima' : 'abaixo'} da previsão`;
}
</script>

<template>
    <button type="button" class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-200 hover:bg-gray-800" @click="open = !open">Gerenciar recorrências</button>
    <ReportSection v-if="open">
        <template #header><div><h2 class="text-lg font-bold text-white">Recorrências</h2><p class="text-sm text-gray-400">Regras vigentes; competências previstas continuam na seção própria.</p></div></template>
        <div v-if="!rules.length" class="p-6 text-sm text-gray-400">Nenhuma recorrência ativa.</div>
        <div v-for="rule in rules" :key="rule.id" class="border-t border-gray-700 p-6 first:border-t-0">
            <div class="flex flex-wrap justify-between gap-4">
                <div><h3 class="font-semibold text-white">{{ rule.description }}</h3><p class="text-sm text-gray-400">{{ labels[rule.frequency] }} · {{ labels[rule.amount_mode] }} · {{ rule.counterparty?.name }}</p><p v-if="rule.forecast_strategy_label" class="text-sm text-gray-400">Método de previsão: {{ rule.forecast_strategy_label }}</p><p class="text-sm text-gray-400">{{ type === 'payable' ? 'Despesa' : 'Receita' }}: {{ formatAccount(rule.default_account?.code, rule.default_account?.name) }}</p><p class="mt-1 text-sm text-gray-300">Próxima: {{ rule.next_due_date ? formatDate(rule.next_due_date) : '-' }} · Previsão: {{ rule.next_expected_amount_cents ? formatCurrency(rule.next_expected_amount_cents) : '-' }}</p></div>
                <div class="flex gap-2"><button class="text-sm text-cyan-300" @click="performanceOpen = performanceOpen === rule.id ? null : rule.id">Previsão x realizado</button><button class="text-sm text-indigo-300" @click="edit(rule)">Editar</button><button class="text-sm text-red-300" @click="deactivate(rule)">Desativar</button></div>
            </div>
            <div v-if="performanceOpen === rule.id" class="mt-5 rounded-lg border border-gray-700 bg-gray-950 p-4">
                <h4 class="font-semibold text-white">Previsão x realizado</h4>
                <p v-if="rule.performance.total_confirmed_count === 0" class="mt-2 text-sm text-gray-400">Ainda não há competências confirmadas para comparar.</p>
                <template v-else>
                    <p class="mt-1 text-sm text-gray-400">Últimas {{ rule.performance.sample_confirmed_count }} de {{ rule.performance.total_confirmed_count }} competências confirmadas · {{ rule.performance.estimated_confirmed_count }} com previsão · {{ rule.performance.unestimated_confirmed_count }} sem previsão</p>
                    <div v-if="rule.performance.estimated_confirmed_count" class="mt-4 grid gap-3 sm:grid-cols-3">
                        <div class="rounded border border-gray-700 p-3"><span class="text-xs text-gray-400">Erro médio</span><b class="block text-white">{{ formatCurrency(rule.performance.mean_absolute_variance_cents) }}</b></div>
                        <div class="rounded border border-gray-700 p-3"><span class="text-xs text-gray-400">Erro percentual médio</span><b class="block text-white">{{ formatPercentBps(rule.performance.mean_absolute_percentage_error_bps) }}</b></div>
                        <div class="rounded border border-gray-700 p-3"><span class="text-xs text-gray-400">Viés médio</span><b class="block" :class="biasTone(rule.performance.mean_signed_variance_cents)">{{ signedCurrency(rule.performance.mean_signed_variance_cents) }}</b><small :class="biasTone(rule.performance.mean_signed_variance_cents)">{{ biasText(rule.performance.mean_signed_variance_cents) }}</small></div>
                    </div>
                    <p v-else class="mt-3 text-sm text-amber-300">Ainda não há competências com previsão registrada suficiente para calcular os erros.</p>
                    <div class="mt-4 overflow-x-auto"><table class="w-full text-sm"><thead class="text-gray-400"><tr><th class="py-2 text-left">Competência</th><th class="py-2 text-right">Previsto</th><th class="py-2 text-right">Realizado</th><th class="py-2 text-right">Variação</th></tr></thead><tbody class="divide-y divide-gray-800"><tr v-for="period in rule.performance.periods" :key="period.occurrence_id"><td class="py-2"><a v-if="period.title_url" :href="period.title_url" class="text-indigo-300">{{ formatPeriod(period.period_date) }}</a><span v-else>{{ formatPeriod(period.period_date) }}</span></td><td class="py-2 text-right">{{ period.has_estimate ? formatCurrency(period.expected_amount_cents) : 'Sem estimativa' }}</td><td class="py-2 text-right">{{ period.actual_amount_cents === null ? '-' : formatCurrency(period.actual_amount_cents) }}</td><td class="py-2 text-right" :class="period.variance_cents === null ? 'text-gray-500' : biasTone(period.variance_cents)">{{ period.variance_cents === null ? '—' : signedCurrency(period.variance_cents) }}</td></tr></tbody></table></div>
                </template>
                <div class="mt-5 border-t border-gray-700 pt-4">
                    <button type="button" class="text-sm font-semibold text-cyan-300" @click="backtestOpen = backtestOpen === rule.id ? null : rule.id">Comparar métodos de previsão</button>
                    <div v-if="backtestOpen === rule.id" class="mt-3">
                        <p v-if="!rule.backtest.applicable" class="text-sm text-gray-400">Esta recorrência utiliza valor fixo; comparação de métodos variáveis não se aplica.</p>
                        <div v-else-if="!rule.backtest.has_sufficient_history" class="text-sm text-amber-300">
                            <p>Histórico insuficiente para comparar métodos de previsão.</p>
                            <p class="mt-1 text-gray-400">São necessárias pelo menos 4 competências realizadas.</p>
                        </div>
                        <template v-else>
                            <p class="text-sm font-semibold text-white">Melhor resultado histórico: {{ rule.backtest.recommended_strategy_label }}<span v-if="rule.backtest.recommended_strategy === rule.backtest.current_strategy"> (método atual)</span></p>
                            <p class="mt-1 text-sm text-gray-400">Comparação baseada em {{ rule.backtest.sample_target_count }} competências anteriores. A estratégia usada atualmente não foi alterada.</p>
                            <div class="mt-3 overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="text-gray-400"><tr><th class="py-2 text-left">Método</th><th class="py-2 text-right">Períodos</th><th class="py-2 text-right">Erro médio</th><th class="py-2 text-right">Erro percentual médio</th><th class="py-2 text-right">Viés</th></tr></thead>
                                    <tbody class="divide-y divide-gray-800"><tr v-for="strategy in rule.backtest.strategies" :key="strategy.code"><td class="py-2">{{ strategy.label }}<span v-if="strategy.code === rule.backtest.current_strategy" class="text-gray-500"> · atual</span></td><td class="py-2 text-right">{{ strategy.sample_count }}</td><td class="py-2 text-right">{{ formatCurrency(strategy.mean_absolute_variance_cents) }}</td><td class="py-2 text-right">{{ formatPercentBps(strategy.mean_absolute_percentage_error_bps) }}</td><td class="py-2 text-right" :class="biasTone(strategy.mean_signed_variance_cents)">{{ signedCurrency(strategy.mean_signed_variance_cents) }}</td></tr></tbody>
                                </table>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
            <form v-if="editing === rule.id" class="mt-5 grid gap-4 rounded-lg bg-gray-900 p-4 md:grid-cols-2" @submit.prevent="save(rule)">
                <p class="md:col-span-2 text-sm text-gray-400">As alterações serão aplicadas somente às competências futuras. Títulos e lançamentos já confirmados não serão modificados.</p>
                <label class="text-sm text-gray-300">Aplicar alterações a partir de<input v-model="form.effective_from" type="date" :min="rule.minimum_revision_period" class="mt-1 w-full rounded border border-gray-700 bg-black p-2" /></label>
                <label class="text-sm text-gray-300">Descrição<input v-model="form.description" class="mt-1 w-full rounded border border-gray-700 bg-black p-2" /></label>
                <label class="text-sm text-gray-300">{{ type === 'payable' ? 'Fornecedor' : 'Cliente' }}<select v-model="form[counterpartyKey()]" class="mt-1 w-full rounded border border-gray-700 bg-black p-2"><option v-for="item in counterparties" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                <label class="text-sm text-gray-300">Periodicidade<select v-model="form.frequency" class="mt-1 w-full rounded border border-gray-700 bg-black p-2"><option v-for="(label, value) in { monthly:'Mensal', quarterly:'Trimestral', semiannual:'Semestral', annual:'Anual' }" :key="value" :value="value">{{ label }}</option></select><small v-if="form.frequency !== rule.frequency">A nova periodicidade será iniciada a partir da competência escolhida.</small></label>
                <label class="text-sm text-gray-300">Comportamento do valor<select v-model="form.amount_mode" class="mt-1 w-full rounded border border-gray-700 bg-black p-2" @change="amountModeChanged"><option value="fixed">Fixo</option><option value="variable">Variável</option></select></label>
                <label v-if="form.amount_mode === 'variable'" class="text-sm text-gray-300">Método de previsão<select v-model="form.forecast_strategy" class="mt-1 w-full rounded border border-gray-700 bg-black p-2"><option value="mean_last_3">Média das últimas 3</option><option value="last_actual">Último realizado</option><option value="median_last_3">Mediana das últimas 3</option></select><small>A alteração será aplicada somente pela nova versão da regra.</small></label>
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
