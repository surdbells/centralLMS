// course-player/services/scorm-adapter.service.ts

import { Injectable } from '@angular/core';

/**
 * SCORM 1.2 / 2004 helpers for the course player.
 *
 * This used to be a full runtime that put `window.API` and `window.API_1484_11`
 * on the application's own window. Nothing ever found it: a package runs in an
 * iframe served from the API's origin, inside a wrapper page that carries its
 * own runtime (ScormContentAction), and the scan a package does for `API` walks
 * up that wrapper, never across into the app. Worse, it was initialised without
 * any saved data and committed nothing (its dirty flag was never set), so it
 * was the reason a package always restarted from slide one while looking like
 * the thing that should have resumed it.
 *
 * What is left is the part that is genuinely the app's job, as pure functions:
 *
 *  - **reading** a CMI map the wrapper posts — in either SCORM dialect — into
 *    the small, whitelisted state the API stores (location, suspend data,
 *    status, exit, total time), plus the score and a progress percentage;
 *  - **seeding** the wrapper with that state when the learner comes back, so
 *    `cmi.core.entry` reads `resume` and the package jumps to where they were.
 *
 * The wrapper fills the full CMI defaults itself from the seed; this side only
 * decides what the learner's saved state is.
 */

export interface ScormData {
  [key: string]: string | number | boolean;
}

export interface ScormCommitResult {
  success: boolean;
  error?: string;
}

/** The SCORM runtime state the API keeps per lesson (`lesson_progress.scorm_state`). */
export interface ScormState {
  location: string;
  suspendData: string;
  lessonStatus: string;
  exit: string;
  totalTime: string;
}

/** What the wrapper is seeded with before it launches the package. */
export interface ScormSeed {
  location: string;
  suspendData: string;
  lessonStatus: string;
}

/**
 * A state update for the API. Location and suspend data are optional: a field
 * over the API's limit is left out (not blanked), so the server's key-by-key
 * merge keeps the last good value instead of wiping the learner's bookmark.
 */
export type ScormStateUpdate = Omit<ScormState, 'location' | 'suspendData'>
  & Partial<Pick<ScormState, 'location' | 'suspendData'>>;

/** A CMI map, read: what to store, and what it means for progress. */
export interface ScormSnapshot {
  state: ScormStateUpdate;
  score?: number;
  /** 0–100, from the status — SCORM has no percentage of its own. */
  progress: number;
  /** Completed or passed, in either dialect. */
  complete: boolean;
}

/**
 * The API's limits. Over-limit values are rejected, which would fail the whole
 * progress write — so an oversized field is dropped rather than sent (and
 * rather than truncated: half a suspend blob is corrupt, not shorter).
 */
export const SCORM_LIMITS = { location: 1000, suspendData: 64000, short: 64 } as const;

/** The stored SCORM state from a lesson-progress payload, or null when none. */
export function toScormState(raw: unknown): ScormState | null {
  if (raw === null || typeof raw !== 'object') {
    return null;
  }

  const r = raw as Record<string, unknown>;
  const text = (v: unknown) => (v === null || v === undefined ? '' : String(v));

  return {
    location: text(r['location']),
    suspendData: text(r['suspendData']),
    lessonStatus: text(r['lessonStatus']),
    exit: text(r['exit']),
    totalTime: text(r['totalTime']),
  };
}

/**
 * Reads a CMI map from either dialect into what the API stores.
 *
 * SCORM 2004 splits 1.2's single `lesson_status` into `completion_status` and
 * `success_status`; the stored status is kept in 1.2's vocabulary because that
 * is what the wrapper seeds `cmi.core.lesson_status` with, and it derives the
 * 2004 pair from it.
 */
