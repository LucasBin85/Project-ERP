import { useAutoFilters } from '@/composables/useAutoFilters';
import { router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import { route } from 'ziggy-js';

type CashFlowFilters = {
    start_date?: string | null;
    end_date?: string | null;
    mode?: string | null;
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

function sixtyDaysFromTodayLocal(): string {
    const today = new Date();
    today.setDate(today.getDate() + 60);

    return formatLocalDate(today);
}

export function useCashFlowIndex(filters: CashFlowFilters) {
    const form = reactive({
        start_date: filters.start_date ?? todayLocal(),
        end_date: filters.end_date ?? sixtyDaysFromTodayLocal(),
        mode: filters.mode ?? 'all',
        search: filters.search ?? '',
    });

    watch(
        () => form.start_date,
        () => {
            if (!form.start_date) {
                form.start_date = todayLocal();
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

    useAutoFilters(form, 'cash-flow.index');

    function clearFilters() {
        Object.assign(form, {
            start_date: todayLocal(),
            end_date: sixtyDaysFromTodayLocal(),
            mode: 'all',
            search: '',
        });

        router.get(route('cash-flow.index'), {}, {
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
