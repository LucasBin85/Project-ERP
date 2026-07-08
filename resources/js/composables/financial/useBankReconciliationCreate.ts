import { formatMoneyInput, moneyToCents } from '@/lib/input';
import { router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { route } from 'ziggy-js';

type ReconciliationFilters = {
    bank_account_id?: string | null;
    period_start?: string | null;
    period_end?: string | null;
};

type PreviewLine = {
    id: number;
    signed_amount_cents: number;
};

export function useBankReconciliationCreate(filters: ReconciliationFilters, previewLines: PreviewLine[]) {
    const form = useForm({
        bank_account_id: filters.bank_account_id ?? '',
        period_start: filters.period_start ?? '',
        period_end: filters.period_end ?? '',
        statement_balance: '',
        statement_balance_cents: 0,
        journal_line_ids: [] as number[],
        notes: '',
    });

    const selectedMovementCents = computed(() => {
        return previewLines
            .filter((line) => form.journal_line_ids.includes(Number(line.id)))
            .reduce((total, line) => total + Number(line.signed_amount_cents ?? 0), 0);
    });

    function applyPreview() {
        router.get(
            route('bank-reconciliations.create'),
            {
                bank_account_id: form.bank_account_id,
                period_start: form.period_start,
                period_end: form.period_end,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }

    function updateStatementBalance(event: Event) {
        const target = event.target as HTMLInputElement;

        form.statement_balance_cents = moneyToCents(target.value);
        form.statement_balance = formatMoneyInput(target.value);
    }

    function toggleLine(lineId: number, checked: boolean) {
        const normalizedId = Number(lineId);

        if (checked && !form.journal_line_ids.includes(normalizedId)) {
            form.journal_line_ids.push(normalizedId);
            return;
        }

        if (!checked) {
            form.journal_line_ids = form.journal_line_ids.filter((id) => id !== normalizedId);
        }
    }

    function selectAll() {
        form.journal_line_ids = previewLines.map((line) => Number(line.id));
    }

    function clearSelection() {
        form.journal_line_ids = [];
    }

    const canPreview = computed(() => Boolean(form.bank_account_id && form.period_start && form.period_end));

    const canSubmit = computed(() => canPreview.value && form.statement_balance !== '');

    return {
        form,
        selectedMovementCents,
        canPreview,
        canSubmit,
        applyPreview,
        updateStatementBalance,
        toggleLine,
        selectAll,
        clearSelection,
    };
}
