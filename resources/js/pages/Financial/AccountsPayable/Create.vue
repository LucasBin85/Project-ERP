<script setup lang="ts">
import SupplierQuickCreateDialog from '@/components/financial/counterparties/SupplierQuickCreateDialog.vue';
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import { useAccountPayableCreate } from '@/composables/financial/useAccountPayableCreate';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCurrency } from '@/lib/formatters';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { computed, ref, watch } from 'vue';

const props = defineProps<{ wallet: Record<string, any>; suppliers: Array<Record<string, any>>; payableControlAccounts: Array<Record<string, any>>; expenseAccounts: Array<Record<string, any>>; supplierNames: string[] }>();
const accountPayable = useAccountPayableCreate();
const suppliers = ref([...props.suppliers]);
const supplierNames = ref([...props.supplierNames]);
const showSupplierDialog = ref(false);
const selectedSupplier = computed(() => suppliers.value.find((item) => item.id === Number(accountPayable.form.supplier_id)));
watch(() => accountPayable.form.supplier_id, () => {
    accountPayable.form.recurring_default_account_id = selectedSupplier.value?.default_expense_account_id
        ? String(selectedSupplier.value.default_expense_account_id)
        : '';
});
function supplierCreated(supplier: Record<string, any>) {
    suppliers.value.push(supplier); supplierNames.value.push(supplier.name); suppliers.value.sort((a, b) => a.name.localeCompare(b.name)); accountPayable.form.supplier_id = String(supplier.id);
}
function submit() { if (accountPayable.canSubmit.value) accountPayable.form.post(route('accounts-payable.store')); }
</script>

