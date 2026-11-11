import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { ApiClient, Endpoints } from '../core/api';

export type PlanItemStatus = 'not_scheduled' | 'scheduled' | 'in_progress' | 'completed';

export interface PlanItem {
  id: string;
  courseId: string | null;
  title: string;
  description: string | null;
  isMandatory: boolean;
  isCourse: boolean;
  status: PlanItemStatus;
  scheduledDate: string | null;
  progress: number | null;
}

export interface LearnerPlan {
  id: string;
  year: number;
  title: string;
  description: string | null;
  items: PlanItem[];
}

export interface PlanCompletion {
  total: number;
  completed: number;
  rate: number;
  byYear: { year: number; total: number; completed: number; rate: number }[];
}

export interface LearningPlan {
  id: string;
  year: number;
  title: string;
  description: string | null;
  status: 'draft' | 'published' | 'archived';
  itemCount: number;
  memberCount: number;
}

export interface AdminPlanItem {
  id: string;
  course_id: string | null;
  title: string;
  description: string | null;
  is_mandatory: boolean;
  sort_order: number;
  course_title: string | null;
}

@Injectable({ providedIn: 'root' })
export class LearningPlansService {
  private readonly api = inject(ApiClient);

  // --- learner -------------------------------------------------------------

  mine(): Observable<{ plans: LearnerPlan[]; completion: PlanCompletion }> {
    return this.api.get<{ plans: LearnerPlan[]; completion: PlanCompletion }>(Endpoints.me.learningPlans);
  }

  schedule(itemId: string, date: string | null): Observable<void> {
    return this.api.put<void>(Endpoints.me.planSchedule(itemId), { date }).pipe(map(() => undefined));
  }

  setMyItemStatus(itemId: string, status: PlanItemStatus): Observable<void> {
    return this.api.put<void>(Endpoints.me.planItemStatus(itemId), { status }).pipe(map(() => undefined));
  }

  // --- admin ---------------------------------------------------------------

  list(year?: number): Observable<LearningPlan[]> {
    return this.api.get<LearningPlan[]>(Endpoints.learningPlans.list, year ? { year } : undefined);
  }

  create(body: { year: number; title: string; description?: string | null }): Observable<LearningPlan> {
    return this.api.post<LearningPlan>(Endpoints.learningPlans.list, body);
  }

  show(id: string): Observable<LearningPlan & { items: AdminPlanItem[] }> {
    return this.api.get<LearningPlan & { items: AdminPlanItem[] }>(Endpoints.learningPlans.one(id));
  }

  update(id: string, body: { year: number; title: string; description?: string | null }): Observable<LearningPlan> {
    return this.api.put<LearningPlan>(Endpoints.learningPlans.one(id), body);
  }

  setStatus(id: string, status: 'draft' | 'published' | 'archived'): Observable<LearningPlan> {
    return this.api.patch<LearningPlan>(Endpoints.learningPlans.status(id), { status });
  }

  remove(id: string): Observable<void> {
    return this.api.delete<void>(Endpoints.learningPlans.one(id)).pipe(map(() => undefined));
  }

  items(id: string): Observable<AdminPlanItem[]> {
    return this.api.get<AdminPlanItem[]>(Endpoints.learningPlans.items(id));
  }

  saveItem(
    id: string,
    item: { id?: string; courseId?: string | null; title: string; description?: string | null; isMandatory?: boolean; sortOrder?: number },
  ): Observable<AdminPlanItem> {
    return this.api.post<AdminPlanItem>(Endpoints.learningPlans.items(id), item);
  }

  deleteItem(id: string, itemId: string): Observable<void> {
    return this.api.delete<void>(Endpoints.learningPlans.item(id, itemId)).pipe(map(() => undefined));
  }

  assign(id: string, body: { userIds?: string[]; departmentId?: string; everyone?: boolean }): Observable<{ assigned: number }> {
    return this.api.post<{ assigned: number }>(Endpoints.learningPlans.members(id), body);
  }
}
