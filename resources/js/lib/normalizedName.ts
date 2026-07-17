export function normalizeName(value: string): string {
    return value.trim().replace(/\s+/g, ' ').toLocaleLowerCase('pt-BR');
}

export function isDuplicateName(value: string, existingNames: string[]): boolean {
    const normalized = normalizeName(value);
    return normalized !== '' && existingNames.some((name) => normalizeName(name) === normalized);
}