export function scormSnapshot(cmi: Record<string, unknown>): ScormSnapshot {
  const read = (...keys: string[]): string => {
    for (const key of keys) {
      const value = cmi[key];
      if (value !== undefined && value !== null && String(value) !== '') {
        return String(value);
      }
    }
    return '';
  };

  const lessonStatus12 = read('cmi.core.lesson_status').toLowerCase();
  const completion = read('cmi.completion_status').toLowerCase();
  const success = read('cmi.success_status').toLowerCase();

  // The wrapper seeds cmi.core.lesson_status ('not attempted') for every
  // package, so for a 2004 package that key is only the seed: prefer what the
  // package actually reported in the 2004 model.
  const status2004 = success === 'passed' || success === 'failed'
    ? success
    : completion === 'completed' || completion === 'incomplete'
      ? completion
      : '';
  const lessonStatus = (lessonStatus12 === '' || lessonStatus12 === 'not attempted') && status2004 !== ''
    ? status2004
    : lessonStatus12;

  const complete = ['completed', 'passed'].includes(lessonStatus)
    || completion === 'completed'
    || success === 'passed';

  const started = lessonStatus !== '' && lessonStatus !== 'not attempted';

  const location = read('cmi.location', 'cmi.core.lesson_location');
  const suspendData = read('cmi.suspend_data');
  const short = (v: string) => v.slice(0, SCORM_LIMITS.short);

  // The API takes a 0–100 score and rejects anything else — which would fail
  // the whole write, resume state included. Packages score on their own scale
  // (2004's scaled -1..1, or raw against min/max), so normalise; a score that
  // can't be read as a percentage is left out rather than sent.
  const num = (...keys: string[]) => { const v = read(...keys); return v === '' ? NaN : Number(v); };
  const scaled = num('cmi.score.scaled');
  const raw = num('cmi.score.raw', 'cmi.core.score.raw');
  const min = num('cmi.score.min', 'cmi.core.score.min');
  const max = num('cmi.score.max', 'cmi.core.score.max');
  let pct = NaN;
  if (Number.isFinite(scaled)) {
    pct = scaled * 100;
  } else if (Number.isFinite(raw) && Number.isFinite(max) && max > (Number.isFinite(min) ? min : 0)) {
    const lo = Number.isFinite(min) ? min : 0;
    pct = ((raw - lo) / (max - lo)) * 100;
  } else if (Number.isFinite(raw) && raw >= 0 && raw <= 100) {
    pct = raw;
  }
  const score = Number.isFinite(pct) ? Math.round(Math.min(100, Math.max(0, pct)) * 100) / 100 : undefined;

  const state: ScormStateUpdate = {
    lessonStatus: short(lessonStatus),
    exit: short(read('cmi.exit', 'cmi.core.exit')),
    // Packages report session time far more often than total time; either
    // is a fair record of "how long", and the stored field is informational.
    totalTime: short(read('cmi.total_time', 'cmi.core.total_time', 'cmi.session_time', 'cmi.core.session_time')),
  };
  // Over the limit: leave the key out so the stored value survives.
  if (location.length <= SCORM_LIMITS.location) { state.location = location; }
  if (suspendData.length <= SCORM_LIMITS.suspendData) { state.suspendData = suspendData; }

  return {
    state,
    score,
    // Started but unfinished counts as halfway: the nearest honest reading of
    // a runtime that only reports a status, and enough to move the lesson.
    progress: complete ? 100 : started || location !== '' || suspendData !== '' ? 50 : 0,
    complete,
  };
}

/** The seed for the wrapper, or null for a first attempt (it launches fresh). */
export function scormSeed(state: ScormState | null | undefined): ScormSeed | null {
  if (!state) {
    return null;
  }

  const location = state.location ?? '';
  const suspendData = state.suspendData ?? '';
  if (location === '' && suspendData === '' && state.lessonStatus === '') {
    return null;
  }

  return { location, suspendData, lessonStatus: state.lessonStatus };
}

/**
 * Kept as an injectable so existing imports (and the public barrel) still
 * resolve; it is a thin face over the functions above.
 */
@Injectable({
  providedIn: 'root'
})
export class ScormAdapterService {
  snapshot(cmi: Record<string, unknown>): ScormSnapshot {
    return scormSnapshot(cmi);
  }

  seed(state: ScormState | null | undefined): ScormSeed | null {
    return scormSeed(state);
  }
}
