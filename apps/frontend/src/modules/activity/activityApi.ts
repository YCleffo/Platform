import { coreAdminHttp } from '@/shared/api/core/adminHttp';

export interface ActivityPluginDescriptor {
  code: string;
  version: number;
  title: string;
  category: string;
  capabilities: string[];
  configSchema: Record<string, unknown>;
  defaultConfig: Record<string, unknown>;
}

export interface ActivityItem {
  id: string;
  pluginCode: string;
  pluginTitle: string;
  category: string;
  title: string;
  description: string;
  status: 'DRAFT' | 'PUBLISHED' | 'CLOSED' | 'ARCHIVED';
  visibility: string;
  tags: string[];
  activeVersionId: string | null;
  versionNumber: number;
  instructions?: string;
  config?: Record<string, unknown>;
  content?: Record<string, unknown>;
  timeLimitSeconds: number | null;
  passingScorePercent: number;
  maxAttempts: number;
  gradingPolicy?: string;
  revealAnswersPolicy?: 'never' | 'immediate' | 'after_grading' | 'deadline';
  updatedAt: string;
  myAttemptsCount?: number;
  myBestPercentage?: number | null;
}

export interface ActivityAttempt {
  id: string;
  activityId: string;
  versionId: string;
  assignmentId: string | null;
  participantPersonId: string;
  state:
    | 'CREATED'
    | 'IN_PROGRESS'
    | 'SUBMITTED'
    | 'PENDING_REVIEW'
    | 'EVALUATED'
    | 'COMPLETED'
    | 'EXPIRED'
    | 'CANCELLED'
    | 'INVALIDATED';
  revision: number;
  seed: number;
  pluginCode: string;
  activityTitle: string;
  config: Record<string, unknown>;
  exercise: Record<string, unknown>;
  answers: Record<string, unknown>;
  telemetry: Record<string, unknown>;
  evaluation: {
    rawScore?: number;
    maxScore?: number;
    percentage?: number;
    passed?: boolean;
    requiresManualReview?: boolean;
    breakdown?: Array<Record<string, unknown>>;
    metrics?: Record<string, unknown>;
    telemetryVerified?: boolean;
    feedback?: string;
  };
  rawScore: number | string | null;
  maxScore: number | string | null;
  percentage: number | string | null;
  passed: boolean | null;
  startedAt: string | null;
  expiresAt: string | null;
  serverNow?: string;
  remainingSeconds?: number | null;
  submittedAt: string | null;
  evaluatedAt: string | null;
  resultsPublishedAt?: string | null;
  resultsPublished?: boolean;
  revealAnswersPolicy?: string;
  canRevealAnswers?: boolean;
  passingScorePercent?: number;
  reviewNotes: string | null;
  resultHistory?: Array<{
    id: string;
    fromState: string | null;
    toState: string;
    actorPersonId: string | null;
    reason: string;
    metadata: Record<string, unknown>;
    createdAt: string;
  }>;
}

export interface ActivityAttemptSummary {
  id: string;
  activityId: string;
  versionId: string;
  versionNumber: number;
  assignmentId: string | null;
  participantPersonId: string;
  participantName: string;
  state: ActivityAttempt['state'];
  rawScore: number | null;
  maxScore: number | null;
  percentage: number | null;
  passed: boolean | null;
  startedAt: string | null;
  submittedAt: string | null;
  evaluatedAt: string | null;
  resultsPublishedAt: string | null;
  reviewNotes: string | null;
  requiresManualReview: boolean;
}

export interface UniversalQuestionBankItem {
  id: string;
  category: string;
  tags: string[];
  difficulty: number;
  questionType: string;
  content: Record<string, unknown>;
  answerKey: unknown;
  explanation: string;
  defaultPoints: number;
  archivedAt: string | null;
  updatedAt: string;
}

function orgHeaders(organizationId: string): Record<string, string> {
  return { 'X-Education-Organization': organizationId };
}

