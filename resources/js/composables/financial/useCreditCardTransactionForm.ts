import { formatMoneyInput, moneyToCents } from '@/lib/input';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

function todayLocal(): string {
    const today = new Date();

    return [today.getFullYear(), String(today.getMonth() + 1).padStart(2, '0'), String(today.getDate()).padStart(2, '0')].join('-');
}

export function useCreditCardTransactionForm() {
    const form = useForm({
        expense_account_id: '',
        purchase_date: todayLocal(),
        merchant_name: '',
        description: '',
        amount: '',
        amount_cents: 0,
        installments_total: 1,
        installment_number: 1,
        notes: '',
    });

    const canSubmit = computed(() => {
        return Boolean(
            form.expense_account_id &&
                form.purchase_date &&
                form.merchant_name.trim() &&
                form.description.trim() &&
                form.amount_cents > 0,
        );
    });

    function updateAmount(event: Event) {
        const target = event.target as HTMLInputElement;

        form.amount_cents = moneyToCents(target.value);
        form.amount = formatMoneyInput(target.value);
    }

    return { form, canSubmit, updateAmount };
}
