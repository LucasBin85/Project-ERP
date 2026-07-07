import { useAutoFilters } from '@/composables/useAutoFilters'
import { useDateRangeFilter } from '@/composables/useDateRangeFilter'

export function useLedgerIndex(props) {
    const { form } = useDateRangeFilter(props.filters)

    form.chart_of_account_id = props.filters?.chart_of_account_id || ''
    form.status = props.filters?.status || ''

    useAutoFilters(form, 'ledger.index', {
        beforeFilter: () => {
            return Boolean(
                form.chart_of_account_id &&
                form.start_date &&
                form.end_date &&
                form.start_date <= form.end_date,
            )
        },
    })

    function typeLabel(type) {
        const map = {
            ativo: 'Ativo',
            passivo: 'Passivo',
            receita: 'Receita',
            despesa: 'Despesa',
            patrimonio: 'Patrimônio',
        }

        return map[type] || type || '—'
    }

    function normalBalanceLabel(side) {
        return side === 'debit' ? 'Devedora' : 'Credora'
    }

    return {
        form,
        typeLabel,
        normalBalanceLabel,
    }
}
