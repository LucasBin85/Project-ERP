<script setup lang="ts">
import type { BankStatementAccount, BankStatementTransaction } from '@/types/financial/bankStatement';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps<{ transaction: BankStatementTransaction; bankAccount: BankStatementAccount }>();

function apply() {
    const suggestion = props.transaction.classification_suggestion;
    if (!suggestion?.rule_id) return;
    router.post(route('bank-accounts.statement.apply-suggestion', [props.bankAccount.id, props.transaction.journal_entry_id]), { rule_id: suggestion.rule_id }, { preserveScroll: true });
}
</script>

<template>
    <div v-if="transaction.classification_suggestion" class="mb-2 rounded-lg border border-indigo-500/30 bg-indigo-950/30 p-2 text-xs">
        <p v-if="transaction.classification_suggestion.status === 'ambiguous'" class="text-amber-300">Há regras empatadas. Revise as prioridades para receber uma sugestão.</p>
        <template v-else>
            <p class="font-semibold text-indigo-200">Sugestão: {{ transaction.classification_suggestion.operation_type }} → {{ transaction.classification_suggestion.target_label }}</p>
            <p class="mt-1 text-gray-400">Regra: {{ transaction.classification_suggestion.rule_name }}</p>
            <button v-if="transaction.classification_suggestion.can_apply" type="button" class="mt-2 rounded border border-indigo-400 px-2 py-1 font-semibold text-indigo-200 hover:bg-indigo-900" @click="apply">Aplicar sugestão</button>
            <p v-else class="mt-1 text-gray-400">Selecione o título correspondente para concluir.</p>
        </template>
    </div>
</template>
