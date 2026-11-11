import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { ApiClient, Endpoints } from '../core/api';

export interface TeamFeedbackRow {
  user_id: string;
  name: string;
  course_id: string;
  course_title: string;
  completed_at: string | null;
  rating: number | null;
  comments: string | null;
}

/** A manager's behaviour-change (L3) feedback on their reports' completed training. */
@Injectable({ providedIn: 'root' })
export class BehaviourFeedbackService {
  private readonly api = inject(ApiClient);

  pending(): Observable<TeamFeedbackRow[]> {
    return this.api.get<TeamFeedbackRow[]>(Endpoints.me.teamFeedback);
  }

  submit(userId: string, courseId: string, rating: number, comments?: string | null): Observable<void> {
    return this.api
      .put<void>(Endpoints.me.teamFeedback, { userId, courseId, rating, comments })
      .pipe(map(() => undefined));
  }
}
