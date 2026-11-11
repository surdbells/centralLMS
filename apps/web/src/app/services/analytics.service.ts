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
  };
  satisfaction: {
    average: number;
    count: number;
    distribution: Record<number, number>;
    top: RatedCourse[];
    low: RatedCourse[];
  };
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
}
