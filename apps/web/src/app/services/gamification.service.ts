import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiClient, Endpoints } from '../core/api';
import type { EarnedBadge, Level, Rank } from './dashboards.service';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

/** Over what stretch the ranking is worked out. */
export type LeaderboardPeriod = 'all_time' | 'year' | 'month' | 'week';

export interface Leaderboard {
  period: LeaderboardPeriod;
  periodLabel: string;
  entries: Rank[];
  /**
   * The caller's own place, when they are not in the entries above.
   *
   * Null when they are, so a screen never draws the same person twice.
   */
  viewer: Rank | null;
}

/** Where points came from, by what earned them. */
export interface PointsBreakdown {
  reason: string;
  label: string;
  points: number;
}

export interface LadderPosition {
  points: number;
  current: Level | null;
  next: Level | null;
  pointsToNext: number | null;
  percentToNext: number;
  atTop: boolean;
}

export interface MyGamification {
  points: number;
  level: LadderPosition;
  breakdown: PointsBreakdown[];
  badges: EarnedBadge[];
  badgeCount: number;
  rank: Rank | null;
}

export interface PointEvent {
  id: string;
  reason: string;
  reasonLabel: string;
  points: number;
  subjectId: string | null;
  earnedAt: string | null;
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * Points, badges, levels and rank.
 *
 * The caller's own figures come from `/me/...` and take no id. The legacy's
 * `get-user-points` took one from the request body and checked nothing, so a
 * learner could read anybody's standing — and `award-points` took one too.
 *
 * The level ladder is configured per organisation and read from the API. Two
 * screens each carried their own hardcoded copy of it, which is how the same
 * person could be a "Progressive" on one page and an "Advanced" on another.
 */
@Injectable({ providedIn: 'root' })
export class GamificationService {
  private readonly api = inject(ApiClient);

  /** Points, level, badges and rank for whoever is asking. */
  mine(): Observable<MyGamification> {
    return this.api.get<MyGamification>(Endpoints.gamification.mine);
  }

  /** Every award, newest first, with what earned it. */
  history(page = 1, perPage = 25): Observable<{ items: PointEvent[]; total: number }> {
    return this.api.get<{ items: PointEvent[]; total: number }>(
      Endpoints.gamification.history,
      { page, perPage },
    );
  }

  leaderboard(period: LeaderboardPeriod = 'all_time', limit = 10): Observable<Leaderboard> {
    return this.api.get<Leaderboard>(Endpoints.gamification.leaderboard, { period, limit });
  }
}
