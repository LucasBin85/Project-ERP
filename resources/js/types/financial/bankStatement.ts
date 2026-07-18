import type { FinancialOperationType } from '@/types/financial/operationType';

export type JournalEntryStatus = 'draft' | 'posted';
export type ReconciliationStatus = 'pending' | 'reconciled' | 'reconciled_via_ofx' | 'awaiting_counterpart_ofx';
export type ClassificationStatus = 'classified' | 'unclassified';
export type BankStatementWorkflowStatus = 'pending_classification' | 'classified' | 'pending_link' | 'ready_for_accounting' | 'posted';

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
    financial_group: string | null;
    allowed_operation_types: FinancialOperationType[];
    bank_account?: { id: number; name: string; bank_name: string | null; agency: string | null; account_number: string | null; statement_url: string } | null;
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
    workflow_status: BankStatementWorkflowStatus;
    source: string | null;
    source_label: string;
    reconciliation_status: ReconciliationStatus;
    classification_status: ClassificationStatus;
    classification_label: string;
    classification_account_id: number | null;
    operation_type: FinancialOperationType | null;
    allowed_operation_types: FinancialOperationType[];
    can_edit_operation_type: boolean;
    can_classify: boolean;
    can_link_account_payable: boolean;
    linked_account_payable: {
        id: number;
        description: string;
        payee_name: string;
        status: string;
        show_url: string;
    } | null;
    can_link_account_receivable: boolean;
    linked_account_receivable: {
        id: number;
        description: string;
        customer_name: string;
        status: string;
        show_url: string;
    } | null;
    match_status: 'none' | 'unique' | 'ambiguous';
    match_resolution: 'created' | 'kept' | 'linked' | null;
    match_candidates: Array<{
        journal_entry_id: number;
        journal_line_id: number;
        date: string | null;
        description: string | null;
        status: JournalEntryStatus;
    }>;
    type: 'inflow' | 'outflow';
    inflow_cents: number | null;
    outflow_cents: number | null;
    amount_cents: number;
    running_balance_cents: number;
    transfer: {
        id: number;
        status: string;
        counterpart_name: string;
        counterpart_statement_url: string;
        match_status: 'none' | 'unique' | 'ambiguous';
        match_candidates: Array<{ audit_id: number; journal_entry_id: number; description: string; counterpart_name: string }>;
    } | null;
}

export interface BankStatementOperational {
    has_older_transactions: boolean;
}
