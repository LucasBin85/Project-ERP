import { formatMoneyInput, moneyToCents } from '@/lib/input';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

export function useBankTransferCreateForm(defaultFromBankAccountId: number | string | null = null) {
    const form = useForm({
        from_bank_account_id: defaultFromBankAccountId ? String(defaultFromBankAccountId) : '',
        to_bank_account_id: '',
        amount: '',
        amount_cents: 0,
        transfer_date: new Date().toISOString().substring(0, 10),
        description: '',
    });

    const canSubmit = computed(() => {
        return Boolean(
            form.from_bank_account_id &&
                form.to_bank_account_id &&
                form.from_bank_account_id !== form.to_bank_account_id &&
                form.amount_cents > 0 &&
                form.transfer_date &&
                form.description.trim(),
        );
    });

    function updateAmount(event: Event) {
        const target = event.target as HTMLInputElement;

        form.amount_cents = moneyToCents(target.value);
        form.amount = formatMoneyInput(target.value);
    }

    return {
        form,
        canSubmit,
        updateAmount,
    };
}
