import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import type {
  AssessmentItem,
  AssessmentVersion,
  AssessmentAttempt,
  QuestionBankItem,
  AssessmentResult,
} from '../activityTypes';

export const assessmentApi = {
  async listAssessments(params?: { status?: string; search?: string }): Promise<{ items: AssessmentItem[] }> {
    const q = new URLSearchParams();
    if (params?.status) q.set('status', params.status);
    if (params?.search) q.set('search', params.search);
    const qs = q.toString();
    return coreAdminHttp.get(qs ? `/education/assessments?${qs}` : '/education/assessments');
  },

  async getAssessment(id: string): Promise<AssessmentItem & AssessmentVersion> {
    return coreAdminHttp.get(`/education/assessments/${id}`);
  },

  async createAssessment(data: Record<string, unknown>): Promise<AssessmentItem & AssessmentVersion> {
    return coreAdminHttp.post('/education/assessments', data);
  },

  async updateAssessment(id: string, data: Record<string, unknown>): Promise<AssessmentItem & AssessmentVersion> {
    return coreAdminHttp.put(`/education/assessments/${id}`, data);
  },

  async publishAssessment(id: string, data?: Record<string, unknown>): Promise<AssessmentItem & AssessmentVersion> {
    return coreAdminHttp.post(`/education/assessments/${id}/publish`, data ?? {});
  },

  async listVersions(assessmentId: string): Promise<AssessmentVersion[]> {
    return coreAdminHttp.get(`/education/assessments/${assessmentId}/versions`);
  },

  async listQuestionBank(params?: { category?: string; difficulty?: number; search?: string }): Promise<{ items: QuestionBankItem[] }> {
    const q = new URLSearchParams();
    if (params?.category) q.set('category', params.category);
    if (params?.difficulty !== undefined) q.set('difficulty', String(params.difficulty));
    if (params?.search) q.set('search', params.search);
    const qs = q.toString();
    return coreAdminHttp.get(qs ? `/education/question-bank?${qs}` : '/education/question-bank');
  },

  async createQuestionBankItem(data: Record<string, unknown>): Promise<QuestionBankItem> {
    return coreAdminHttp.post('/education/question-bank', data);
  },

  async deleteQuestionBankItem(id: string): Promise<{ deleted: boolean }> {
    return coreAdminHttp.delete(`/education/question-bank/${id}`);
  },

  async listStudentAssessments(): Promise<{ items: (AssessmentItem & { versionId: string; studentAttemptsCount: number; bestPercentage: number | null })[] }> {
    return coreAdminHttp.get('/education/student/assessments');
  },

  async startStudentAssessment(assessmentId: string, data?: Record<string, unknown>): Promise<AssessmentAttempt & { title: string; instructions: string; questions: unknown[]; timeLimitSeconds: number | null; passingScorePercent: number }> {
    return coreAdminHttp.post(`/education/student/assessments/${assessmentId}/start`, data ?? {});
  },

  async getStudentAssessmentAttempt(attemptId: string): Promise<AssessmentAttempt & { title: string; instructions: string; questions: unknown[]; timeLimitSeconds: number | null; passingScorePercent: number }> {
    return coreAdminHttp.get(`/education/student/assessment-attempts/${attemptId}`);
  },

  async saveStudentAssessmentAttempt(attemptId: string, data: { answers: Record<string, unknown> }): Promise<{ saved: boolean }> {
    return coreAdminHttp.put(`/education/student/assessment-attempts/${attemptId}/save`, data);
  },

  async submitStudentAssessmentAttempt(attemptId: string, data: { answers: Record<string, unknown>; elapsedSeconds: number }): Promise<AssessmentAttempt & { result: AssessmentResult }> {
    return coreAdminHttp.post(`/education/student/assessment-attempts/${attemptId}/submit`, data);
  },

  async listAssessmentAttempts(assessmentId: string): Promise<Array<AssessmentAttempt & { studentName: string; versionNumber: number }>> {
    return coreAdminHttp.get(`/education/assessments/${assessmentId}/attempts`);
  },

  async reviewAssessmentAttempt(attemptId: string, data: { manualReviewNotes?: string; score?: number | null; percentage?: number; passed?: boolean }): Promise<{ success: boolean }> {
    return coreAdminHttp.post(`/education/assessment-attempts/${attemptId}/review`, data);
  },

  async reviewAttempt(attemptId: string, data: { manualReviewNotes?: string; score?: number | null; percentage?: number; passed?: boolean }): Promise<{ success: boolean }> {
    return this.reviewAssessmentAttempt(attemptId, data);
  },
};
