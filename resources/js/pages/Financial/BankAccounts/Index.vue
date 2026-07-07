<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import BankAccountsTable from '@/components/financial/bankAccounts/BankAccountsTable.vue'
import ReportPage from '@/components/reports/ReportPage.vue'
import ReportSection from '@/components/reports/ReportSection.vue'
import { Link } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useBankAccountsIndex } from '@/composables/financial/useBankAccountsIndex'

defineProps({
    wallet: Object,
    bankAccounts: Array,
})

const bankAccountsView = useBankAccountsIndex()
</script>

<template>
    <AppLayout title="Contas Bancárias">
        <ReportPage
            title="Contas Bancárias"
            :subtitle="wallet.name"
        >
            <div class="flex justify-end">
                <Link
                    :href="route('bank-accounts.create')"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500"
                >
                    Nova conta bancária
                </Link>
            </div>

            <ReportSection>
                <BankAccountsTable
                    :bank-accounts="bankAccounts"
                    :format-type="bankAccountsView.formatType"
                />
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