export const activityApi = {
  async listPlugins(organizationId: string): Promise<{ items: ActivityPluginDescriptor[] }> {
    return coreAdminHttp.request('/activity/plugins', {
      method: 'GET',
      headers: orgHeaders(organizationId),
    });
  },

  async listActivities(
    organizationId: string,
    params?: { status?: string; pluginCode?: string; q?: string },
  ): Promise<{ items: ActivityItem[] }> {
    const q = new URLSearchParams();
    if (params?.status) q.set('status', params.status);
    if (params?.pluginCode) q.set('pluginCode', params.pluginCode);
    if (params?.q) q.set('q', params.q);
    const qs = q.toString();
    return coreAdminHttp.request(`/activity/items${qs ? '?' + qs : ''}`, {
      method: 'GET',
      headers: orgHeaders(organizationId),
    });
  },

  async getActivity(organizationId: string, id: string): Promise<ActivityItem> {
    return coreAdminHttp.request(`/activity/items/${encodeURIComponent(id)}`, {
      method: 'GET',
      headers: orgHeaders(organizationId),
    });
  },

  async createActivity(
    organizationId: string,
    payload: Record<string, unknown>,
  ): Promise<ActivityItem> {
    return coreAdminHttp.request('/activity/items', {
      method: 'POST',
      headers: orgHeaders(organizationId),
      body: payload,
    });
  },

  async updateActivity(
    organizationId: string,
    id: string,
    payload: Record<string, unknown>,
  ): Promise<ActivityItem> {
    return coreAdminHttp.request(`/activity/items/${encodeURIComponent(id)}`, {
      method: 'PUT',
      headers: orgHeaders(organizationId),
      body: payload,
    });
  },

  async duplicateActivity(organizationId: string, id: string): Promise<ActivityItem> {
    return coreAdminHttp.request(`/activity/items/${encodeURIComponent(id)}/duplicate`, {
      method: 'POST',
      headers: orgHeaders(organizationId),
      body: {},
    });
  },

  async listVersions(
    organizationId: string,
    activityId: string,
  ): Promise<
    Array<{
      id: string;
      versionNumber: number;
      title: string;
      instructions: string;
      timeLimitSeconds: number | null;
      passingScorePercent: number;
      maxAttempts: number;
      revealAnswersPolicy: string;
      gradingPolicy: string;
      questionsCount: number;
      attemptsCount: number;
      createdAt: string;
    }>
  > {
    return coreAdminHttp.request(`/activity/items/${encodeURIComponent(activityId)}/versions`, {
      method: 'GET',
      headers: orgHeaders(organizationId),
    });
  },

  async startAttempt(
    organizationId: string,
    activityId: string,
    payload?: {
      config?: Record<string, unknown>;
      assignmentId?: string;
      idempotencyKey?: string;
      resumeActive?: boolean;
    },
  ): Promise<ActivityAttempt> {
    return coreAdminHttp.request(`/activity/items/${encodeURIComponent(activityId)}/attempts`, {
      method: 'POST',
      headers: orgHeaders(organizationId),
      body: payload ?? {},
    });
  },

  async bootstrapTyping(organizationId: string): Promise<ActivityItem> {
    return coreAdminHttp.request('/activity/typing/bootstrap', {
      method: 'POST',
      headers: orgHeaders(organizationId),
      body: {},
    });
  },

  async bootstrapPlugin(organizationId: string, pluginCode: string): Promise<ActivityItem> {
    return coreAdminHttp.request(`/activity/plugins/${encodeURIComponent(pluginCode)}/bootstrap`, {
      method: 'POST',
      headers: orgHeaders(organizationId),
      body: {},
    });
  },

  async getAttempt(organizationId: string, attemptId: string): Promise<ActivityAttempt> {
    return coreAdminHttp.request(`/activity/attempts/${encodeURIComponent(attemptId)}`, {
      method: 'GET',
      headers: orgHeaders(organizationId),
    });
  },

  async saveDraft(
    organizationId: string,
    attemptId: string,
    payload: {
      answers: Record<string, unknown>;
      telemetry?: Record<string, unknown>;
      revision: number;
    },
  ): Promise<{ saved: boolean; revision: number; serverNow?: string; remainingSeconds?: number | null }> {
    return coreAdminHttp.request(`/activity/attempts/${encodeURIComponent(attemptId)}/draft`, {
      method: 'PUT',
      headers: orgHeaders(organizationId),
      body: payload,
    });
  },

  async submitAttempt(
    organizationId: string,
    attemptId: string,
    payload: {
      submission: Record<string, unknown>;
      telemetry?: Record<string, unknown>;
      revision: number;
      idempotencyKey?: string;
    },
  ): Promise<ActivityAttempt> {
    return coreAdminHttp.request(`/activity/attempts/${encodeURIComponent(attemptId)}/submit`, {
      method: 'POST',
      headers: orgHeaders(organizationId),
      body: payload,
    });
  },

  async listActivityAttempts(
    organizationId: string,
    activityId: string,
    params?: { state?: string },
  ): Promise<{ items: ActivityAttemptSummary[] }> {
    const q = new URLSearchParams();
    if (params?.state) q.set('state', params.state);
    const qs = q.toString();
    return coreAdminHttp.request(
      `/activity/items/${encodeURIComponent(activityId)}/attempts${qs ? '?' + qs : ''}`,
      {
        method: 'GET',
        headers: orgHeaders(organizationId),
      },
    );
  },

  async reviewAttempt(
    organizationId: string,
    attemptId: string,
    payload: {
      rawScore?: number;
      questionReviews?: Array<{
        questionId: string;
        pointsEarned: number;
        comment?: string;
        correct?: boolean;
      }>;
      reviewNotes?: string;
      publish?: boolean;
    },
  ): Promise<ActivityAttempt> {
    return coreAdminHttp.request(`/activity/attempts/${encodeURIComponent(attemptId)}/review`, {
      method: 'POST',
      headers: orgHeaders(organizationId),
      body: payload,
    });
  },

  async regradeAttempt(
    organizationId: string,
    attemptId: string,
    payload?: { resetManual?: boolean },
  ): Promise<ActivityAttempt> {
    return coreAdminHttp.request(`/activity/attempts/${encodeURIComponent(attemptId)}/regrade`, {
      method: 'POST',
      headers: orgHeaders(organizationId),
      body: payload ?? {},
    });
  },

  async publishAttemptResults(
    organizationId: string,
    attemptId: string,
    publish = true,
  ): Promise<ActivityAttempt> {
    return coreAdminHttp.request(`/activity/attempts/${encodeURIComponent(attemptId)}/publish`, {
      method: 'POST',
      headers: orgHeaders(organizationId),
      body: { publish },
    });
  },

  async listQuestionBank(
    organizationId: string,
    params?: { archived?: boolean; category?: string; difficulty?: number; type?: string; q?: string },
  ): Promise<{ items: UniversalQuestionBankItem[] }> {
    const q = new URLSearchParams();
    if (params?.archived) q.set('archived', '1');
    if (params?.category) q.set('category', params.category);
    if (params?.difficulty) q.set('difficulty', String(params.difficulty));
    if (params?.type) q.set('type', params.type);
    if (params?.q) q.set('q', params.q);
    const qs = q.toString();
    return coreAdminHttp.request(`/activity/question-bank${qs ? '?' + qs : ''}`, {
      method: 'GET',
      headers: orgHeaders(organizationId),
    });
  },

  async createQuestionBankItem(
    organizationId: string,
    payload: Record<string, unknown>,
  ): Promise<UniversalQuestionBankItem> {
    return coreAdminHttp.request('/activity/question-bank', {
      method: 'POST',
      headers: orgHeaders(organizationId),
      body: payload,
    });
  },

  async archiveQuestionBankItem(
    organizationId: string,
    id: string,
    restore = false,
  ): Promise<{ id: string; archived: boolean }> {
    return coreAdminHttp.request(`/activity/question-bank/${encodeURIComponent(id)}/archive`, {
      method: 'POST',
      headers: orgHeaders(organizationId),
      body: { restore },
    });
  },

  async analytics(
    organizationId: string,
    params?: { pluginCode?: string; personId?: string },
  ): Promise<{ summary: Record<string, unknown>; recentAttempts: Array<Record<string, unknown>> }> {
    const q = new URLSearchParams();
    if (params?.pluginCode) q.set('pluginCode', params.pluginCode);
    if (params?.personId) q.set('personId', params.personId);
    const qs = q.toString();
    return coreAdminHttp.request(`/activity/analytics${qs ? '?' + qs : ''}`, {
      method: 'GET',
      headers: orgHeaders(organizationId),
    });
  },

  async assignActivity(
    organizationId: string,
    activityId: string,
    payload: {
      targetType: string;
      targetId?: string;
      opensAt?: string;
      dueAt?: string;
      allowLate?: boolean;
    },
  ): Promise<Record<string, unknown>> {
    return coreAdminHttp.request(`/activity/items/${encodeURIComponent(activityId)}/assignments`, {
      method: 'POST',
      headers: orgHeaders(organizationId),
      body: payload,
    });
  },

  async listAssignments(
    organizationId: string,
    targetId?: string,
  ): Promise<{ items: Array<Record<string, unknown>> }> {
    const qs = targetId ? `?targetId=${encodeURIComponent(targetId)}` : '';
    return coreAdminHttp.request(`/activity/assignments${qs}`, {
      method: 'GET',
      headers: orgHeaders(organizationId),
    });
  },
};
