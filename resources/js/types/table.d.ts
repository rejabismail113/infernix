export type SortDir = 'asc' | 'desc';

export interface TableColumn {
    key: string;
    label: string;
    sortable?: boolean;
    align?: 'left' | 'right' | 'center';
    /** false = always visible (cannot be hidden in the column chooser) */
    hideable?: boolean;
    hidden?: boolean;
    /** Money/number columns: monospaced, right aligned */
    numeric?: boolean;
    width?: string;
}

export interface TablePagination {
    total: number;
    perPage: number;
    currentPage: number;
    lastPage: number;
}

export interface TableSort {
    key: string;
    dir: SortDir;
}

export type Density = 'compact' | 'normal' | 'comfortable';
