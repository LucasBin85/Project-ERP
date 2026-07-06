import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { todayLocal } from '@/lib/date'
import { formatMoneyInput, moneyToCents, onlyNumbers } from '@/lib/input'

export function useBankAccountCreate(bankAccounts = []) {
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

        return bankAccounts.some(account =>
            account.name?.trim().toLowerCase() === normalizedName.value,
        )
    })

    const isDuplicateBankAccount = computed(() => {
        if (!form.bank_code || !form.agency || !form.account_number) return false

        return bankAccounts.some(account =>
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

    return {
        form,
        isDuplicateName,
        isDuplicateBankAccount,
        canSubmit,
        updateOnlyNumbers,
        updateOpeningBalance,
        submit,
    }
}
