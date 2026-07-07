import { useAutoFilters } from '@/composables/useAutoFilters'
import { useDateFilter } from '@/composables/useDateFilter'

export function useBalanceSheetIndex(props) {
    const { form } = useDateFilter(props.filters)

    useAutoFilters(form, 'balance-sheet.index')

    function rowPadding(level) {
        return `${Number(level || 0) * 1.5}rem`
    }

    function balanceSheetDifference() {
        return props.balanceSheet.totals.difference_cents || 0
    }

    function isBalanced() {
        return Number(balanceSheetDifference()) === 0
    }

    function sectionTone(sectionKey) {
        return sectionKey === 'ativo'
            ? 'text-green-300'
            : 'text-blue-300'
    }

    return {
        form,
        rowPadding,
        balanceSheetDifference,
        isBalanced,
        sectionTone,
    }
}
