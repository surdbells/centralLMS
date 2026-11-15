import { Injectable, inject } from '@angular/core';
import { Observable, filter, map } from 'rxjs';
import { ApiClient, Endpoints } from '../core/api';
import {
  appendCreatePeople,
  type CreatePeopleOptions,
  type ImportPersonCannotCreate,
  type ImportPersonToCreate,
} from './users.service';

export type PlanItemStatus = 'not_scheduled' | 'scheduled' | 'in_progress' | 'completed';

/** What kind of training a plan item is; null for a hand-made shared item. */
export type PlanCategory =
  | 'functional' | 'conference' | 'certification' | 'behavioural'
  | 'organisational' | 'leadership' | 'other';

/** Display order of the categories, matching the server's sort_order bands. */
export const PLAN_CATEGORY_ORDER: readonly PlanCategory[] = [
  'functional', 'conference', 'certification', 'behavioural', 'organisational', 'leadership', 'other',
];

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
  category?: PlanCategory | null;
  provider?: string | null;
  scheduleText?: string | null;
  plannedStart?: string | null;
  plannedEnd?: string | null;
  isPersonal?: boolean;
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
  /** Items that belong to one person each (e.g. imported from Excel). */
  personalItemCount?: number;
}

export interface AdminPlanItem {
  id: string;
  course_id: string | null;
  title: string;
  description: string | null;
  is_mandatory: boolean;
  sort_order: number;
  course_title: string | null;
  category?: PlanCategory | null;
  provider?: string | null;
  schedule_text?: string | null;
  planned_start?: string | null;
  planned_end?: string | null;
  source?: 'manual' | 'import';
}

// --- Excel import ------------------------------------------------------------

export type ImportSheetKind = 'plan' | 'placement' | 'empty' | 'unrecognised';

export interface ImportItemCounts { create: number; update: number; unchanged: number; }

export interface ImportWarning { row: number | null; code: string; message: string; }

export interface ImportSheetPreview {
  sheet: string;
  index: number;
  kind: ImportSheetKind;
  importable: boolean;
  year: number | null;
  entity: string | null;
  planTitle: string | null;
  existingPlanId: string | null;
  existingPlanStatus: 'draft' | 'published' | 'archived' | null;
  columns: { index: number; header: string; category: PlanCategory; providerIndex: number | null }[];
  rows: number;
  people: { matched: number; unmatched: number; noEmail: number; duplicate: number };
  matched: { row: number; email: string; userId: string; name: string; userStatus: string; items: ImportItemCounts }[];
  unmatched: { row: number; email: string; name: string }[];
  /** Unmatched rows `createMissing` would turn into invited accounts (always reported). */
  toCreate?: ImportPersonToCreate[];
  /** Unmatched rows that cannot become accounts, with the reason. */
  cannotCreate?: ImportPersonCannotCreate[];
  skipped: { row: number; name: string; reason: 'no_email' | 'duplicate_email' }[];
  items: ImportItemCounts & { stale: number; byCategory: Partial<Record<PlanCategory, ImportItemCounts>> };
  dates: { parsed: number; textOnly: number; none: number };
  unparsedDates: {
    row: number; email: string; category: PlanCategory; title: string; provider: string | null; scheduleText: string | null;
  }[];
  warnings: ImportWarning[];
}

export interface ImportTotals {
  sheets: number;
  importableSheets: number;
  matchedPeople: number;
  unmatchedPeople: number;
  itemsCreate: number;
  itemsUpdate: number;
  itemsUnchanged: number;
  itemsStale: number;
  /** Distinct people (by email, across sheets) `createMissing` would create. */
  toCreate?: number;
  cannotCreate?: number;
}

export interface ImportPreview {
  file: string;
  /** Whether this preview counted the people `createMissing` would create. */
  createMissing?: boolean;
  sheets: ImportSheetPreview[];
  totals: ImportTotals;
}

export interface ImportSheetWritten {
  planId: string | null;
  planCreated: boolean;
  membersAdded: number;
  itemsCreated: number;
  itemsUpdated: number;
  itemsUnchanged: number;
  itemsRemoved: number;
  published: boolean;
  error: string | null;
  /** People created as invited accounts on this sheet (`createMissing`). */
  peopleCreated?: number;
  /** Rows → the account created for them. */
  createdPeople?: { row: number; email: string; userId: string; sheet?: string }[];
}

