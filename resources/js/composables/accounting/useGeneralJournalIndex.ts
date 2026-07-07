import { computed } from 'vue'
import { useAutoFilters } from '@/composables/useAutoFilters'
import { useDateRangeFilter } from '@/composables/useDateRangeFilter'

export function useGeneralJournalIndex(props) {
    const { form } = useDateRangeFilter(props.filters)

    form.source = props.filters.source ?? ''
    form.status = props.filters.status ?? ''
    form.search = props.filters.search ?? ''

    useAutoFilters(form, 'general-journal.index')

    const rows = computed(() => props.entries?.data ?? [])

    return {
        form,
        rows,
    }
}
