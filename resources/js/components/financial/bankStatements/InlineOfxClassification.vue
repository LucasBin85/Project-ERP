<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import type { BankStatementAccount, BankStatementClassificationAccount, BankStatementTransaction } from '@/types/financial/bankStatement';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { route } from 'ziggy-js';

const props = defineProps<{
    transaction: BankStatementTransaction;
    bankAccount: BankStatementAccount;
    classificationAccounts: BankStatementClassificationAccount[];
}>();

type InlineClassificationForm = {
    operation_type: string;
    chart_of_account_id: string;
    should_post: boolean;
};

const form = useForm<InlineClassificationForm>({
    operation_type: props.transaction.operation_type ?? '',
    chart_of_account_id: props.transaction.classification_account_id ? String(props.transaction.classification_account_id) : '',
    should_post: false,
});

const eligibleAccounts = computed(() =>
    props.transaction.operation_type
        ? props.classificationAccounts.filter((account) => account.allowed_operation_types.includes(props.transaction.operation_type!))
        : [],
);

const hasValidCurrentAccount = computed(() => eligibleAccounts.value.some((account) => account.id === props.transaction.classification_account_id));
const transferLabel = computed(() => props.transaction.type === 'outflow' ? 'Conta de destino' : 'Conta de origem');

function accountLabel(account: BankStatementClassificationAccount): string {
    if (props.transaction.operation_type !== 'transfer' || !account.bank_account) return `${account.code} - ${account.name}`;
    const details = [account.bank_account.bank_name, account.bank_account.agency, account.bank_account.account_number].filter(Boolean).join(' · ');
    return `${account.bank_account.name}${details ? ` (${details})` : ''}`;
}

function syncFromTransaction() {
    form.operation_type = props.transaction.operation_type ?? '';
    form.chart_of_account_id = props.transaction.classification_account_id ? String(props.transaction.classification_account_id) : '';
}

function submit() {
    if (!props.transaction.journal_entry_id || !form.operation_type || !form.chart_of_account_id || form.processing) return;

    form.should_post = false;
    form.clearErrors();
    form.post(route('bank-accounts.statement.classify', [props.bankAccount.id, props.transaction.journal_entry_id]), {
        preserveScroll: true,
        onError: syncFromTransaction,
        onFinish: () => {
            form.should_post = false;
        },
    });
}

function saveClassification() {
    if (Number(form.chart_of_account_id) === props.transaction.classification_account_id) return;

    submit();
}

watch(() => [props.transaction.operation_type, props.transaction.classification_account_id], syncFromTransaction);
</script>

<template>
    <div class="min-w-60 space-y-1.5">
        <p v-if="!transaction.operation_type" class="rounded bg-gray-900 px-2 py-2 text-xs text-gray-400">Selecione primeiro o tipo de operação.</p>

        <p v-else-if="eligibleAccounts.length === 0" class="rounded bg-amber-950/40 px-2 py-2 text-xs text-amber-300">
            {{ transaction.operation_type === 'transfer' ? 'Cadastre outra conta bancária para registrar transferência.' : 'Este tipo está preparado para integração futura. O lançamento permanece em “A classificar”.' }}
        </p>

        <select
            v-else
            :id="`ofx-classification-account-${transaction.id}`"
            v-model="form.chart_of_account_id"
            :disabled="form.processing || !transaction.can_classify"
            class="w-full rounded-lg border border-gray-700 bg-gray-950 px-3 py-2 text-sm text-white disabled:cursor-not-allowed disabled:opacity-60"
            :aria-label="transaction.operation_type === 'transfer' ? transferLabel : 'Conta contábil de classificação'"
            @change="saveClassification"
        >
            <option value="" disabled>{{ transaction.operation_type === 'transfer' ? transferLabel : 'Selecionar conta...' }}</option>
            <option v-for="account in eligibleAccounts" :key="account.id" :value="String(account.id)">{{ accountLabel(account) }}</option>
        </select>

        <div class="flex min-h-5 items-center gap-3 text-xs">
            <span v-if="form.processing" class="text-gray-400">Salvando...</span>
            <InputError v-else :message="form.errors.chart_of_account_id || form.errors.operation_type" />
            <span v-if="transaction.classification_account_id && hasValidCurrentAccount" class="ml-auto font-semibold text-green-300">
                Classificado
            </span>
        </div>
    </div>
</template>
