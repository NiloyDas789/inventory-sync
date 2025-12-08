export interface GoogleSheetsConnection {
    id: number;
    shop_id: number;
    sheet_id: string;
    sheet_url: string;
    last_synced_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface SyncLog {
    id: number;
    shop_id: number;
    sync_type: string;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    started_at: string | null;
    completed_at: string | null;
    records_processed: number;
    error_message: string | null;
    created_at: string;
    updated_at: string;
}

export interface SyncFieldMapping {
    id: number;
    shop_id: number;
    shopify_field: string;
    sheet_column: string;
    is_active: boolean;
    display_order: number;
    created_at: string;
    updated_at: string;
}

export interface SyncProgress {
    sync_log_id: number;
    status: string;
    records_processed: number;
    total_records: number | null;
    percentage: number;
    updated_at: string;
}

export interface SyncStartRequest {
    strategy: 'full' | 'incremental' | 'selective';
    async?: boolean;
    conflict_resolution?: 'shopify_wins' | 'sheets_wins' | 'manual' | 'merge';
    options?: {
        product_ids?: string[];
        variant_ids?: string[];
        chunk_size?: number;
        dry_run?: boolean;
        preview_only?: boolean;
    };
}

export interface ImportPreview {
    preview?: Array<{
        row: number;
        changes: Record<string, { current: any; new: any }>;
        current: Record<string, any>;
        new: Record<string, any>;
        error?: string;
    }>;
    total_rows?: number;
    rows_with_changes?: number;
    valid?: Array<{
        row: number;
        data: Record<string, any>;
    }>;
    invalid?: Array<{
        row: number;
        data: Record<string, any>;
        errors: string[];
    }>;
    errors?: string[];
}

