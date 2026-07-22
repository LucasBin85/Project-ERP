<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import type { BankStatementAccount, BankStatementTransaction } from '@/types/financial/bankStatement';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { route } from 'ziggy-js';

const props = defineProps<{
    transaction: BankStatementTransaction;
    bankAccount: BankStatementAccount;
}>();

const initialCandidateId = props.transaction.match_candidates.length === 1 ? props.transaction.match_candidates[0].journal_line_id : null;
const form = useForm({
    action: 'link' as 'link' | 'keep',
    journal_line_id: initialCandidateId as number | null,
});

const selectedCandidate = computed(() =>
    props.transaction.match_candidates.find((candidate) => candidate.journal_line_id === Number(form.journal_line_id)),
);

function resolve(action: 'link' | 'keep') {
    if (!props.transaction.journal_entry_id || form.processing) return;
    if (action === 'link' && !form.journal_line_id) return;

    form.action = action;
    form.clearErrors();
    form.post(route('bank-accounts.statement.resolve-match', [props.bankAccount.id, props.transaction.journal_entry_id]), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="min-w-72 space-y-2 rounded-lg border border-amber-500/30 bg-amber-950/30 p-3 text-xs">
        <p class="font-semibold text-amber-200">
            {{ transaction.match_status === 'unique' ? 'Possível vínculo encontrado' : 'Vínculo ambíguo' }}
        </p>

        <select
            v-if="transaction.match_candidates.length > 1"
            v-model="form.journal_line_id"
            :disabled="form.processing"
            class="w-full rounded border border-amber-700/60 bg-gray-950 px-2 py-1.5 text-white"
        >
            <option :value="null" disabled>Selecionar lançamento...</option>
            <option v-for="candidate in transaction.match_candidates" :key="candidate.journal_line_id" :value="candidate.journal_line_id">
                #{{ candidate.journal_entry_id }} · {{ candidate.description || 'Lançamento manual' }}
            </option>
        </select>

        <p v-else-if="selectedCandidate" class="text-amber-100">
            #{{ selectedCandidate.journal_entry_id }} · {{ selectedCandidate.description || 'Lançamento manual' }}
        </p>

        <div class="flex flex-wrap gap-2">
            <button
                type="button"
                :disabled="form.processing || !form.journal_line_id"
                class="rounded bg-amber-600 px-2.5 py-1.5 font-semibold text-white hover:bg-amber-500 disabled:opacity-50"
                @click="resolve('link')"
            >
                Vincular
            </button>
            <button
                type="button"
                :disabled="form.processing"
                class="rounded border border-amber-700 px-2.5 py-1.5 font-semibold text-amber-100 hover:bg-amber-900 disabled:opacity-50"
                @click="resolve('keep')"
            >
                Manter lançamento importado
            </button>
        </div>

        <InputError :message="form.errors.action || form.errors.journal_line_id" />
    </div>
</template>
