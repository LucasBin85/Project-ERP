<script setup lang="ts">
import BankTransferCreateForm from '@/components/financial/bankTransfers/BankTransferCreateForm.vue';
import ReportPage from '@/components/reports/ReportPage.vue';
import ReportSection from '@/components/reports/ReportSection.vue';
import { useBankTransferCreateForm } from '@/composables/financial/useBankTransferCreateForm';
import AppLayout from '@/layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps<{
    wallet: Record<string, any>;
    bankAccounts: Array<Record<string, any>>;
    selectedFromBankAccountId?: number | null;
}>();

const bankTransfer = useBankTransferCreateForm(props.selectedFromBankAccountId ?? null);

function submit() {
    if (!bankTransfer.canSubmit.value) {
        return;
    }

    bankTransfer.form.post(route('bank-transfers.store'));
}
</script>

<template>
    <AppLayout title="Nova Transferência">
        <ReportPage title="Nova Transferência" :subtitle="props.wallet?.name">
            <div v-if="selectedFromBankAccountId" class="flex justify-end">
                <Link
                    :href="route('bank-accounts.show', [selectedFromBankAccountId])"
                    class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800"
                >
                    Voltar para a conta bancária
                </Link>
            </div>

            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">
                            Transferência entre contas bancárias
                        </h2>

                        <p class="mt-1 text-sm text-gray-400">
                            Informe origem, destino e valor. O lançamento contábil será gerado automaticamente.
                        </p>
                    </div>
                </template>

                <div
                    v-if="bankAccounts.length < 2"
                    class="m-6 rounded-lg border border-yellow-900 bg-yellow-950/30 p-4 text-sm text-yellow-200"
                >
                    Cadastre pelo menos duas contas bancárias ativas para registrar uma transferência.
                </div>

                <BankTransferCreateForm
                    v-else
                    :form="bankTransfer.form"
                    :bank-accounts="bankAccounts"
                    :can-submit="bankTransfer.canSubmit.value"
                    @submit="submit"
                    @update-amount="bankTransfer.updateAmount"
                />
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
