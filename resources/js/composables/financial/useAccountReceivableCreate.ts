import { formatMoneyInput, moneyToCents } from '@/lib/input';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

function todayLocal(): string {
    const today = new Date();
    return [today.getFullYear(), String(today.getMonth() + 1).padStart(2, '0'), String(today.getDate()).padStart(2, '0')].join('-');
}

export function useAccountReceivableCreate() {
    const form = useForm({
        customer_id: '',
        description: '',
        due_date: todayLocal(),
        amount: '',
        amount_cents: 0,
        notes: '',
    });

    const canSubmit = computed(() => {
        return Boolean(
            form.customer_id &&
                form.description.trim() &&
                form.due_date &&
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
