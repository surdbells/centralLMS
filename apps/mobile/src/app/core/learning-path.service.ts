import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { ApiClient } from './api';

export interface PathProgress {
  total: number;
  required: number;
  completed: number;
  requiredCompleted: number;
  percent: number;
  isComplete: boolean;
  pointsEarned: number;
  pointsAvailable: number;
}

/** A path in the catalogue (GET /paths/catalogue). */
export interface PathSummary {
  id: string;
  title: string;
  summary: string | null;
  thumbnailUrl: string | null;
  level: string | null;
  pacingLabel: string | null;
  categoryName: string | null;
  estimatedHours: number | null;
  itemCount: number;
  enrolmentCount: number;
  isMandatory: boolean;
  progress?: PathProgress | null;
}

/** One of the learner's path enrolments (GET /me/paths). */
export interface PathEnrolment {
  id: string;
  pathId: string;
  pathTitle: string;
  status: string;
  isMandatory: boolean;
  dueDate: string | null;
  isOverdue: boolean;
  completedAt: string | null;
  progress: PathProgress;
}

export interface PathSection {
  id: string;
  title: string;
  description: string | null;
  sortOrder: number;
  isMilestone: boolean;
  minItemsToComplete: number | null;
  itemCount: number;
}

/** GET /me/paths/{id} — the path with the learner's enrolment and progress. */
export interface PathDetail {
  id: string;
  title: string;
  summary: string | null;
  description: string | null;
  level: string | null;
  pacingLabel: string | null;
  categoryName: string | null;
  estimatedHours: number | null;
  itemCount: number;
  isMandatory: boolean;
  objectives: string[];
  enrollment: { id: string; status: string } | null;
  sections: PathSection[];
  progress: PathProgress;
}

/** Learning paths, as a learner sees them. */
@Injectable({ providedIn: 'root' })
export class LearningPathService {
  private readonly api = inject(ApiClient);

  /** The catalogue of paths available to enrol in. */
  catalogue(search?: string): Observable<PathSummary[]> {
    return this.api
      .getPaged<PathSummary>('/paths/catalogue', { search, perPage: 100 })
      .pipe(map((page) => page.items));
  }

  /** The paths this learner is enrolled in. */
  mine(): Observable<PathEnrolment[]> {
    return this.api.get<PathEnrolment[]>('/me/paths');
  }

  /** One path in full, with this learner's enrolment and progress. */
  detail(id: string): Observable<PathDetail> {
    return this.api.get<PathDetail>(`/me/paths/${id}`);
  }

  /** Put the learner on a path. */
  enroll(id: string): Observable<void> {
    return this.api.post(`/me/paths/${id}/enroll`, {}).pipe(map(() => undefined));
  }
}
