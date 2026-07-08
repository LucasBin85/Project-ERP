import { useAutoFilters } from '@/composables/useAutoFilters';
import { router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import { route } from 'ziggy-js';

type AccountsPayableFilters = {
    status?: string | null;
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

function startOfMonthLocal(): string {
    const today = new Date();

    return formatLocalDate(new Date(today.getFullYear(), today.getMonth(), 1));
}

function endOfMonthLocal(): string {
    const today = new Date();

    return formatLocalDate(new Date(today.getFullYear(), today.getMonth() + 1, 0));
}

export function useAccountsPayableIndex(filters: AccountsPayableFilters) {
    const form = reactive({
        status: filters.status ?? '',
        start_date: filters.start_date ?? startOfMonthLocal(),
        end_date: filters.end_date ?? endOfMonthLocal(),
        search: filters.search ?? '',
    });

    watch(
        () => form.start_date,
        () => {
            if (!form.start_date) {
                form.start_date = startOfMonthLocal();
            }

            if (form.start_date > form.end_date) {
                form.end_date = form.start_date;
            }
        },
    );

    watch(
        () => form.end_date,
        () => {
            if (!form.end_date) {
                form.end_date = form.start_date;
            }

            if (form.end_date < form.start_date) {
                form.start_date = form.end_date;
            }
        },
    );

    useAutoFilters(form, 'accounts-payable.index');

    function clearFilters() {
        Object.assign(form, {
            status: '',
            start_date: startOfMonthLocal(),
            end_date: endOfMonthLocal(),
            search: '',
        });

        router.get(route('accounts-payable.index'), {}, {
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
