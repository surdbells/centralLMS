import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiClient, Endpoints } from '../core/api';

export interface ActivePoint {
  period: string;
  learners: number;
  sessions: number;
}

export interface DeviceUsage {
  device: string;
  sessions: number;
  learners: number;
}

export interface EngagementAnalytics {
  from: string;
  to: string;
  days: number;
  totals: { learners: number; sessions: number; averageMinutes: number };
  login: { signIns: number; learners: number; averagePerLearner: number };
  devices: DeviceUsage[];
  daily: ActivePoint[];
  weekly: ActivePoint[];
  monthly: ActivePoint[];
}

export interface RatedCourse {
  courseId: string;
  title: string;
  average: number;
  reviews: number;
}

export interface AnalyticsSummary {
  days: number;
  compliance: { completed: number; inProgress: number; yetToAttempt: number; total: number };
  adoption: {
    people: number;
    activated: number;
    neverSignedIn: number;
    newUsers: number;
    newActivated: number;
    adoptionRate: number;
    firstTimeLoginRate: number;
    activeLearners: number;
    activeLearnerRate: number;
    avgCoursesPerEmployee: number;
  };
  assessment: { attempts: number; learners: number; averageScore: number; passRate: number };
  competency: { items: number; met: number; rate: number };
  competencyTrend: { cycle: string; items: number; met: number; rate: number; avgGap: number }[];
  engagement: { forum: { posts: number; participants: number }; avgActiveDays: number };
  satisfaction: {
    average: number;
    count: number;
    distribution: Record<number, number>;
    top: RatedCourse[];
    low: RatedCourse[];
  };
  behaviour: {
    average: number;
    count: number;
    distribution: Record<number, number>;
  };
}

export interface FieldSlice {
  value: string;
  enrolled: number;
  completed: number;
  rate: number;
}

export interface ByFieldAnalytics {
  fields: { key: string; label: string }[];
  field: string | null;
  rows: FieldSlice[];
}

/** Admin learner-analytics: engagement (sessions/device/login) and the summary. */
@Injectable({ providedIn: 'root' })
export class AnalyticsService {
  private readonly api = inject(ApiClient);

  engagement(days = 30): Observable<EngagementAnalytics> {
    return this.api.get<EngagementAnalytics>(Endpoints.analytics.engagement, { days });
  }

  summary(days = 30): Observable<AnalyticsSummary> {
    return this.api.get<AnalyticsSummary>(Endpoints.analytics.summary, { days });
  }

  /** Enrolment/completion sliced by a custom field (Division/Subsidiary/Entity). */
  byField(field?: string): Observable<ByFieldAnalytics> {
    return this.api.get<ByFieldAnalytics>(Endpoints.analytics.byField, field ? { field } : undefined);
  }

  /** The caller's own engagement, for their report. */
  myEngagement(days = 30): Observable<MyEngagement> {
    return this.api.get<MyEngagement>(Endpoints.me.engagement, { days });
  }
}

export interface MyEngagement {
  sessions: number;
  averageMinutes: number;
  signIns: number;
  devices: DeviceUsage[];
  days?: number;
}
