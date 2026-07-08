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
    date?: string;
    description?: string;
    signed_amount_cents: number;
    journal_entry_id?: number;
};

type MovementType = 'inflow' | 'outflow';

type StatementItem = {
    transaction_date: string;
    description: string;
    movement_type: MovementType;
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

function signedCents(rawValue: unknown, movementType: MovementType): number {
    const cents = moneyToCents(rawValue);

    return movementType === 'outflow' ? cents * -1 : cents;
}

function absoluteMoney(value: number): string {
    return formatMoneyInput(String(Math.abs(value)));
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
            movement_type: 'outflow',
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

        item.amount_cents = signedCents(target.value, item.movement_type);
        item.amount = formatMoneyInput(target.value);
    }

    function updateStatementItemType(index: number, movementType: MovementType) {
        const item = form.statement_items[index];

        if (!item) {
            return;
        }

        item.movement_type = movementType;
        item.amount_cents = signedCents(item.amount, movementType);
    }

    function applySuggestedStatementItems() {
        form.statement_items = previewLines.map((line) => ({
            transaction_date: String(line.date ?? form.period_end ?? todayLocal()).substring(0, 10),
            description: line.description ?? '',
            movement_type: Number(line.signed_amount_cents ?? 0) >= 0 ? 'inflow' : 'outflow',
            amount: absoluteMoney(Number(line.signed_amount_cents ?? 0)),
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
        updateStatementItemType,
        applySuggestedStatementItems,
    };
}
