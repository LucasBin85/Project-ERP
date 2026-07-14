import { todayLocal } from '@/lib/date';
import { formatMoneyInput, moneyToCents, onlyNumbers } from '@/lib/input';
import type { BankAccountCreateFormData, ExistingBankAccount } from '@/types/financial/bankAccount';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { route } from 'ziggy-js';

export function useBankAccountCreate(bankAccounts: ExistingBankAccount[] = []) {
    const form = useForm<BankAccountCreateFormData>({
        bank_id: null,
        name: '',
        agency: '',
        account_number: '',
        account_type: 'checking',
        opening_balance: 'R$ 0,00',
        opening_balance_cents: 0,
        opening_balance_date: todayLocal(),
    });

    const normalizedName = computed(() => form.name.trim().toLowerCase());

    const isDuplicateName = computed(() => {
        if (!normalizedName.value) return false;

        return bankAccounts.some((account) => account.name?.trim().toLowerCase() === normalizedName.value);
    });

    const isDuplicateBankAccount = computed(() => {
        if (!form.bank_id || !form.agency || !form.account_number) return false;

        return bankAccounts.some(
            (account) =>
                account.bank_id === form.bank_id &&
                String(account.agency || '') === String(form.agency) &&
                String(account.account_number || '') === String(form.account_number),
        );
    });

    const canSubmit = computed(() => {
        return form.name.trim().length > 0 && form.bank_id !== null && !isDuplicateName.value && !isDuplicateBankAccount.value && !form.processing;
    });

    function updateOnlyNumbers(field: 'agency' | 'account_number', event: Event) {
        const target = event.target as HTMLInputElement;
        const value = onlyNumbers(target.value);

        form[field] = value;
        target.value = value;
    }

    function updateOpeningBalance(event: Event) {
        const target = event.target as HTMLInputElement;

        form.opening_balance = formatMoneyInput(target.value);
        form.opening_balance_cents = moneyToCents(form.opening_balance);
    }

    function submit() {
        if (!canSubmit.value) return;

        form.post(route('bank-accounts.store'));
    }

    return {
        form,
        isDuplicateName,
        isDuplicateBankAccount,
        canSubmit,
        updateOnlyNumbers,
        updateOpeningBalance,
        submit,
    };
}