export interface ImportCommitResult {
  file: string;
  sheets: (ImportSheetPreview & { written?: ImportSheetWritten })[];
  totals: ImportTotals & {
    itemsCreated: number; itemsUpdated: number; itemsRemoved: number; membersAdded: number;
    /** People created across all sheets. */
    peopleCreated?: number;
  };
  /** Everyone created, with the sheet that created them. */
  createdPeople?: { row: number; email: string; userId: string; sheet?: string }[];
  invitationsSent?: number;
  /** User ids whose invitation could not be sent (see `failedInviteEmails`). */
  invitationsFailed?: string[];
}

export interface PersonalItem {
  id: string;
  category: PlanCategory | null;
  title: string;
  provider: string | null;
  scheduleText: string | null;
  plannedStart: string | null;
  plannedEnd: string | null;
  scheduledDate: string | null;
  status: PlanItemStatus;
  courseId: string | null;
  source: 'manual' | 'import';
}

export interface PersonalItemsPerson {
  userId: string;
  name: string;
  email: string;
  department: string | null;
  items: PersonalItem[];
}

export interface PersonalItemsPage {
  people: PersonalItemsPerson[];
  total: number;
  page: number;
  perPage: number;
  categories: Partial<Record<PlanCategory, number>>;
}

export interface PlanMember { id: string; name: string; email: string; assigned_at?: string; }

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
    item: {
      id?: string; courseId?: string | null; title: string; description?: string | null; isMandatory?: boolean; sortOrder?: number;
      userId?: string | null; category?: PlanCategory | null; provider?: string | null; scheduleText?: string | null;
      plannedStart?: string | null; plannedEnd?: string | null;
    },
  ): Observable<AdminPlanItem> {
    return this.api.post<AdminPlanItem>(Endpoints.learningPlans.items(id), item);
  }

  deleteItem(id: string, itemId: string): Observable<void> {
    return this.api.delete<void>(Endpoints.learningPlans.item(id, itemId)).pipe(map(() => undefined));
  }

  assign(id: string, body: { userIds?: string[]; departmentId?: string; everyone?: boolean }): Observable<{ assigned: number }> {
    return this.api.post<{ assigned: number }>(Endpoints.learningPlans.members(id), body);
  }

  members(id: string, perPage = 200): Observable<{ items: PlanMember[]; total: number }> {
    return this.api.get<{ items: PlanMember[]; total: number }>(Endpoints.learningPlans.members(id), { page: 1, perPage });
  }

  /** Per-person items, a page of people at a time. */
  personalItems(
    planId: string,
    q: { userId?: string | null; category?: PlanCategory | null; q?: string | null; page: number; perPage: number },
  ): Observable<PersonalItemsPage> {
    return this.api.get<PersonalItemsPage>(Endpoints.learningPlans.personalItems(planId), {
      userId: q.userId || undefined,
      category: q.category || undefined,
      q: q.q?.trim() || undefined,
      page: q.page,
      perPage: q.perPage,
    });
  }

  // --- Excel import ----------------------------------------------------------

  /** Reads the workbook and reports what an import would do. Writes nothing. */
  previewImport(file: File, createMissing?: boolean): Observable<ImportPreview> {
    const form = new FormData();
    form.append('file', file);
    // With it, people who would be created count as members (items, importable sheets).
    if (createMissing !== undefined) {
      form.append('createMissing', createMissing ? 'true' : 'false');
    }
    return this.api.upload<ImportPreview>(Endpoints.learningPlans.importPreview, form).pipe(
      filter((e): e is typeof e & { result: ImportPreview } => e.state === 'done'),
      map((e) => e.result),
    );
  }

  /** Re-sends the same workbook with the sheets to write; the server re-parses it. */
  commitImport(
    file: File, sheets: string[], publish: boolean, removeMissing: boolean, people?: CreatePeopleOptions,
  ): Observable<ImportCommitResult> {
    const form = new FormData();
    form.append('file', file);
    form.append('sheets', JSON.stringify(sheets));
    form.append('publish', publish ? 'true' : 'false');
    form.append('removeMissing', removeMissing ? 'true' : 'false');
    appendCreatePeople(form, people);
    return this.api.upload<ImportCommitResult>(Endpoints.learningPlans.importCommit, form).pipe(
      filter((e): e is typeof e & { result: ImportCommitResult } => e.state === 'done'),
      map((e) => e.result),
    );
  }
}
