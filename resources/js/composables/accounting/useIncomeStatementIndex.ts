import { useAutoFilters } from '@/composables/useAutoFilters'
import { useDateRangeFilter } from '@/composables/useDateRangeFilter'

export function useIncomeStatementIndex(props) {
    const { form } = useDateRangeFilter(props.filters)

    useAutoFilters(form, 'income-statement.index')

    function formatPercent(value, base) {
        if (!base) return '-'

        return `${((Number(value || 0) / Number(base)) * 100).toFixed(2).replace('.', ',')}%`
    }

    function rowPadding(level) {
        return `${Number(level || 0) * 1.5}rem`
    }

    function amountClass(sectionKey) {
        return sectionKey === 'receita'
            ? 'text-green-300'
            : 'text-red-300'
    }

    function resultTone(value) {
        return Number(value || 0) >= 0 ? 'green' : 'red'
    }

    return {
        form,
        formatPercent,
        rowPadding,
        amountClass,
        resultTone,
    }
}
