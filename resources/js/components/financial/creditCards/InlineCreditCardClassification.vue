<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
const props = defineProps<{ creditCardId: number; itemId: number; kind: 'purchase' | 'installment_plan'; currentAccountId?: number | null; suspenseAccountId: number; accounts: Array<Record<string, any>> }>();
const form = useForm({ account_id: props.currentAccountId === props.suspenseAccountId ? '' : (props.currentAccountId ?? '') });
function submit() {
    if (!form.account_id) return;
    const installment = props.kind === 'installment_plan';
    form.transform((data) => installment ? { classification_account_id: data.account_id } : { chart_of_account_id: data.account_id })
        .post(route(installment ? 'credit-cards.installment-plans.classify' : 'credit-cards.transactions.classify', [props.creditCardId, props.itemId]), { preserveScroll: true });
}
</script>
<template>
    <select v-model="form.account_id" class="min-w-56 rounded border border-amber-600/40 bg-gray-950 px-2 py-1 text-xs text-amber-200" @change="submit">
        <option value="">A classificar</option>
        <option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.label }}</option>
    </select>
</template>
