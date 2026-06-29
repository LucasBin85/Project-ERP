import { reactive } from 'vue'

type DateRangeFilters = {
    start_date?: string | null
    end_date?: string | null
}

type DateRangeFilterOptions = {
    defaultStart?: string
    defaultEnd?: string
}

function todayLocal(): string {
    const today = new Date()

    return [
        today.getFullYear(),
        String(today.getMonth() + 1).padStart(2, '0'),
        String(today.getDate()).padStart(2, '0'),
    ].join('-')
}

function startOfYearLocal(): string {
    const today = new Date()

    return `${today.getFullYear()}-01-01`
}

export function useDateRangeFilter(
    filters: DateRangeFilters = {},
    options: DateRangeFilterOptions = {},
) {
    const {
        defaultStart = startOfYearLocal(),
        defaultEnd = todayLocal(),
    } = options

    const form = reactive({
        start_date: filters?.start_date || defaultStart,
        end_date: filters?.end_date || defaultEnd,
    })

    return {
        form,
    }
}