<template>
    <AppLayout title="Nova conta a pagar">
        <ReportPage title="Nova conta a pagar" :subtitle="wallet.name">
            <ReportSection>
                <template #header><div><h2 class="text-lg font-bold text-white">Dados do título</h2><p class="mt-1 text-sm text-gray-400">A provisão contábil será criada em rascunho pelo valor total.</p></div></template>
                <form class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2" @submit.prevent="submit">
                    <div class="md:col-span-2"><label class="mb-1 block text-sm font-semibold text-gray-300">Descrição</label><input v-model="accountPayable.form.description" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" /><p class="mt-1 text-sm text-red-400">{{ accountPayable.form.errors.description }}</p></div>
                    <div class="md:col-span-2"><label class="mb-1 block text-sm font-semibold text-gray-300">Fornecedor / Beneficiário</label><div class="flex gap-2"><select v-model="accountPayable.form.supplier_id" class="min-w-0 flex-1 rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"><option value="">Selecione o fornecedor</option><option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option></select><button type="button" class="rounded-lg border border-indigo-500 px-3 py-2 text-sm text-indigo-300" @click="showSupplierDialog = true">Cadastrar fornecedor</button></div><div v-if="selectedSupplier" class="mt-2 rounded-lg border border-gray-700 bg-gray-950 p-3 text-sm text-gray-300">Conta de controle: {{ selectedSupplier.payable_account.code }} - {{ selectedSupplier.payable_account.name }} · Despesa: {{ selectedSupplier.default_expense_account.code }} - {{ selectedSupplier.default_expense_account.name }}</div><p class="mt-1 text-sm text-red-400">{{ accountPayable.form.errors.supplier_id }}</p></div>
                    <div><label class="mb-1 block text-sm font-semibold text-gray-300">Valor total</label><input :value="accountPayable.form.amount" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="R$ 0,00" inputmode="numeric" @input="accountPayable.updateAmount" /></div>
                    <div><label class="mb-1 block text-sm font-semibold text-gray-300">Vencimento inicial</label><input v-model="accountPayable.form.due_date" type="date" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]" /></div>
                    <div><label class="mb-1 block text-sm font-semibold text-gray-300">Competência</label><input v-model="accountPayable.form.competence_date" type="date" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]" /></div>
                    <div><label class="mb-1 block text-sm font-semibold text-gray-300">Tipo do lançamento</label><select v-model="accountPayable.form.mode" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"><option value="single">Única</option><option value="installment">Parcelada</option><option value="recurring">Recorrente</option></select></div>
                    <div v-if="accountPayable.form.mode === 'recurring'" class="md:col-span-2 grid grid-cols-1 gap-4 rounded-lg border border-gray-700 bg-gray-950 p-4 md:grid-cols-2">
                        <div class="md:col-span-2"><h3 class="font-semibold text-white">Configuração da recorrência</h3><p class="mt-1 text-sm text-gray-400">Somente esta competência será criada agora. As próximas serão tratadas conforme forem confirmadas.</p></div>
                        <div><label class="mb-1 block text-sm font-semibold text-gray-300">Periodicidade</label><select v-model="accountPayable.form.recurring_frequency" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"><option value="monthly">Mensal</option><option value="quarterly">Trimestral</option><option value="semiannual">Semestral</option><option value="annual">Anual</option></select><p class="mt-1 text-sm text-red-400">{{ accountPayable.form.errors.recurring_frequency }}</p></div>
                        <div><label class="mb-1 block text-sm font-semibold text-gray-300">Comportamento do valor</label><select v-model="accountPayable.form.recurring_amount_mode" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"><option value="fixed">Fixo</option><option value="variable">Variável</option></select><p class="mt-1 text-sm text-red-400">{{ accountPayable.form.errors.recurring_amount_mode }}</p></div>
                        <div><label class="mb-1 block text-sm font-semibold text-gray-300">Dia esperado de vencimento</label><input v-model.number="accountPayable.form.recurring_due_day" type="number" min="1" max="31" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" /><p class="mt-1 text-sm text-red-400">{{ accountPayable.form.errors.recurring_due_day }}</p></div>
                        <div><label class="mb-1 block text-sm font-semibold text-gray-300">Conta de despesa</label><select v-model="accountPayable.form.recurring_default_account_id" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"><option value="">Selecione a conta</option><option v-for="account in expenseAccounts" :key="account.id" :value="account.id">{{ account.label }}</option></select><p class="mt-1 text-sm text-red-400">{{ accountPayable.form.errors.recurring_default_account_id || accountPayable.form.errors.default_account_id }}</p></div>
                        <div v-if="accountPayable.form.recurring_amount_mode === 'variable'"><label class="mb-1 block text-sm font-semibold text-gray-300">Previsão inicial (opcional)</label><input :value="accountPayable.form.recurring_expected_amount" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" placeholder="R$ 0,00" inputmode="numeric" @input="accountPayable.updateRecurringExpectedAmount" /><p class="mt-1 text-sm text-red-400">{{ accountPayable.form.errors.recurring_expected_amount_cents }}</p></div>
                        <div v-if="accountPayable.form.recurring_amount_mode === 'variable'"><label class="mb-1 block text-sm font-semibold text-gray-300">Método de previsão</label><select v-model="accountPayable.form.recurring_forecast_strategy" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white"><option value="mean_last_3">Média das últimas 3</option><option value="last_actual">Último realizado</option><option value="median_last_3">Mediana das últimas 3</option></select><p class="mt-1 text-sm text-red-400">{{ accountPayable.form.errors.recurring_forecast_strategy }}</p></div>
                        <div><label class="mb-1 block text-sm font-semibold text-gray-300">Encerrar recorrência em (opcional)</label><input v-model="accountPayable.form.recurring_ends_on" type="date" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white [color-scheme:dark]" /><p class="mt-1 text-sm text-red-400">{{ accountPayable.form.errors.recurring_ends_on }}</p></div>
                        <p v-if="accountPayable.form.recurring_amount_mode === 'variable'" class="md:col-span-2 text-sm text-gray-400">A previsão usa os valores reais das competências anteriores. Sem histórico, será usada a previsão base informada, quando existir.</p>
                    </div>
                    <template v-if="accountPayable.form.mode === 'installment'">
                        <div><label class="mb-1 block text-sm font-semibold text-gray-300">Quantidade de parcelas</label><input v-model.number="accountPayable.form.installment_count" type="number" min="2" max="360" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" /></div>
                        <div><label class="mb-1 block text-sm font-semibold text-gray-300">Intervalo mensal</label><input v-model.number="accountPayable.form.interval_months" type="number" min="1" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" /></div>
                        <div class="md:col-span-2 rounded-lg border border-gray-700">
                            <div class="flex items-center justify-between border-b border-gray-700 p-4"><div><h3 class="font-semibold text-white">Parcelas do título</h3><p class="text-sm text-gray-400">Revise e ajuste os valores antes de salvar.</p></div><div class="flex gap-2"><button type="button" class="rounded border border-gray-600 px-3 py-1.5 text-sm text-gray-200" @click="accountPayable.recalculateInstallments">Recalcular parcelas</button><button v-if="accountPayable.difference.value !== 0" type="button" class="rounded border border-indigo-500 px-3 py-1.5 text-sm text-indigo-300" @click="accountPayable.adjustLastInstallment">Ajustar diferença na última</button></div></div>
                            <div class="overflow-x-auto"><table class="w-full"><thead><tr class="text-left text-xs uppercase text-gray-400"><th class="p-3">Parcela</th><th class="p-3">Vencimento</th><th class="p-3">Valor</th><th class="p-3">Validação</th></tr></thead><tbody><tr v-for="(item, index) in accountPayable.form.installments" :key="index" class="border-t border-gray-800"><td class="p-3 text-white">{{ index + 1 }}/{{ accountPayable.form.installment_count }}</td><td class="p-3"><input v-model="item.due_date" type="date" class="rounded border border-gray-700 bg-black px-2 py-1 text-white [color-scheme:dark]" /></td><td class="p-3"><input :value="item.amount" class="rounded border border-gray-700 bg-black px-2 py-1 text-white" @input="accountPayable.updateInstallmentAmount(index, $event)" /></td><td class="p-3 text-sm" :class="item.amount_cents > 0 ? 'text-green-400' : 'text-red-400'">{{ item.amount_cents > 0 ? 'Válida' : 'Valor inválido' }}</td></tr></tbody></table></div>
                            <div class="grid gap-2 border-t border-gray-700 p-4 text-sm md:grid-cols-3"><p class="text-gray-300">Total do título: <strong>{{ formatCurrency(accountPayable.form.amount_cents) }}</strong></p><p class="text-gray-300">Total das parcelas: <strong>{{ formatCurrency(accountPayable.installmentTotal.value) }}</strong></p><p :class="accountPayable.difference.value === 0 ? 'text-green-400' : 'text-red-400'">Diferença: <strong>{{ formatCurrency(accountPayable.difference.value) }}</strong></p><p class="md:col-span-3" :class="accountPayable.difference.value === 0 ? 'text-green-400' : 'text-red-400'">{{ accountPayable.difference.value === 0 ? 'Parcelas fecham com o valor total.' : 'A soma das parcelas precisa ser igual ao valor total.' }}</p></div>
                            <p class="px-4 pb-4 text-sm text-red-400">{{ accountPayable.form.errors.installments }}</p>
                        </div>
                    </template>
                    <div class="md:col-span-2"><label class="mb-1 block text-sm font-semibold text-gray-300">Observações</label><textarea v-model="accountPayable.form.notes" rows="3" class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-white" /></div>
                    <div class="md:col-span-2 flex justify-end gap-3"><Link :href="route('accounts-payable.index')" class="rounded-lg border border-gray-600 px-4 py-2 text-gray-300">Cancelar</Link><button type="submit" :disabled="!accountPayable.canSubmit.value || accountPayable.form.processing" class="rounded-lg bg-indigo-600 px-4 py-2 font-semibold text-white disabled:opacity-50">Salvar conta a pagar</button></div>
                </form>
            </ReportSection>
        </ReportPage>
        <SupplierQuickCreateDialog :show="showSupplierDialog" :control-accounts="payableControlAccounts" :expense-accounts="expenseAccounts" :existing-names="supplierNames" @close="showSupplierDialog = false" @created="supplierCreated" />
    </AppLayout>
</template>
