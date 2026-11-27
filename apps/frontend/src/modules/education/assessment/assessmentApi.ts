import { getActivePinia } from 'pinia';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { studentSession } from '../student/session';
import { useEducationWorkspace } from '../workspace';
import type {
  AssessmentItem,
  AssessmentVersion,
  AssessmentAttempt,
  QuestionBankItem,
  AssessmentResult,
} from '../activityTypes';

function resolveOrganizationId(): string {
  if (studentSession.organizationId) {
    return studentSession.organizationId;
  }
  try {
    if (getActivePinia()) {
      const workspace = useEducationWorkspace();
      if (workspace.organizationId) {
        return workspace.organizationId;
      }
    }
  } catch {
    // Pinia store inactive in isolated component tests
  }
  return '';
}

async function api<T>(path: string, method: 'GET' | 'POST' | 'PUT' | 'DELETE' = 'GET', body?: unknown): Promise<T> {
  const organizationId = resolveOrganizationId();
  return coreAdminHttp.request<T>(`/education${path}`, {
    method,
    ...(organizationId ? { headers: { 'X-Education-Organization': organizationId } } : {}),
    ...(body === undefined ? {} : { body }),
  });
}

export const assessmentApi = {
  async listAssessments(params?: { status?: string; search?: string }): Promise<{ items: AssessmentItem[] }> {
    const q = new URLSearchParams();
    if (params?.status) q.set('status', params.status);
    if (params?.search) q.set('search', params.search);
    const qs = q.toString();
    return api<{ items: AssessmentItem[] }>(`/assessments${qs ? '?' + qs : ''}`);
  },

  async getAssessment(id: string): Promise<AssessmentItem & AssessmentVersion> {
    return api<AssessmentItem & AssessmentVersion>(`/assessments/${encodeURIComponent(id)}`);
  },

  async createAssessment(data: Record<string, unknown>): Promise<AssessmentItem & AssessmentVersion> {
    return api<AssessmentItem & AssessmentVersion>('/assessments', 'POST', data);
  },

  async updateAssessment(id: string, data: Record<string, unknown>): Promise<AssessmentItem & AssessmentVersion> {
    return api<AssessmentItem & AssessmentVersion>(`/assessments/${encodeURIComponent(id)}`, 'PUT', data);
  },

  async publishAssessment(id: string, data?: Record<string, unknown>): Promise<AssessmentItem & AssessmentVersion> {
    return api<AssessmentItem & AssessmentVersion>(`/assessments/${encodeURIComponent(id)}/publish`, 'POST', data ?? {});
  },

  async listVersions(assessmentId: string): Promise<AssessmentVersion[]> {
    return api<AssessmentVersion[]>(`/assessments/${encodeURIComponent(assessmentId)}/versions`);
  },

  async listQuestionBank(params?: { category?: string; difficulty?: number; search?: string }): Promise<{ items: QuestionBankItem[] }> {
    const q = new URLSearchParams();
    if (params?.category) q.set('category', params.category);
    if (params?.difficulty) q.set('difficulty', String(params.difficulty));
    if (params?.search) q.set('search', params.search);
    const qs = q.toString();
    return api<{ items: QuestionBankItem[] }>(`/question-bank${qs ? '?' + qs : ''}`);
  },

  async createQuestionBankItem(data: Record<string, unknown>): Promise<QuestionBankItem> {
    return api<QuestionBankItem>('/question-bank', 'POST', data);
  },

  async deleteQuestionBankItem(id: string): Promise<{ deleted: boolean }> {
    return api<{ deleted: boolean }>(`/question-bank/${encodeURIComponent(id)}`, 'DELETE');
  },

  async listStudentAssessments(): Promise<{ items: (AssessmentItem & { versionId: string; studentAttemptsCount: number; bestPercentage: number | null })[] }> {
    return api<{ items: (AssessmentItem & { versionId: string; studentAttemptsCount: number; bestPercentage: number | null })[] }>('/student/assessments');
  },

  async startStudentAssessment(assessmentId: string, data?: Record<string, unknown> | string): Promise<AssessmentAttempt & { title: string; instructions: string; questions: unknown[]; timeLimitSeconds: number | null; passingScorePercent: number }> {
    const payload = typeof data === 'string' ? { assignmentId: data } : (data ?? {});
    return api<AssessmentAttempt & { title: string; instructions: string; questions: unknown[]; timeLimitSeconds: number | null; passingScorePercent: number }>(`/student/assessments/${encodeURIComponent(assessmentId)}/start`, 'POST', payload);
  },

  async getStudentAssessmentAttempt(attemptId: string): Promise<AssessmentAttempt & { title: string; instructions: string; questions: unknown[]; timeLimitSeconds: number | null; passingScorePercent: number }> {
    return api<AssessmentAttempt & { title: string; instructions: string; questions: unknown[]; timeLimitSeconds: number | null; passingScorePercent: number }>(`/student/assessment-attempts/${encodeURIComponent(attemptId)}`);
  },

  async saveStudentAssessmentAttempt(attemptId: string, data: { answers: Record<string, unknown>; revision?: number }): Promise<{ saved: boolean; revision?: number }> {
    return api<{ saved: boolean; revision?: number }>(`/student/assessment-attempts/${encodeURIComponent(attemptId)}/save`, 'PUT', data);
  },

  async submitStudentAssessmentAttempt(attemptId: string, data: { answers: Record<string, unknown>; elapsedSeconds: number; revision?: number }): Promise<AssessmentAttempt & { result: AssessmentResult }> {
    return api<AssessmentAttempt & { result: AssessmentResult }>(`/student/assessment-attempts/${encodeURIComponent(attemptId)}/submit`, 'POST', data);
  },

  async listAssessmentAttempts(assessmentId: string): Promise<Array<AssessmentAttempt & { studentName: string; versionNumber: number }>> {
    return api<Array<AssessmentAttempt & { studentName: string; versionNumber: number }>>(`/assessments/${encodeURIComponent(assessmentId)}/attempts`);
  },

  async reviewAssessmentAttempt(attemptId: string, data: { manualReviewNotes?: string; score?: number; percentage?: number; passed?: boolean }): Promise<{ success: boolean }> {
    return api<{ success: boolean }>(`/assessment-attempts/${encodeURIComponent(attemptId)}/review`, 'POST', data);
  },

  async reviewAttempt(attemptId: string, data: { manualReviewNotes?: string; score?: number; percentage?: number; passed?: boolean }): Promise<{ success: boolean }> {
    return api<{ success: boolean }>(`/assessment-attempts/${encodeURIComponent(attemptId)}/review`, 'POST', data);
  },
};

