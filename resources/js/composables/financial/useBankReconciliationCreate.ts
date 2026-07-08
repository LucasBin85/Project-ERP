import { formatMoneyInput, moneyToCents } from '@/lib/input';
import { router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
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

type StatementItem = {
    transaction_date: string;
    description: string;
    amount: string;
    amount_cents: number;
    journal_line_id: string;
};

function todayLocal(): string {
    const today = new Date();

    return [
        today.getFullYear(),
        String(today.getMonth() + 1).padStart(2, '0'),
        String(today.getDate()).padStart(2, '0'),
    ].join('-');
}

function startOfMonthLocal(): string {
    const today = new Date();

    return [
        today.getFullYear(),
        String(today.getMonth() + 1).padStart(2, '0'),
        '01',
    ].join('-');
}

export function useBankReconciliationCreate(filters: ReconciliationFilters, previewLines: PreviewLine[], openingBalanceCents = 0) {
    const form = useForm({
        bank_account_id: filters.bank_account_id ?? '',
        period_start: filters.period_start ?? startOfMonthLocal(),
        period_end: filters.period_end ?? todayLocal(),
        statement_balance_cents: openingBalanceCents,
        statement_items: [] as StatementItem[],
        notes: '',
    });

    const systemLineById = computed(() => {
        return new Map(previewLines.map((line) => [Number(line.id), line]));
    });

    const statementMovementCents = computed(() => {
        return form.statement_items.reduce((total, item) => total + Number(item.amount_cents ?? 0), 0);
    });

    const matchedMovementCents = computed(() => {
        return form.statement_items.reduce((total, item) => {
            if (!item.journal_line_id) {
                return total;
            }

            const line = systemLineById.value.get(Number(item.journal_line_id));

            return total + Number(line?.signed_amount_cents ?? 0);
        }, 0);
    });

    const statementBalanceCents = computed(() => openingBalanceCents + statementMovementCents.value);
    const reconciledBalanceCents = computed(() => openingBalanceCents + matchedMovementCents.value);
    const differenceCents = computed(() => reconciledBalanceCents.value - statementBalanceCents.value);

    const pendingItemsCount = computed(() => {
        return form.statement_items.filter((item) => !item.journal_line_id).length;
    });

    const canPreview = computed(() => Boolean(form.bank_account_id && form.period_start && form.period_end));
    const canSubmit = computed(() => canPreview.value && form.statement_items.length > 0);

    watch(
        () => form.period_start,
        () => {
            if (!form.period_start) {
                form.period_start = startOfMonthLocal();
            }

            if (form.period_start > form.period_end) {
                form.period_end = form.period_start;
            }
        },
    );

    watch(
        () => form.period_end,
        () => {
            if (!form.period_end) {
                form.period_end = form.period_start;
            }

            if (form.period_end < form.period_start) {
                form.period_start = form.period_end;
            }
        },
    );

    watch(
        () => [form.bank_account_id, form.period_start, form.period_end],
        () => {
            if (!canPreview.value) {
                return;
            }

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
        },
    );

    function addStatementItem() {
        form.statement_items.push({
            transaction_date: form.period_end || todayLocal(),
            description: '',
            amount: '',
            amount_cents: 0,
            journal_line_id: '',
        });
    }

    function removeStatementItem(index: number) {
        form.statement_items.splice(index, 1);
    }

    function updateStatementItemAmount(index: number, event: Event) {
        const target = event.target as HTMLInputElement;
        const item = form.statement_items[index];

        if (!item) {
            return;
        }

        item.amount_cents = moneyToCents(target.value);
        item.amount = formatMoneyInput(target.value);
    }

    function applySuggestedStatementItems() {
        form.statement_items = previewLines.map((line) => ({
            transaction_date: form.period_end || todayLocal(),
            description: '',
            amount: formatMoneyInput(String(Math.abs(Number(line.signed_amount_cents ?? 0)))),
            amount_cents: Number(line.signed_amount_cents ?? 0),
            journal_line_id: String(line.id),
        }));
    }

    watch(
        statementBalanceCents,
        (value) => {
            form.statement_balance_cents = value;
        },
        { immediate: true },
    );

    return {
        form,
        statementMovementCents,
        matchedMovementCents,
        statementBalanceCents,
        reconciledBalanceCents,
        differenceCents,
        pendingItemsCount,
        canPreview,
        canSubmit,
        addStatementItem,
        removeStatementItem,
        updateStatementItemAmount,
        applySuggestedStatementItems,
    };
}
