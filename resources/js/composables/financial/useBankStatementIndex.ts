import { useAutoFilters } from '@/composables/useAutoFilters';
import { useDateRangeFilter } from '@/composables/useDateRangeFilter';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

type BankStatementFilters = {
    bank_account_id?: string | null;
    start_date?: string | null;
    end_date?: string | null;
    search?: string | null;
};

function todayLocal(): string {
    const today = new Date();

    return [
        today.getFullYear(),
        String(today.getMonth() + 1).padStart(2, '0'),
        String(today.getDate()).padStart(2, '0'),
    ].join('-');
}

function startOfYearLocal(): string {
    const today = new Date();

    return `${today.getFullYear()}-01-01`;
}

export function useBankStatementIndex(filters: BankStatementFilters) {
    const { form } = useDateRangeFilter(filters);

    Object.assign(form, {
        bank_account_id: filters.bank_account_id ?? '',
        search: filters.search ?? '',
    });

    useAutoFilters(form, 'bank-statements.index', {
        beforeFilter: () => Boolean(form.bank_account_id && form.start_date && form.end_date),
    });

    function clearFilters() {
        Object.assign(form, {
            bank_account_id: '',
            start_date: startOfYearLocal(),
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
        clearFilters,
    };
}
