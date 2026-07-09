import { formatMoneyInput, moneyToCents } from '@/lib/input';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

function suggestedBestPurchaseDay(closingDay: number): number {
    return closingDay >= 28 ? 1 : closingDay + 1;
}

export function useCreditCardCreate() {
    const form = useForm({
        name: '',
        issuer_name: '',
        network: 'mastercard',
        card_type: 'main',
        parent_card_id: '',
        holder_name: '',
        last_four: '',
        closing_day: 5,
        due_day: 15,
        best_purchase_day: 6,
        credit_limit: '',
        credit_limit_cents: 0,
        notes: '',
    });

    watch(
        () => form.closing_day,
        (value) => {
            form.best_purchase_day = suggestedBestPurchaseDay(Number(value));
        },
    );

    watch(
        () => form.card_type,
        (value) => {
            if (value === 'main') {
                form.parent_card_id = '';
            }
        },
    );

    const canSubmit = computed(() => {
        return Boolean(
            form.name.trim() &&
                form.issuer_name.trim() &&
                form.network &&
                form.card_type &&
                Number(form.closing_day) >= 1 &&
                Number(form.due_day) >= 1 &&
                Number(form.best_purchase_day) >= 1 &&
                (form.card_type === 'main' || form.parent_card_id),
        );
    });

    function updateLimit(event: Event) {
        const target = event.target as HTMLInputElement;

        form.credit_limit_cents = moneyToCents(target.value);
        form.credit_limit = formatMoneyInput(target.value);
    }

    return {
        form,
        canSubmit,
        updateLimit,
    };
}
