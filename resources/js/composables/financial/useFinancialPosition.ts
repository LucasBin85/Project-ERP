import { computed } from 'vue'
import { todayLocal } from '@/lib/date'
import { formatCurrency } from '@/lib/formatters'

export function useFinancialPosition(position) {
    const positionDate = todayLocal()

    const summaryCards = computed(() => {
        const summary = position.summary
        const projected = projectedBalance.value

        return [
            {
                label: 'Disponível',
                value: formatCurrency(summary.available_cents),
                tone: 'neutral',
            },
            {
                label: 'Investimentos',
                value: formatCurrency(summary.investments_cents),
                tone: 'neutral',
            },
            {
                label: 'A Receber',
                value: formatCurrency(summary.accounts_receivable_cents),
                tone: 'neutral',
            },
            {
                label: 'A Pagar',
                value: formatCurrency(summary.accounts_payable_cents),
                tone: 'neutral',
            },
            {
                label: 'Posição Líquida',
                value: formatCurrency(summary.net_position_cents),
                tone: isNegative(summary.net_position_cents) ? 'negative' : 'positive',
            },
            {
                label: 'Saldo Projetado',
                value: formatCurrency(projected),
                tone: isNegative(projected) ? 'negative' : 'info',
            },
        ]
    })

    const projectedBalance = computed(() => {
        const summary = position.summary
        const available = summary.available_cents || 0
        const investments = summary.investments_cents || 0
        const receivable = summary.accounts_receivable_cents || 0
        const payable = summary.accounts_payable_cents || 0

        return available + investments + receivable - payable
    })

    function isNegative(value) {
        return Number(value || 0) < 0
    }

    return {
        positionDate,
        projectedBalance,
        summaryCards,
        isNegative,
    }
}
