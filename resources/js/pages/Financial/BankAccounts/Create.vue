<script setup lang="ts">
import BankAccountCreateForm from '@/components/financial/bankAccounts/BankAccountCreateForm.vue';
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import { useBankAccountCreateForm } from '@/composables/financial/useBankAccountCreateForm';
import { useBankAccountOfxPreview } from '@/composables/financial/useBankAccountOfxPreview';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BankAccountOfxPreview, BankOption, ExistingBankAccount } from '@/types/financial/bankAccount';
import { route } from 'ziggy-js';

interface WalletSummary {
    id: number;
    name: string;
}

const props = withDefaults(
    defineProps<{
        wallet?: WalletSummary | null;
        bankAccounts?: ExistingBankAccount[];
        banks?: BankOption[];
        bankAccountOfxPreview?: BankAccountOfxPreview | null;
    }>(),
    {
        wallet: null,
        bankAccounts: () => [],
        banks: () => [],
        bankAccountOfxPreview: null,
    },
);

const bankAccount = useBankAccountCreateForm(props.bankAccounts);
const bankAccountOfx = useBankAccountOfxPreview(bankAccount.form);

if (props.bankAccountOfxPreview) {
    bankAccountOfx.applyPreview(props.bankAccountOfxPreview);
}

function submit() {
    if (!bankAccount.canSubmit.value || bankAccountOfx.processing.value) return;

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
                    :can-submit="bankAccount.canSubmit.value && !bankAccountOfx.processing.value"
                    :ofx-processing="bankAccountOfx.processing.value"
                    :ofx-selected-file-name="bankAccountOfx.selectedFileName.value"
                    :ofx-message="bankAccountOfx.message.value"
                    :ofx-warnings="bankAccountOfx.warnings.value"
                    :ofx-error="bankAccountOfx.errorMessage.value"
                    @submit="submit"
                    @select-ofx-file="bankAccountOfx.selectFile"
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
