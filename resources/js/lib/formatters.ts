export function formatDate(value: unknown): string {
    if (!value) return '-'

    const raw =
        typeof value === 'string'
            ? value
            : (value as { date?: string }).date ?? String(value)

    const date = raw.substring(0, 10)
    const [year, month, day] = date.split('-')

    if (!year || !month || !day) return '-'

    return `${day}/${month}/${year}`
}

export function formatDateTime(value: string | Date | null | undefined): string {
    if (!value) return '-'

    return new Intl.DateTimeFormat('pt-BR', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value))
}

export function formatCurrency(cents: number | null | undefined): string {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    }).format(Number(cents ?? 0) / 100)
}

export function formatMoneyOrDash(cents: number | null | undefined): string {
    if (!cents) return '-'

    return formatCurrency(cents)
}

export function formatAccount(
    code?: string | null,
    name?: string | null,
): string {
    if (!code && !name) return '-'
    if (!code) return String(name)
    if (!name) return String(code)

    return `${code} - ${name}`
}

export function formatStatus(status?: string | null): string {
    const statuses: Record<string, string> = {
        active: 'Ativo',
        open: 'Aberta',
        closed: 'Fechada',
        partial: 'Parcial',
        overdue: 'Vencida',
        posted: 'Postado',
        draft: 'Rascunho',
        pending: 'Pendente',
        paid: 'Pago',
        received: 'Recebido',
        reconciled: 'Conciliado',
        completed: 'Concluído',
        cancelled: 'Cancelado',
        reversed: 'Estornado',
    }

    if (!status) return '-'

    return statuses[status] ?? status
}

export function formatNature(nature?: string | null): string {
    const natures: Record<string, string> = {
        devedora: 'Devedora',
        credora: 'Credora',
        debit: 'Devedora',
        credit: 'Credora',
    }

    if (!nature) return '-'

    return natures[nature] ?? nature
}

export function formatAccountType(type?: string | null): string {
    const types: Record<string, string> = {
        ativo: 'Ativo',
        passivo: 'Passivo',
        receita: 'Receita',
        despesa: 'Despesa',
        patrimonio: 'Patrimônio',
    }

    if (!type) return '-'

    return types[type] ?? type
}

export function formatPercentage(value: number): string {
    return new Intl.NumberFormat('pt-BR', {
        style: 'percent',
        minimumFractionDigits: 2,
    }).format(value / 100)
}

export function formatNumber(value: number): string {
    return new Intl.NumberFormat('pt-BR').format(value)
}
