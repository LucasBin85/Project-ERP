export type FinancialOperationType = 'transfer' | 'payment' | 'income' | 'investment' | 'expense' | 'fee' | 'other';

export interface FinancialOperationTypeOption {
    code: FinancialOperationType;
    label: string;
    classification_enabled: boolean;
}
