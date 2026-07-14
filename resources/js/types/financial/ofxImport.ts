export type OfxImportSituation = 'new' | 'already_imported' | 'possible_match' | 'ambiguous_match' | 'ignored' | 'error';

export type OfxImportAction = 'create' | 'link' | 'ignore';

export interface OfxPreviewSuggestion {
    kind: string;
    label: string;
    journal_entry_id?: number | null;
    journal_line_id?: number | null;
    status?: string | null;
    candidate_ids?: number[];
}

export interface OfxPreviewRow {
    row_key: string;
    date: string | null;
    description: string;
    amount_cents: number | null;
    signed_amount_cents: number | null;
    direction: 'in' | 'out' | null;
    situation: OfxImportSituation;
    default_action: OfxImportAction;
    allowed_actions: OfxImportAction[];
    candidate_count?: number;
    suggestion: OfxPreviewSuggestion;
}

export type OfxPreviewSummary = Partial<Record<OfxImportSituation, number>>;

export interface OfxAccountDetails {
    id?: number | null;
    name?: string | null;
    bank_name?: string | null;
    bank_code?: string | null;
    ispb?: string | null;
    agency?: string | null;
    account_number?: string | null;
    account_type?: string | null;
    container?: string | null;
    bank_id?: string | null;
    branch_id?: string | null;
    account_id?: string | null;
    broker_id?: string | null;
    routing_number?: string | null;
    organization?: string | null;
    financial_institution_id?: string | null;
    currency?: string | null;
}

export interface OfxAccountValidation {
    status: 'validated' | 'unverified' | 'mismatched';
    blocking: boolean;
    message: string;
    current_account: OfxAccountDetails;
    ofx_account: OfxAccountDetails;
    matched_fields: string[];
    divergent_fields: string[];
    warnings: string[];
}

export interface OfxImportPreview {
    token: string;
    file_name: string;
    bank_account_id: number;
    account_validation: OfxAccountValidation;
    rows: OfxPreviewRow[];
    summary: OfxPreviewSummary;
}

export interface OfxImportDecision {
    row_key: string;
    action: OfxImportAction;
}
