import { useForm } from '@inertiajs/vue3'

function today(): string {
    return new Date().toISOString().substring(0, 10)
}

export function useDateFilter(filters: Record<string, unknown> = {}) {
    const form = useForm({
        date: filters.date ?? today(),
    })

    return { form }
}