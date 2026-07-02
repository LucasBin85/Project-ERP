export function onlyNumbers(value: unknown): string {
    return String(value ?? '').replace(/\D/g, '')
}

export function moneyToCents(value: unknown): number {
    return Number(onlyNumbers(value) || 0)
}

export function formatMoneyInput(value: unknown): string {
    const cents = moneyToCents(value)

    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    }).format(cents / 100)
}

export function trimSpaces(value: unknown): string {
    return String(value ?? '').trim()
}

export function upperCase(value: unknown): string {
    return String(value ?? '').toUpperCase()
}