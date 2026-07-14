export interface BankOption {
    id: number;
    code: string;
    name: string;
    short_name: string;
    ispb: string;
}

export interface ExistingBankAccount {
    id: number;
    name: string;
    bank_id: number | null;
    bank_code: string | null;
    agency: string | null;
    account_number: string | null;
}

export interface BankAccountCreateFormData {
    [key: string]: string | number | null;
    bank_id: number | null;
    name: string;
    agency: string;
    account_number: string;
    account_type: 'checking' | 'savings' | 'investment' | 'cash' | 'other';
    opening_balance: string;
    opening_balance_cents: number;
    opening_balance_date: string;
}
