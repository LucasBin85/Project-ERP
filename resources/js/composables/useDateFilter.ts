import { useForm } from '@inertiajs/vue3'
import { todayLocal } from '@/lib/date'

export function useDateFilter(filters: Record<string, unknown> = {}) {
    const form = useForm({
        date: filters.date ?? todayLocal(),
    })

    return { form }
}