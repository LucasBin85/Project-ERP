<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { formatCurrency, formatDate } from '@/lib/formatters';
import type { BankStatementAccount, BankStatementTransaction } from '@/types/financial/bankStatement';
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { route } from 'ziggy-js';

interface PayableCandidate {
    id: number;
    payee_name: string;
    description: string;
    due_date: string;
    amount_cents: number;
    proximity_days: number;
    expense_account: {
        id: number;
        code: string;
        name: string;
    };
    show_url: string;
}

const props = defineProps<{
    transaction: BankStatementTransaction;
    bankAccount: BankStatementAccount;
}>();

const expanded = ref(false);
const loading = ref(false);
const loaded = ref(false);
const candidates = ref<PayableCandidate[]>([]);
const loadError = ref<string | null>(null);
const form = useForm({
    account_payable_id: '',
});

async function loadCandidates() {
    if (!props.transaction.journal_entry_id || loading.value || loaded.value) return;

    loading.value = true;
    loadError.value = null;

    try {
        const response = await fetch(
            route('bank-accounts.statement.payable-candidates', [props.bankAccount.id, props.transaction.journal_entry_id]),
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );
        const payload = await response.json();

        if (!response.ok) {
            const validationErrors = payload.errors ? Object.values(payload.errors).flat() : [];
            throw new Error(String(validationErrors[0] ?? payload.message ?? 'Não foi possível buscar contas a pagar.'));
        }

        candidates.value = payload.candidates ?? [];
        loaded.value = true;
    } catch (error) {
        loadError.value = error instanceof Error ? error.message : 'Não foi possível buscar contas a pagar.';
    } finally {
        loading.value = false;
    }
}

function toggleCandidates() {
    expanded.value = !expanded.value;

    if (expanded.value) void loadCandidates();
}

function linkPayable() {
    if (!props.transaction.journal_entry_id || !form.account_payable_id || form.processing) return;

    form.post(route('bank-accounts.statement.link-payable', [props.bankAccount.id, props.transaction.journal_entry_id]), {
        preserveScroll: true,
        onSuccess: () => {
            expanded.value = false;
        },
    });
}
</script>

<template>
    <div v-if="transaction.linked_account_payable" class="min-w-60 space-y-1.5">
        <span class="inline-flex rounded bg-green-950 px-2 py-1 text-xs font-semibold text-green-300">Conta a pagar vinculada</span>
        <Link :href="transaction.linked_account_payable.show_url" class="block text-sm font-semibold text-indigo-300 hover:text-indigo-200">
            {{ transaction.linked_account_payable.description }}
        </Link>
        <p class="text-xs text-gray-500">{{ transaction.linked_account_payable.payee_name }}</p>
        <p class="text-xs text-gray-400">Classificação: {{ transaction.classification_label }}</p>
    </div>

    <div v-else-if="transaction.can_link_account_payable" class="min-w-72 space-y-2">
        <button
            type="button"
            class="rounded-lg border border-indigo-500/60 px-3 py-2 text-sm font-semibold text-indigo-200 hover:bg-indigo-950/40"
            :aria-expanded="expanded"
            @click="toggleCandidates"
        >
            Vincular conta a pagar
        </button>

        <div v-if="expanded" class="space-y-2 rounded-xl border border-gray-700 bg-gray-950 p-3">
            <p v-if="loading" class="text-xs text-gray-400">Buscando títulos pendentes...</p>
            <p v-else-if="loadError" class="text-xs text-red-300">{{ loadError }}</p>
            <p v-else-if="candidates.length === 0" class="text-xs text-amber-300">Nenhum título pendente com o mesmo valor.</p>

            <template v-else>
                <select
                    v-model="form.account_payable_id"
                    :disabled="form.processing"
                    class="w-full rounded-lg border border-gray-700 bg-black px-3 py-2 text-sm text-white disabled:opacity-60"
                    aria-label="Conta a pagar para vincular"
                >
                    <option value="" disabled>Selecione explicitamente...</option>
                    <option v-for="candidate in candidates" :key="candidate.id" :value="String(candidate.id)">
                        {{ candidate.payee_name }} · {{ candidate.description }} · {{ formatDate(candidate.due_date) }} ·
                        {{ formatCurrency(candidate.amount_cents) }}
                    </option>
                </select>

                <button
                    type="button"
                    :disabled="form.processing || !form.account_payable_id"
                    class="w-full rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                    @click="linkPayable"
                >
                    {{ form.processing ? 'Vinculando...' : 'Confirmar vínculo' }}
                </button>
            </template>

            <InputError :message="form.errors.account_payable_id || Object.values(form.errors)[0]" />
        </div>
    </div>
</template>
