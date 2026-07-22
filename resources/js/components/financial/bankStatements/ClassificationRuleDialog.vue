<script setup lang="ts">
import type { BankStatementAccount, BankStatementClassificationAccount, BankStatementTransaction } from '@/types/financial/bankStatement';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { route } from 'ziggy-js';

const props = defineProps<{ transaction: BankStatementTransaction; bankAccount: BankStatementAccount; accounts: BankStatementClassificationAccount[]; suppliers: Array<{id:number;name:string}>; customers: Array<{id:number;name:string}> }>();
const open = ref(false);
const form = useForm({ name: '', match_text: '', match_mode: 'contains', direction: props.transaction.type === 'inflow' ? 'in' : 'out', operation_type: props.transaction.operation_type ?? (props.transaction.type === 'inflow' ? 'income' : 'expense'), chart_of_account_id: null as number|null, bank_account_id: null as number|null, supplier_id: null as number|null, customer_id: null as number|null, investment_account_id: null as number|null, active: true, priority: 0 });
const target = computed({
    get: () => form.operation_type === 'transfer' ? form.bank_account_id : form.operation_type === 'payment' ? form.supplier_id : form.operation_type === 'investment' ? form.investment_account_id : form.chart_of_account_id,
    set: (value: number|null) => { form.chart_of_account_id = form.bank_account_id = form.supplier_id = form.customer_id = form.investment_account_id = null; if (form.operation_type === 'transfer') form.bank_account_id=value; else if(form.operation_type==='payment') form.supplier_id=value; else if(form.operation_type==='investment') form.investment_account_id=value; else form.chart_of_account_id=value; },
});
const targets = computed(() => {
    if (form.operation_type === 'payment') return props.suppliers;
    if (form.operation_type === 'transfer') return props.accounts.filter(a => a.bank_account && a.bank_account.id !== props.bankAccount.id).map(a => ({id:a.bank_account!.id,name:a.bank_account!.name}));
    return props.accounts.filter(a => a.allowed_operation_types.includes(form.operation_type as never));
});
function show() { const words=(props.transaction.description??'').trim().split(/\s+/); form.name=words.slice(-2).join(' '); form.match_text=words.slice(-2).join(' '); target.value=props.transaction.classification_account_id; open.value=true; }
function submit() { form.post(route('bank-statement-classification-rules.store'), { preserveScroll:true, onSuccess:()=>open.value=false }); }
</script>
<template>
  <button type="button" class="mt-2 text-xs font-semibold text-indigo-300 hover:underline" @click="show">Criar regra para lançamentos parecidos</button>
  <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" @click.self="open=false">
    <form class="w-full max-w-lg space-y-4 rounded-xl border border-gray-700 bg-gray-950 p-6" @submit.prevent="submit">
      <h3 class="text-lg font-bold text-white">Criar regra de classificação</h3>
      <div class="grid gap-3 sm:grid-cols-2">
        <label class="text-sm text-gray-300">Nome<input v-model="form.name" required class="mt-1 w-full rounded border border-gray-700 bg-black p-2 text-white"></label>
        <label class="text-sm text-gray-300">Texto de busca<input v-model="form.match_text" required class="mt-1 w-full rounded border border-gray-700 bg-black p-2 text-white"></label>
        <label class="text-sm text-gray-300">Correspondência<select v-model="form.match_mode" class="mt-1 w-full rounded border border-gray-700 bg-black p-2"><option value="contains">Contém</option><option value="starts_with">Começa com</option><option value="exact">Exata</option></select></label>
        <label class="text-sm text-gray-300">Direção<select v-model="form.direction" class="mt-1 w-full rounded border border-gray-700 bg-black p-2"><option value="in">Entrada</option><option value="out">Saída</option><option value="any">Qualquer</option></select></label>
        <label class="text-sm text-gray-300">Operação<select v-model="form.operation_type" class="mt-1 w-full rounded border border-gray-700 bg-black p-2" @change="target=null"><option value="expense">Despesa</option><option value="income">Receita</option><option value="payment">Pagamento</option><option value="transfer">Transferência</option><option value="investment">Investimento</option><option value="fee">Tarifa</option><option value="other">Outro</option></select></label>
        <label class="text-sm text-gray-300">Classificação<select v-model="target" required class="mt-1 w-full rounded border border-gray-700 bg-black p-2"><option :value="null" disabled>Selecione</option><option v-for="item in targets" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
        <label class="text-sm text-gray-300">Prioridade<input v-model.number="form.priority" type="number" class="mt-1 w-full rounded border border-gray-700 bg-black p-2 text-white"></label>
      </div>
      <p v-if="Object.keys(form.errors).length" class="text-sm text-red-300">{{ Object.values(form.errors)[0] }}</p>
      <div class="flex justify-end gap-2"><button type="button" class="rounded border border-gray-600 px-3 py-2" @click="open=false">Cancelar</button><button :disabled="form.processing" class="rounded bg-indigo-600 px-3 py-2 font-semibold text-white">Salvar regra</button></div>
    </form>
  </div>
</template>
