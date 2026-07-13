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
    chart_of_account_id: string;
    should_post: boolean;
};

const form = useForm<InlineClassificationForm>({
    chart_of_account_id: props.transaction.classification_account_id ? String(props.transaction.classification_account_id) : '',
    should_post: false,
});

const hasValidCurrentAccount = computed(() =>
    props.classificationAccounts.some((account) => account.id === props.transaction.classification_account_id),
);

function syncCurrentAccount() {
    form.chart_of_account_id = props.transaction.classification_account_id ? String(props.transaction.classification_account_id) : '';
}

function submit(shouldPost: boolean) {
    if (!props.transaction.journal_entry_id || !form.chart_of_account_id || form.processing) return;

    form.should_post = shouldPost;
    form.clearErrors();
    form.post(route('bank-accounts.statement.classify', [props.bankAccount.id, props.transaction.journal_entry_id]), {
        preserveScroll: true,
        onError: syncCurrentAccount,
        onFinish: () => {
            form.should_post = false;
        },
    });
}

function saveClassification() {
    if (Number(form.chart_of_account_id) === props.transaction.classification_account_id) return;

    submit(false);
}

watch(() => props.transaction.classification_account_id, syncCurrentAccount);
</script>

<template>
    <div class="min-w-56 space-y-1.5">
        <select
            :id="`ofx-classification-account-${transaction.id}`"
            v-model="form.chart_of_account_id"
            :disabled="form.processing"
            class="w-full rounded-lg border border-gray-700 bg-gray-950 px-3 py-2 text-sm text-white disabled:cursor-wait disabled:opacity-60"
            aria-label="Conta contábil de classificação"
            @change="saveClassification"
        >
            <option value="" disabled>Selecionar conta...</option>
            <option v-for="account in classificationAccounts" :key="account.id" :value="String(account.id)">
                {{ account.code }} - {{ account.name }}
            </option>
        </select>

        <div class="flex min-h-5 items-center justify-between gap-3 text-xs">
            <span v-if="form.processing" class="text-gray-400">Salvando...</span>
            <InputError v-else :message="form.errors.chart_of_account_id" />

            <button
                v-if="transaction.classification_account_id && hasValidCurrentAccount"
                type="button"
                :disabled="form.processing"
                class="ml-auto font-semibold text-indigo-300 hover:text-indigo-200 disabled:opacity-50"
                @click="submit(true)"
            >
                Postar
            </button>
        </div>
    </div>
</template>
