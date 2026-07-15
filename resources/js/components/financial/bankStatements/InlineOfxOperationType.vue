<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import type { BankStatementAccount, BankStatementTransaction } from '@/types/financial/bankStatement';
import type { FinancialOperationTypeOption } from '@/types/financial/operationType';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { route } from 'ziggy-js';

const props = defineProps<{
    transaction: BankStatementTransaction;
    bankAccount: BankStatementAccount;
    operationTypes: FinancialOperationTypeOption[];
}>();

const form = useForm({
    operation_type: props.transaction.operation_type ?? '',
    chart_of_account_id: null as null,
    should_post: false,
});

const allowedOperationTypes = computed(() =>
    props.operationTypes.filter((operationType) => props.transaction.allowed_operation_types.includes(operationType.code)),
);

function optionLabel(operationType: FinancialOperationTypeOption): string {
    if (operationType.code === 'investment') {
        return props.transaction.type === 'inflow' ? 'Resgate de investimento' : 'Investimento / aplicação';
    }

    if (operationType.code === 'other' && props.transaction.type === 'inflow') {
        return 'Reembolso, estorno ou outro';
    }

    return operationType.label;
}

function saveOperationType() {
    if (!props.transaction.journal_entry_id || !form.operation_type || form.processing) return;

    form.clearErrors();
    form.post(route('bank-accounts.statement.classify', [props.bankAccount.id, props.transaction.journal_entry_id]), {
        preserveScroll: true,
        onError: () => {
            form.operation_type = props.transaction.operation_type ?? '';
        },
    });
}

watch(
    () => props.transaction.operation_type,
    (operationType) => {
        form.operation_type = operationType ?? '';
    },
);
</script>

<template>
    <div class="min-w-40 space-y-1.5">
        <select
            v-model="form.operation_type"
            :disabled="form.processing || !transaction.can_edit_operation_type"
            class="w-full rounded-lg border border-gray-700 bg-gray-950 px-2 py-2 text-sm text-white disabled:cursor-not-allowed disabled:opacity-60"
            aria-label="Tipo de operação"
            @change="saveOperationType"
        >
            <option value="" disabled>Selecionar tipo...</option>
            <option v-for="operationType in allowedOperationTypes" :key="operationType.code" :value="operationType.code">
                {{ optionLabel(operationType) }}
            </option>
        </select>
        <InputError :message="form.errors.operation_type || form.errors.chart_of_account_id" />
    </div>
</template>
