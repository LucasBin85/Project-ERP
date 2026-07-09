import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

function todayLocal(): string {
    const today = new Date();
    return [today.getFullYear(), String(today.getMonth() + 1).padStart(2, '0'), String(today.getDate()).padStart(2, '0')].join('-');
}

export function useAccountReceivableReceipt() {
    const form = useForm({
        bank_account_id: '',
        received_at: todayLocal(),
    });

    const canSubmit = computed(() => Boolean(form.bank_account_id && form.received_at));

    return { form, canSubmit };
}
