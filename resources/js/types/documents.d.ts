export interface DocumentRow {
    id: number;
    title: string;
    classification: string;
    owner: string;
    version: number;
    file_name: string | null;
    mime_type: string | null;
    size_bytes: number | null;
    links: string[];
    legal_hold: boolean;
    frozen: boolean;
    archived: boolean;
    uploaded_at: string;
}

export interface EntityTypeOption {
    type: string;
    label: string;
    link_types: string[];
}
