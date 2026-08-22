<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { formatCurrency, formatDate } from '@/lib/formatters';
import type { BankStatementAccount, BankStatementTransaction } from '@/types/financial/bankStatement';
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { route } from 'ziggy-js';
type Candidate = { id: number; payee_name: string; description: string; due_date: string; amount_cents: number };
type Recurring = {
    expectation_id: number;
    period_date: string;
    description: string;
    expected_due_date: string;
    expected_amount_cents: number | null;
    statement_amount_cents: number;
    amount_difference_cents: number | null;
    counterparty: { name: string } | null;
};
const p = defineProps<{ transaction: BankStatementTransaction; bankAccount: BankStatementAccount; suppliers: Array<{ id: number; name: string }> }>(),
    open = ref(false),
    loading = ref(false),
    error = ref<string | null>(null),
    candidates = ref<Candidate[]>([]),
    recurring = ref<Recurring[]>([]),
    selected = ref<Recurring | null>(null);
const linkForm = useForm({ account_payable_id: '' }),
    createForm = useForm({ supplier_id: '', description: p.transaction.description ?? '', notes: '' }),
    recurringForm = useForm({ recurring_financial_expectation_id: '', period_date: '', due_date: '', notes: '' });
async function load() {
    if (!p.transaction.journal_entry_id) return;
    loading.value = true;
    try {
        const r = await fetch(route('bank-accounts.statement.payable-candidates', [p.bankAccount.id, p.transaction.journal_entry_id]), {
                headers: { Accept: 'application/json' },
            }),
            j = await r.json();
        if (!r.ok) throw new Error(String(j.errors ? Object.values(j.errors).flat()[0] : j.message));
        candidates.value = j.candidates ?? [];
        recurring.value = j.recurring_candidates ?? [];
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Falha ao buscar candidatos.';
    } finally {
        loading.value = false;
    }
}
function toggle() {
    open.value = !open.value;
    if (open.value) void load();
}
function choose(i: Recurring) {
    selected.value = i;
    recurringForm.recurring_financial_expectation_id = String(i.expectation_id);
    recurringForm.period_date = i.period_date;
    recurringForm.due_date = i.expected_due_date;
    recurringForm.clearErrors();
}
function link() {
    linkForm.post(route('bank-accounts.statement.link-payable', [p.bankAccount.id, p.transaction.journal_entry_id]), { preserveScroll: true });
}
function confirm() {
    recurringForm.post(route('bank-accounts.statement.confirm-link-recurring-payable', [p.bankAccount.id, p.transaction.journal_entry_id]), {
        preserveScroll: true,
    });
}
function create() {
    createForm.post(route('bank-accounts.statement.create-link-payable', [p.bankAccount.id, p.transaction.journal_entry_id]), {
        preserveScroll: true,
    });
}
</script>
<template>
    <div v-if="transaction.linked_account_payable">
        <Link :href="transaction.linked_account_payable.show_url" class="text-indigo-300"
            >Conta a pagar vinculada: {{ transaction.linked_account_payable.description }}</Link
        >
    </div>
    <div v-else-if="transaction.can_link_account_payable">
        <button class="rounded border border-indigo-500 px-3 py-2" @click="toggle">Criar ou vincular título a pagar</button>
        <div v-if="open" class="fixed inset-0 z-40 flex items-center justify-center bg-black/70 p-4" @click.self="open = false">
            <div class="max-h-[90vh] w-full max-w-lg space-y-4 overflow-y-auto rounded-xl bg-gray-950 p-5">
                <div class="flex justify-between"><b>Conta a pagar do extrato</b><button @click="open = false">Fechar</button></div>
                <p>Valor: {{ formatCurrency(Math.abs(transaction.amount_cents)) }} · Data: {{ formatDate(transaction.date) }}</p>
                <p v-if="loading">Buscando...</p>
                <p v-if="error" class="text-red-300">{{ error }}</p>
                <section v-if="candidates.length" class="space-y-2">
                    <h3>Títulos pendentes já existentes</h3>
                    <select v-model="linkForm.account_payable_id" class="w-full bg-black p-2">
                        <option value="" disabled>Selecione...</option>
                        <option v-for="i in candidates" :key="i.id" :value="String(i.id)">
                            {{ i.payee_name }} · {{ i.description }} · {{ formatCurrency(i.amount_cents) }}
                        </option></select
                    ><button class="w-full bg-indigo-600 p-2" @click="link">Confirmar vínculo</button
                    ><InputError :message="Object.values(linkForm.errors)[0]" />
                </section>
                <section v-if="recurring.length" class="space-y-2 border-t pt-3">
                    <h3>Recorrências esperadas</h3>
                    <div v-for="i in recurring" :key="`${i.expectation_id}-${i.period_date}`" class="border p-3 text-xs">
                        <b>{{ i.description }} · {{ i.counterparty?.name }}</b>
                        <p>Vencimento previsto: {{ formatDate(i.expected_due_date) }}</p>
                        <p>
                            Previsto: {{ i.expected_amount_cents === null ? 'Sem estimativa' : formatCurrency(i.expected_amount_cents) }} · Real:
                            {{ formatCurrency(i.statement_amount_cents) }}
                        </p>
                        <p v-if="i.amount_difference_cents !== null">
                            Diferença: {{ i.amount_difference_cents >= 0 ? '+' : '-' }}{{ formatCurrency(Math.abs(i.amount_difference_cents)) }}
                        </p>
                        <button class="text-indigo-300" @click="choose(i)">Usar esta recorrência</button>
                    </div>
                    <div v-if="selected" class="space-y-2 bg-gray-900 p-3">
                        <p>
                            Competência: {{ selected.period_date.slice(0, 7) }} · Valor readonly:
                            {{ formatCurrency(selected.statement_amount_cents) }}
                        </p>
                        <input v-model="recurringForm.due_date" type="date" class="w-full bg-black p-2" /><textarea
                            v-model="recurringForm.notes"
                            class="w-full bg-black p-2"
                        /><button class="w-full bg-indigo-600 p-2" @click="confirm">Confirmar recorrência e vincular</button
                        ><button @click="selected = null">Cancelar</button><InputError :message="Object.values(recurringForm.errors)[0]" />
                    </div>
                </section>
                <section class="space-y-2 border-t pt-3">
                    <h3>Criar novo título</h3>
                    <select v-model="createForm.supplier_id" class="w-full bg-black p-2">
                        <option value="">Fornecedor...</option>
                        <option v-for="i in suppliers" :key="i.id" :value="String(i.id)">{{ i.name }}</option></select
                    ><input v-model="createForm.description" class="w-full bg-black p-2" /><textarea
                        v-model="createForm.notes"
                        class="w-full bg-black p-2"
                    /><button class="w-full bg-indigo-600 p-2" @click="create">Criar título e vincular</button
                    ><InputError :message="Object.values(createForm.errors)[0]" />
                </section>
            </div>
        </div>
    </div>
</template>
