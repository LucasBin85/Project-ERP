export type JournalEntryStatus = 'draft' | 'posted';
export type ReconciliationStatus = 'pending' | 'reconciled' | 'reconciled_via_ofx';
export type ClassificationStatus = 'classified' | 'unclassified';

export interface BankStatementWallet {
    id: number;
    name: string;
}

export interface BankStatementAccount {
    id: number;
    name: string;
}

export interface BankStatementClassificationAccount {
    id: number;
    code: string;
    name: string;
    type: string;
}

export interface BankStatementFilters {
    bank_account_id: string;
    start_date: string;
    end_date: string;
    search: string;
}

export interface BankStatementTransaction {
    id: number;
    date: string | null;
    journal_entry_id: number | null;
    description: string | null;
    accounting_status: JournalEntryStatus;
    source: string | null;
    source_label: string;
    reconciliation_status: ReconciliationStatus;
    classification_status: ClassificationStatus;
    classification_label: string;
    classification_account_id: number | null;
    can_classify: boolean;
    type: 'inflow' | 'outflow';
    inflow_cents: number | null;
    outflow_cents: number | null;
    amount_cents: number;
    running_balance_cents: number;
}

export interface BankStatementOperational {
    has_older_transactions: boolean;
}
