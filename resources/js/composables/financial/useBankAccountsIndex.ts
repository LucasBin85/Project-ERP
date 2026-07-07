export function useBankAccountsIndex() {
    function formatType(type) {
        const types = {
            checking: 'Conta Corrente',
            savings: 'Poupança',
            investment: 'Investimento',
            cash: 'Caixa',
            other: 'Outra',
        }

        return types[type] ?? type
    }

    return {
        formatType,
    }
}
