<script setup lang="ts">
import type { BankStatementAccount, BankStatementTransaction } from '@/types/financial/bankStatement';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { route } from 'ziggy-js';

const props = defineProps<{
    transaction: BankStatementTransaction;
    bankAccount: BankStatementAccount;
    invoices: Array<Record<string, any>>;
}>();

const form = useForm({ credit_card_invoice_id: '' });
const movementAmount = computed(() => Math.abs(props.transaction.amount_cents));
const eligibleInvoices = computed(() => props.invoices.filter((invoice) => invoice.balance_cents >= movementAmount.value));

function submit() {
    if (!props.transaction.journal_entry_id || !form.credit_card_invoice_id) return;
    form.post(route('bank-accounts.statement.link-credit-card-payment', [props.bankAccount.id, props.transaction.journal_entry_id]), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="min-w-72 space-y-2">
        <select v-model="form.credit_card_invoice_id" class="w-full rounded-lg border border-gray-700 bg-gray-950 px-2 py-2 text-sm text-white">
            <option value="">Selecione cartão e fatura...</option>
            <option v-for="invoice in eligibleInvoices" :key="invoice.id" :value="invoice.id">
                {{ invoice.credit_card.name }} · {{ invoice.reference }} · vence {{ formatDate(invoice.due_at) }} · saldo {{ formatCurrency(invoice.balance_cents) }}
            </option>
        </select>
        <button type="button" :disabled="!form.credit_card_invoice_id || form.processing" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50" @click="submit">
            Vincular pagamento
        </button>
        <p v-if="eligibleInvoices.length === 0" class="text-xs text-amber-300">Nenhuma fatura possui saldo suficiente para este pagamento.</p>
        <p v-if="form.errors.credit_card_invoice_id" class="text-xs text-red-300">{{ form.errors.credit_card_invoice_id }}</p>
    </div>
</template>
