export function toDateInput(date: Date): string {
    const year = date.getFullYear()
    const month = String(date.getMonth() + 1).padStart(2, '0')
    const day = String(date.getDate()).padStart(2, '0')

    return `${year}-${month}-${day}`
}

export function todayLocal(): string {
    return toDateInput(new Date())
}

export function startOfMonth(date = new Date()): string {
    return toDateInput(new Date(date.getFullYear(), date.getMonth(), 1))
}

export function endOfMonth(date = new Date()): string {
    return toDateInput(new Date(date.getFullYear(), date.getMonth() + 1, 0))
}

export function startOfYear(date = new Date()): string {
    return toDateInput(new Date(date.getFullYear(), 0, 1))
}

export function endOfYear(date = new Date()): string {
    return toDateInput(new Date(date.getFullYear(), 11, 31))
}

export function yesterday(): string {
    const date = new Date()
    date.setDate(date.getDate() - 1)

    return toDateInput(date)
}

export function tomorrow(): string {
    const date = new Date()
    date.setDate(date.getDate() + 1)

    return toDateInput(date)
}