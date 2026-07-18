<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import type { BankStatementAccount, BankStatementTransaction } from '@/types/financial/bankStatement';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps<{ transaction: BankStatementTransaction; bankAccount: BankStatementAccount }>();
const candidates = props.transaction.transfer?.match_candidates ?? [];
const form = useForm({ audit_id: candidates.length === 1 ? candidates[0].audit_id : null as number | null });

function merge() {
    if (!props.transaction.journal_entry_id || !form.audit_id || form.processing) return;
    form.post(route('bank-accounts.statement.merge-transfer', [props.bankAccount.id, props.transaction.journal_entry_id]), { preserveScroll: true });
}
</script>

<template>
    <div class="min-w-72 space-y-2 rounded-lg border border-amber-500/30 bg-amber-950/30 p-3 text-xs">
        <p class="font-semibold text-amber-200">
            {{ transaction.transfer?.match_status === 'unique' ? 'Foi encontrada uma possível outra ponta desta transferência.' : 'Múltiplas correspondências encontradas' }}
        </p>
        <select v-if="candidates.length > 1" v-model="form.audit_id" class="w-full rounded border border-amber-700/60 bg-gray-950 px-2 py-1.5 text-white">
            <option :value="null" disabled>Selecionar outra ponta...</option>
            <option v-for="candidate in candidates" :key="candidate.audit_id" :value="candidate.audit_id">
                #{{ candidate.journal_entry_id }} · {{ candidate.counterpart_name }} · {{ candidate.description }}
            </option>
        </select>
        <button type="button" :disabled="!form.audit_id || form.processing" class="rounded bg-amber-600 px-2.5 py-1.5 font-semibold text-white disabled:opacity-50" @click="merge">
            {{ candidates.length === 1 ? 'Vincular transferência encontrada' : 'Vincular transferências' }}
        </button>
        <InputError :message="form.errors.audit_id || form.errors.transfer_match" />
    </div>
</template>
