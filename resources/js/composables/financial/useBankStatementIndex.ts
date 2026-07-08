import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { route } from 'ziggy-js';

type BankStatementFilters = {
    bank_account_id: string;
    start_date: string;
    end_date: string;
    search: string;
};

export function useBankStatementIndex(filters: BankStatementFilters) {
    const form = reactive({
        bank_account_id: filters.bank_account_id ?? '',
        start_date: filters.start_date ?? '',
        end_date: filters.end_date ?? '',
        search: filters.search ?? '',
    });

    function cleanFilters() {
        return Object.fromEntries(
            Object.entries(form).filter(([, value]) => String(value ?? '').trim() !== ''),
        );
    }

    function applyFilters() {
        router.get(route('bank-statements.index'), cleanFilters(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    function clearFilters() {
        form.bank_account_id = '';
        form.start_date = '';
        form.end_date = '';
        form.search = '';

        router.get(route('bank-statements.index'), {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    return {
        form,
        applyFilters,
        clearFilters,
    };
}
