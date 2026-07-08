import { useAutoFilters } from '@/composables/useAutoFilters';
import { router } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import { route } from 'ziggy-js';

type BankStatementFilters = {
    bank_account_id?: string | null;
    start_date?: string | null;
    end_date?: string | null;
    search?: string | null;
};

function formatLocalDate(date: Date): string {
    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-');
}

function todayLocal(): string {
    return formatLocalDate(new Date());
}

function startOfMonthLocal(): string {
    const today = new Date();

    return formatLocalDate(new Date(today.getFullYear(), today.getMonth(), 1));
}

function endOfMonthLocal(value: string): string {
    const [year, month] = value.split('-').map(Number);

    if (!year || !month) {
        return todayLocal();
    }

    return formatLocalDate(new Date(year, month, 0));
}

function minDate(first: string, second: string): string {
    return first <= second ? first : second;
}

function sameMonth(first: string, second: string): boolean {
    return first.substring(0, 7) === second.substring(0, 7);
}

export function useBankStatementIndex(filters: BankStatementFilters) {
    const form = reactive({
        bank_account_id: filters.bank_account_id ?? '',
        start_date: filters.start_date ?? startOfMonthLocal(),
        end_date: filters.end_date ?? todayLocal(),
        search: filters.search ?? '',
    });

    const maxEndDate = computed(() => minDate(endOfMonthLocal(form.start_date), todayLocal()));

    watch(
        () => form.start_date,
        () => {
            if (!form.start_date) {
                form.start_date = startOfMonthLocal();
            }

            if (!sameMonth(form.start_date, form.end_date) || form.end_date < form.start_date) {
                form.end_date = minDate(maxEndDate.value, todayLocal());
            }

            if (form.end_date > maxEndDate.value) {
                form.end_date = maxEndDate.value;
            }
        },
    );

    watch(
        () => form.end_date,
        () => {
            if (!form.end_date) {
                form.end_date = form.start_date;
            }

            if (!sameMonth(form.start_date, form.end_date)) {
                form.end_date = maxEndDate.value;
            }

            if (form.end_date < form.start_date) {
                form.end_date = form.start_date;
            }

            if (form.end_date > maxEndDate.value) {
                form.end_date = maxEndDate.value;
            }
        },
    );

    useAutoFilters(form, 'bank-statements.index', {
        beforeFilter: () => Boolean(form.bank_account_id && form.start_date && form.end_date),
    });

    function clearFilters() {
        Object.assign(form, {
            bank_account_id: '',
            start_date: startOfMonthLocal(),
            end_date: todayLocal(),
            search: '',
        });

        router.get(route('bank-statements.index'), {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    return {
        form,
        maxEndDate,
        clearFilters,
    };
}
