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

type OfxStatementItem = {
    bank_statement_import_transaction_id?: number | null;
    source?: string | null;
    source_label?: string | null;
    external_id?: string | null;
    fit_id?: string | null;
    transaction_date?: string | null;
    description?: string | null;
    amount_cents: number;
    direction?: 'in' | 'out' | null;
    journal_entry_id?: number | null;
    journal_line_id?: number | null;
    match_reason?: string | null;
};

type MovementType = 'inflow' | 'outflow';
type StatementSource = 'manual' | 'ofx';

type StatementItem = {
    bank_statement_import_transaction_id: number | null;
    source: StatementSource;
    source_label: string;
    external_id: string | null;
    transaction_date: string;
    description: string;
    movement_type: MovementType;
    amount: string;
    amount_cents: number;
    journal_line_id: string;
    match_reason: string | null;
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

function normalizeMovementType(value: string): MovementType {
    return value === 'inflow' ? 'inflow' : 'outflow';
}

function movementTypeFromAmount(amountCents: number): MovementType {
    return Number(amountCents) >= 0 ? 'inflow' : 'outflow';
}

function makeOfxStatementItem(item: OfxStatementItem, fallbackDate: string): StatementItem {
    const amountCents = Number(item.amount_cents ?? 0);

    return {
        bank_statement_import_transaction_id: item.bank_statement_import_transaction_id ? Number(item.bank_statement_import_transaction_id) : null,
        source: 'ofx',
        source_label: item.source_label ?? 'OFX',
        external_id: item.external_id ?? item.fit_id ?? null,
        transaction_date: String(item.transaction_date ?? fallbackDate).substring(0, 10),
        description: item.description ?? '',
        movement_type: movementTypeFromAmount(amountCents),
        amount: absoluteMoney(amountCents),
        amount_cents: amountCents,
        journal_line_id: item.journal_line_id ? String(item.journal_line_id) : '',
        match_reason: item.match_reason ?? null,
    };
}

export function useBankReconciliationCreate(
    filters: ReconciliationFilters,
    previewLines: PreviewLine[],
    openingBalanceCents = 0,
    ofxStatementItems: OfxStatementItem[] = [],
) {
    const defaultPeriodEnd = filters.period_end ?? todayLocal();

    const form = useForm({
        bank_account_id: filters.bank_account_id ?? '',
        period_start: filters.period_start ?? startOfMonthLocal(),
        period_end: defaultPeriodEnd,
        statement_balance_cents: openingBalanceCents,
        statement_items: ofxStatementItems.map((item) => makeOfxStatementItem(item, defaultPeriodEnd)) as StatementItem[],
        notes: '',
    });

    const systemLineById = computed(() => {
        return new Map(previewLines.map((line) => [Number(line.id), line]));
    });

    const ofxItemsCount = computed(() => {
        return form.statement_items.filter((item) => item.source === 'ofx').length;
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
                    preserveState: false,
                    preserveScroll: true,
                    replace: true,
                },
            );
        },
    );

    function addStatementItem() {
        form.statement_items.push({
            bank_statement_import_transaction_id: null,
            source: 'manual',
            source_label: 'Manual',
            external_id: null,
            transaction_date: form.period_end || todayLocal(),
            description: '',
            movement_type: 'outflow',
            amount: '',
            amount_cents: 0,
            journal_line_id: '',
            match_reason: null,
        });
    }

    function removeStatementItem(index: number) {
        form.statement_items.splice(index, 1);
    }

    function updateStatementItemAmount(index: number, event: Event) {
        const target = event.target as HTMLInputElement;
        const item = form.statement_items[index];

        if (!item || item.source === 'ofx') {
            return;
        }

        item.amount_cents = signedCents(target.value, item.movement_type);
        item.amount = formatMoneyInput(target.value);
    }

    function updateStatementItemType(index: number, movementType: string) {
        const item = form.statement_items[index];

        if (!item || item.source === 'ofx') {
            return;
        }

        const normalizedType = normalizeMovementType(movementType);

        item.movement_type = normalizedType;
        item.amount_cents = signedCents(item.amount, normalizedType);
    }

    function applySuggestedStatementItems() {
        form.statement_items = previewLines.map((line) => ({
            bank_statement_import_transaction_id: null,
            source: 'manual',
            source_label: 'Sistema',
            external_id: null,
            transaction_date: String(line.date ?? form.period_end ?? todayLocal()).substring(0, 10),
            description: line.description ?? '',
            movement_type: movementTypeFromAmount(Number(line.signed_amount_cents ?? 0)),
            amount: absoluteMoney(Number(line.signed_amount_cents ?? 0)),
            amount_cents: Number(line.signed_amount_cents ?? 0),
            journal_line_id: String(line.id),
            match_reason: 'Sugerido pelos lançamentos do sistema',
        }));
    }

    function applyOfxStatementItems() {
        form.statement_items = ofxStatementItems.map((item) => makeOfxStatementItem(item, form.period_end || todayLocal()));
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
        ofxItemsCount,
        canPreview,
        canSubmit,
        addStatementItem,
        removeStatementItem,
        updateStatementItemAmount,
        updateStatementItemType,
        applySuggestedStatementItems,
        applyOfxStatementItems,
    };
}
