<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import BankAccountCreateForm from '@/components/financial/bankAccounts/BankAccountCreateForm.vue'
import ReportPage from '@/components/reports/ReportPage.vue'
import ReportSection from '@/components/reports/ReportSection.vue'
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { todayLocal } from '@/lib/date'
import { onlyNumbers, moneyToCents, formatMoneyInput } from '@/lib/input'

const props = defineProps({
    wallet: Object,
    bankAccounts: {
        type: Array,
        default: () => [],
    },
})

const form = useForm({
    name: '',
    bank_code: '',
    agency: '',
    account_number: '',
    account_type: 'checking',
    opening_balance: 'R$ 0,00',
    opening_balance_cents: 0,
    opening_balance_date: todayLocal(),
})

const normalizedName = computed(() => form.name.trim().toLowerCase())

const isDuplicateName = computed(() => {
    if (!normalizedName.value) return false

    return props.bankAccounts.some(account =>
        account.name?.trim().toLowerCase() === normalizedName.value,
    )
})

const isDuplicateBankAccount = computed(() => {
    if (!form.bank_code || !form.agency || !form.account_number) return false

    return props.bankAccounts.some(account =>
        String(account.bank_code || '') === String(form.bank_code) &&
        String(account.agency || '') === String(form.agency) &&
        String(account.account_number || '') === String(form.account_number),
    )
})

const canSubmit = computed(() => {
    return form.name.trim().length > 0 &&
        !isDuplicateName.value &&
        !isDuplicateBankAccount.value &&
        !form.processing
})

function updateOnlyNumbers(field, event) {
    const value = onlyNumbers(event.target.value)

    form[field] = value
    event.target.value = value
}

function updateOpeningBalance(event) {
    form.opening_balance = formatMoneyInput(event.target.value)
    form.opening_balance_cents = moneyToCents(form.opening_balance)
}

function submit() {
    if (!canSubmit.value) return

    form.post(route('bank-accounts.store'))
}
</script>

<template>
    <AppLayout title="Nova Conta Bancária">
        <ReportPage
            title="Nova Conta Bancária"
            :subtitle="wallet?.name"
        >
            <ReportSection>
                <template #header>
                    <div>
                        <h2 class="text-lg font-bold text-white">
                            Dados da conta
                        </h2>

                        <p class="mt-1 text-sm text-gray-400">
                            Ao salvar, o sistema criará automaticamente uma subconta em
                            <strong>1.1.2 Bancos</strong>.
                        </p>
                    </div>
                </template>

                <BankAccountCreateForm
                    :form="form"
                    :is-duplicate-name="isDuplicateName"
                    :is-duplicate-bank-account="isDuplicateBankAccount"
                    :can-submit="canSubmit"
                    @submit="submit"
                    @update-only-numbers="updateOnlyNumbers"
                    @update-opening-balance="updateOpeningBalance"
                />
            </ReportSection>
        </ReportPage>
    </AppLayout>
</template>
