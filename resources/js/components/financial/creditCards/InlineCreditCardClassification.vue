<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
const props = defineProps<{ creditCardId: number; transactionId: number; accounts: Array<Record<string, any>> }>();
const form = useForm({ chart_of_account_id: '' });
function submit() {
    if (!form.chart_of_account_id) return;
    form.post(route('credit-cards.transactions.classify', [props.creditCardId, props.transactionId]), { preserveScroll: true });
}
</script>
<template>
    <select v-model="form.chart_of_account_id" class="min-w-56 rounded border border-amber-600/40 bg-gray-950 px-2 py-1 text-xs text-amber-200" @change="submit">
        <option value="">A classificar...</option>
        <option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.label }}</option>
    </select>
</template>
