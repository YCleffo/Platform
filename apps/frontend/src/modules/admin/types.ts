export interface AdminActionInput {
  name: string;
  label: string;
  placeholder?: string;
}

export interface AdminResourceAction {
  label: string;
  method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  path: (row: Record<string, unknown>, input: Record<string, string>) => string;
  inputs?: AdminActionInput[];
  body?: unknown;
  danger?: boolean;
  resultPanel?: boolean;
}

export interface AdminOperation {
  label: string;
  description: string;
  method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  path: (input: Record<string, string>, body: unknown) => string;
  inputs?: AdminActionInput[];
  body?: unknown;
  danger?: boolean;
}

export interface AdminResourceConfig {
  title: string;
  description: string;
  listPath: string;
  idField?: string;
  columns?: string[];
  pageSize?: number;
  pageSizeOptions?: number[];
  paginated?: boolean;
  create?: {
    path: string;
    template: Record<string, unknown>;
  };
  decorate?: (rows: Record<string, unknown>[]) => Promise<Record<string, unknown>[]>;
  actions?: (row: Record<string, unknown>) => AdminResourceAction[];
  globalActions?: AdminResourceAction[];
}
