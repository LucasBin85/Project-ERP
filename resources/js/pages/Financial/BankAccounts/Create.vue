<script setup>
import BankAccountCreateForm from '@/components/financial/bankAccounts/BankAccountCreateForm.vue';
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import { useBankAccountCreateForm } from '@/composables/financial/useBankAccountCreateForm';
import AppLayout from '@/layouts/AppLayout.vue';
import { route } from 'ziggy-js';

const props = defineProps({
    wallet: Object,
    bankAccounts: {
        type: Array,
        default: () => [],
    },
    banks: {
        type: Array,
        default: () => [],
    },
});

const bankAccount = useBankAccountCreateForm(props.bankAccounts);

function submit() {
    if (!bankAccount.canSubmit.value) return;

    bankAccount.form.post(route('bank-accounts.store'));
}
</script>

<template>
    <AppLayout title="Nova Conta Bancária">
        <ReportPage title="Nova Conta Bancária" :subtitle="wallet?.name">
            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">Dados da conta</h2>

                        <p class="mt-1 text-sm text-gray-400">
                            Ao salvar, o sistema criará automaticamente uma subconta em
                            <strong>1.1.2 Bancos</strong>.
                        </p>
                    </div>
                </template>

                <BankAccountCreateForm
                    :form="bankAccount.form"
                    :banks="banks"
                    :is-duplicate-name="bankAccount.isDuplicateName.value"
                    :is-duplicate-bank-account="bankAccount.isDuplicateBankAccount.value"
                    :can-submit="bankAccount.canSubmit.value"
                    @submit="submit"
                    @update-bank-id="bankAccount.form.bank_id = $event"
                    @update-name="bankAccount.form.name = $event"
                    @update-account-type="bankAccount.form.account_type = $event"
                    @update-opening-balance-date="bankAccount.form.opening_balance_date = $event"
                    @update-only-numbers="bankAccount.updateOnlyNumbers"
                    @update-opening-balance="bankAccount.updateOpeningBalance"
                />
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
