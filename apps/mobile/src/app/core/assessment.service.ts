import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiClient } from './api';

/** An assessment as it appears in the learner's catalogue (GET /me/assessments). */
export interface Assessment {
  assessmentId: string;
  title: string;
  description: string | null;
  kind: string; // quiz | exam | assignment | survey
  categoryName: string | null;
  courseTitle: string | null;
  questionCount: number;
  totalPoints: number;
  timeLimitMinutes: number | null;
  maxAttempts: number | null;
  passingScore: number | null;
  passingType: string | null;
  proctoringLevel: string; // none | ...
  difficulty: string | null;
  availableFrom: string | null;
  availableUntil: string | null;
  /** The learner's standing: not_started | in_progress | passed | failed. */
  enrollmentStatus: string;
  attemptCount: number;
  bestScore: number | null;
  isMandatory: boolean;
  dueDate: string | null;
}

/** One of the learner's attempts (GET /me/attempts). */
export interface Attempt {
  id: string;
  assessmentId: string;
  assessmentTitle: string;
  attemptNumber: number;
  status: string;
  statusLabel: string;
  rawScore: number | null;
  maxScore: number | null;
  percentage: number | null;
  passed: boolean | null;
  timeSpentSeconds: number;
  startedAt: string | null;
  submittedAt: string | null;
  gradedAt: string | null;
}

/** Assessments and attempts, as a learner sees them. */
@Injectable({ providedIn: 'root' })
export class AssessmentService {
  private readonly api = inject(ApiClient);

  /** The assessments available to this learner. */
  catalogue(): Observable<Assessment[]> {
    return this.api.get<Assessment[]>('/me/assessments');
  }

  /** This learner's attempts across all assessments, newest first. */
  attempts(): Observable<Attempt[]> {
    return this.api.get<Attempt[]>('/me/attempts');
  }
}
