import { useAutoFilters } from '@/composables/useAutoFilters'
import { useDateRangeFilter } from '@/composables/useDateRangeFilter'

export function useTrialBalanceIndex(props) {
    const { form } = useDateRangeFilter(props.filters)

    useAutoFilters(form, 'trial-balance.index')

    function isBalanced() {
        return Number(props.trialBalance.totals.difference_cents || 0) === 0 &&
            Number(props.trialBalance.totals.balance_difference_cents || 0) === 0
    }

    function differenceValue() {
        const movementDifference = Number(props.trialBalance.totals.difference_cents || 0)
        const balanceDifference = Number(props.trialBalance.totals.balance_difference_cents || 0)

        return Math.abs(movementDifference || balanceDifference)
    }

    return {
        form,
        isBalanced,
        differenceValue,
    }
}
