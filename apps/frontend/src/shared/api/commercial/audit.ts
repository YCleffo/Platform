import type { HttpClient } from '@/shared/api/http';
import type { AuditEventPage } from '@/shared/types';
import { isAuditEventPage } from '../guards';

export interface AuditEventQuery {
  limit?: number;
  cursor?: string | null;
  action?: string;
  outcome?: '' | 'success' | 'failure' | 'denied';
}

export interface CommercialAuditApi {
  events(accountId: string, query?: AuditEventQuery): Promise<AuditEventPage>;
}

export function createCommercialAuditApi(http: HttpClient): CommercialAuditApi {
  return {
    events: (accountId, query = {}) => {
      const params = new URLSearchParams();
      if (query.limit !== undefined) params.set('limit', String(query.limit));
      if (query.cursor) params.set('cursor', query.cursor);
      if (query.action) params.set('action', query.action);
      if (query.outcome) params.set('outcome', query.outcome);
      const suffix = params.toString() === '' ? '' : `?${params.toString()}`;
      return http.get<AuditEventPage>(
        `/accounts/${encodeURIComponent(accountId)}/audit-events${suffix}`,
        { validate: isAuditEventPage },
      );
    },
  };
}
