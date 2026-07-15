export interface BankOption {
    id: number;
    code: string;
    name: string;
    short_name: string;
    ispb: string;
}

export type BankAccountType = 'checking' | 'savings' | 'investment' | 'cash' | 'other';

export interface ExistingBankAccount {
    id: number;
    name: string;
    bank_id: number | null;
    bank_code: string | null;
    agency: string | null;
    account_number: string | null;
}

export interface BankAccountChartOfAccount {
    id: number;
    code: string;
    name: string;
}

export interface BankAccountOverview {
    id: number;
    name: string;
    bank_name: string | null;
    bank_code: string | null;
    agency: string | null;
    account_number: string | null;
    account_type: BankAccountType;
    opening_balance_cents: number;
    statement_balance_cents: number;
    accounting_balance_cents: number;
    current_balance_cents?: number;
    is_active: boolean;
    chart_of_account: BankAccountChartOfAccount | null;
    last_transaction_at: string | null;
    show_url?: string | null;
}

export interface BankAccountsIndexSummary {
    total_statement_balance_cents: number;
    total_accounting_balance_cents: number;
    total_current_balance_cents?: number;
    total_opening_balance_cents: number;
    active_accounts: number;
    inactive_accounts: number;
    accounts_count: number;
}

export interface BankAccountShowSummary {
    statement_balance_cents: number;
    accounting_balance_cents: number;
    current_balance_cents?: number;
    month_inflows_cents: number;
    month_outflows_cents: number;
    month_result_cents: number;
    current_card_invoice_cents: number;
    open_reconciliations: number;
    linked_credit_cards: number;
}

export interface BankAccountCreateFormData {
    [key: string]: string | number | null;
    bank_id: number | null;
    name: string;
    agency: string;
    account_number: string;
    account_type: BankAccountType;
    opening_balance: string;
    opening_balance_cents: number;
    opening_balance_date: string;
}

export interface BankAccountOfxPreviewAccount {
    container: string | null;
    bank_code: string | null;
    ispb: string | null;
    agency: string | null;
    account_number: string | null;
    account_digit: string | null;
    account_type: BankAccountType | null;
    raw_account_number: string | null;
    raw_account_type: string | null;
}

export interface BankAccountOfxPreviewSuggestion {
    bank_id: number | null;
    name: string | null;
    agency: string | null;
    account_number: string | null;
    account_type: BankAccountType | null;
}

export interface BankAccountOfxPreview {
    file_name: string;
    account: BankAccountOfxPreviewAccount;
    matched_bank: BankOption | null;
    suggested: BankAccountOfxPreviewSuggestion;
    warnings: string[];
    message: string;
}
