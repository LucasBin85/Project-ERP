<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BankOption, BankAccountType } from '@/types/financial/bankAccount';
import { Link, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
const props = defineProps<{ wallet: { id: number; name: string }; account: { id: number; bank_id: number; name: string; agency: string; account_number: string; account_type: BankAccountType; is_active: boolean }; banks: BankOption[] }>();
const form = useForm({ bank_id: props.account.bank_id, name: props.account.name, agency: props.account.agency, account_number: props.account.account_number, account_type: props.account.account_type, is_active: props.account.is_active });
function submit() { form.put(route('bank-accounts.update', [props.account.id])); }
</script>
<template><AppLayout title="Editar Conta Bancária"><ReportPage title="Editar Conta Bancária" :subtitle="wallet.name"><ReportSection><template #header><div><h2 class="text-lg font-bold text-white">Dados cadastrais</h2><p class="text-sm text-amber-300">Alterações em banco, agência ou número impactam validações futuras de OFX/extrato.</p></div></template><form class="grid gap-4 p-6 md:grid-cols-2" @submit.prevent="submit">
<label class="text-sm text-gray-300">Banco<select v-model="form.bank_id" class="mt-1 w-full rounded bg-gray-900 p-2 text-white"><option v-for="bank in banks" :key="bank.id" :value="bank.id">{{ bank.code }} - {{ bank.short_name }}</option></select><InputError :message="form.errors.bank_id" /></label>
<label class="text-sm text-gray-300">Nome/apelido<input v-model="form.name" class="mt-1 w-full rounded bg-gray-900 p-2 text-white"><InputError :message="form.errors.name" /></label>
<label class="text-sm text-gray-300">Agência<input v-model="form.agency" inputmode="numeric" class="mt-1 w-full rounded bg-gray-900 p-2 text-white"><InputError :message="form.errors.agency" /></label>
<label class="text-sm text-gray-300">Número da conta<input v-model="form.account_number" inputmode="numeric" class="mt-1 w-full rounded bg-gray-900 p-2 text-white"><InputError :message="form.errors.account_number" /></label>
<label class="text-sm text-gray-300">Tipo<select v-model="form.account_type" class="mt-1 w-full rounded bg-gray-900 p-2 text-white"><option value="checking">Conta corrente</option><option value="savings">Poupança</option><option value="investment">Investimento</option><option value="cash">Caixa</option><option value="other">Outra</option></select></label>
<label class="flex items-center gap-2 text-sm text-gray-300"><input v-model="form.is_active" type="checkbox">Conta ativa</label>
<div class="flex justify-end gap-3 md:col-span-2"><Link :href="route('bank-accounts.show', [account.id])" class="rounded border border-gray-600 px-4 py-2 text-gray-300">Cancelar</Link><button :disabled="form.processing" class="rounded bg-indigo-600 px-4 py-2 font-semibold text-white disabled:opacity-50">Salvar alterações</button></div>
</form></ReportSection></ReportPage></AppLayout></template>
