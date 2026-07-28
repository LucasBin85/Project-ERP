import { formatMoneyInput, moneyToCents } from '@/lib/input';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

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
        mode: 'single',
        installment_count: 2,
        interval_months: 1,
        competence_date: todayLocal(),
        installments: [] as Array<{ due_date: string; amount_cents: number; amount: string }>,
    });

    const installmentTotal = computed(() => form.installments.reduce((sum, item) => sum + item.amount_cents, 0));
    const difference = computed(() => form.amount_cents - installmentTotal.value);
    const canSubmit = computed(() => {
        return Boolean(
            form.customer_id &&
                form.description.trim() &&
                form.due_date &&
                form.amount_cents > 0 &&
                (form.mode === 'single' || (form.installment_count >= 2 && Boolean(form.competence_date) && difference.value === 0)),
        );
    });

    function updateAmount(event: Event) {
        const target = event.target as HTMLInputElement;
        form.amount_cents = moneyToCents(target.value);
        form.amount = formatMoneyInput(target.value);
    }

    function recalculateInstallments() {
        const count = Math.max(2, Number(form.installment_count));
        const base = Math.floor(form.amount_cents / count);
        const remainder = form.amount_cents % count;
        form.installments = Array.from({ length: count }, (_, index) => {
            const date = new Date(`${form.due_date}T12:00:00`); date.setMonth(date.getMonth() + index * Number(form.interval_months));
            const amountCents = base + (index < remainder ? 1 : 0);
            return { due_date: date.toISOString().slice(0, 10), amount_cents: amountCents, amount: formatMoneyInput(String(amountCents)) };
        });
    }
    function updateInstallmentAmount(index: number, event: Event) {
        const target = event.target as HTMLInputElement;
        form.installments[index].amount_cents = moneyToCents(target.value);
        form.installments[index].amount = formatMoneyInput(target.value);
    }
    function adjustLastInstallment() {
        if (form.installments.length) {
            const last = form.installments.length - 1;
            form.installments[last].amount_cents += difference.value;
            form.installments[last].amount = formatMoneyInput(String(form.installments[last].amount_cents));
        }
    }
    watch(() => [form.amount_cents, form.due_date, form.installment_count, form.interval_months, form.mode], () => {
        if (form.mode === 'installment') recalculateInstallments();
    });

    return { form, canSubmit, updateAmount, installmentTotal, difference, recalculateInstallments, updateInstallmentAmount, adjustLastInstallment };
}
