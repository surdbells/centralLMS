import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiClient, Endpoints, type Paged, type UploadProgress } from '../core/api';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

/** 0 None, 1 Knowledge, 2 Skill, 3 Mastery. The ladder a competency is held on. */
export type ProficiencyLevel = 0 | 1 | 2 | 3;

/** How wide a gap is — the same buckets the outcome report counts in. */
export type GapCategory = 'met' | 'minor' | 'major' | 'significant';

export type AssessmentStatus = 'draft' | 'self_assessed' | 'reviewed' | 'finalized';

export interface Competency {
  id: string;
  functionName: string;
  name: string;
  description: string | null;
  /** What each level of this competency looks like. */
  levels: {
    knowledge: string | null;
    skill: string | null;
    mastery: string | null;
  };
  /** How many job profiles require it. Counted, never stored. */
  profileCount: number;
  /** How many assessment items measure it. */
  assessedCount: number;
  createdAt: string | null;
}

export interface CompetencyInput {
  name: string;
  functionName: string;
  description?: string | null;
  knowledgeDescriptor?: string | null;
  skillDescriptor?: string | null;
  masteryDescriptor?: string | null;
}

/** A function — a family of competencies — with what hangs off it. */
export interface CompetencyFunction {
  function: string;
  competencies: number;
  profiles: number;
}

export interface ProfileRequirement {
  competencyId: string;
  competencyName: string | null;
  requiredLevel: ProficiencyLevel;
  requiredLabel: string;
  isCritical: boolean;
}

export interface JobCompetencyProfile {
  id: string;
  jobTitle: string;
  functionName: string;
  description: string | null;
  requirements: ProfileRequirement[];
  competencyCount: number;
  assessmentCount: number;
  createdAt: string | null;
}

export interface JobProfileInput {
  jobTitle: string;
  functionName: string;
  description?: string | null;
  requirements: Array<{
    competencyId: string;
    requiredLevel: ProficiencyLevel;
    isCritical?: boolean;
  }>;
}

export interface AssessmentItem {
  competencyId: string;
  competencyName: string;
  functionName: string;
  requiredLevel: ProficiencyLevel;
  requiredLabel: string;
  selfLevel: ProficiencyLevel | null;
  selfLabel: string | null;
  assessedLevel: ProficiencyLevel | null;
  assessedLabel: string | null;
  lineExecutiveLevel: ProficiencyLevel | null;
  lineExecutiveLabel: string | null;
  /** The demonstrated level: assessed if confirmed, else self, else none. */
  demonstratedLevel: ProficiencyLevel;
  demonstratedLabel: string;
  /** Required minus demonstrated, never below zero. */
  gap: number;
  gapCategory: GapCategory;
  gapLabel: string;
  estimatedMonths?: number;
  isCritical: boolean;
  comment: string | null;
}

export interface CompetencyAssessment {
  id: string;
  userId: string;
  userName: string | null;
  profileId: string;
  jobTitle: string | null;
  functionName: string | null;
  cycle: string;
  status: AssessmentStatus;
  statusLabel: string;
  assessorId: string | null;
  assessorName: string | null;
  assessorComment: string | null;
  /** The share of requirements met — the headline. Null if it measures nothing. */
  readinessPercent: number | null;
  gapCounts: Record<GapCategory, number>;
  /** Estimated months to close every gap, on the standard estimates. */
  estimatedMonths: number;
  /** Sum of required levels vs sum of demonstrated — the report's totals. */
  idealProficiencyTotal?: number;
  assessedProficiencyTotal?: number;
  selfComment?: string | null;
  lineManagerComment?: string | null;
  lineExecutiveDecision?: string | null;
  gapsIdentified?: string | null;
  gapsClosureInterventions?: string | null;
  items: AssessmentItem[];
  selfSubmittedAt: string | null;
  reviewedAt: string | null;
  finalizedAt: string | null;
  createdAt: string | null;
}

/** The header facts about the person a report is for. */
export interface ReportSubject {
  name: string | null;
  email: string | null;
  jobTitle: string | null;
  level: string | null;
  department: string | null;
  division: string | null;
}

/** Everything an individual competency report shows. */
export interface CompetencyReport {
  assessment: CompetencyAssessment;
  subject: ReportSubject;
  /** competencyId -> level descriptors, for the proficiency-description column. */
  descriptors: Record<string, { knowledge: string | null; skill: string | null; mastery: string | null }>;
}

/** The qualitative fields an assessor writes on a report. */
export interface ReportNarrative {
  selfComment?: string;
  assessorComment?: string;
  lineManagerComment?: string;
  lineExecutiveDecision?: string;
  gapsIdentified?: string;
  gapsClosureInterventions?: string;
  /** Line executive's confirming levels, keyed by competency id. */
  lineExecutiveLevels?: Record<string, ProficiencyLevel>;
}

export interface OutcomeReport {
  cycle: string;
  cycles: string[];
  coverage: {
    people: number;
    assessments: number;
    finalized: number;
  };
  gaps: {
    requirements: number;
    met: number;
    minor: number;
    major: number;
    significant: number;
    readiness: number | null;
    estimatedMonths: number;
  };
  byFunction: Array<{
    function: string;
    assessments: number;
    requirements: number;
    met: number;
    gaps: number;
    readiness: number | null;
  }>;
  topGaps: Array<{
    competency: string;
    function: string;
    assessed: number;
    withGap: number;
    avgGap: number;
  }>;
}

export interface CompetencyFilters {
  search?: string;
  function?: string;
  page?: number;
  perPage?: number;
}

export interface ProfileFilters {
  search?: string;
  function?: string;
  page?: number;
  perPage?: number;
}

export interface AssessmentFilters {
  cycle?: string;
  function?: string;
  status?: AssessmentStatus;
  search?: string;
  page?: number;
  perPage?: number;
}

/** Levels keyed by competency id — how a self-assessment or review is submitted. */
export type LevelSubmission = Record<string, ProficiencyLevel>;

/** What one uploaded workbook turned into, or why it could not be read. */
export interface ImportFileResult {
  file: string;
  status: 'imported' | 'legacy_xls' | 'no_competency_sheets' | 'unreadable';
  people?: number;
  functions: Array<{
    function: string;
    competencies: number;
    profiles: Array<{ jobTitle: string; requirements: number }>;
  }>;
}

export interface ImportResult {
  totals: {
    files: number;
    functions: number;
    competencies: number;
    profiles: number;
    requirements: number;
    people: number;
  };
  files: ImportFileResult[];
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * Competency assurance: the dictionary, the job profiles, the assessments,
 * and the outcome that reads across them.
 *
 * The gap between what a job needs and what a person has is never computed
 * here — the API derives it from the levels and hands it back, so a browser
 * that lies about the arithmetic cannot make an unready person look ready.
 */
@Injectable({ providedIn: 'root' })
export class CompetencyService {
  private readonly api = inject(ApiClient);

  // --- the dictionary -----------------------------------------------------

  getCompetencies(filters: CompetencyFilters = {}): Observable<Paged<Competency>> {
    return this.api.getPaged<Competency>(Endpoints.competencies.list, { ...filters });
  }

  getCompetency(id: string): Observable<Competency> {
    return this.api.get<Competency>(Endpoints.competencies.show(id));
  }

  createCompetency(input: CompetencyInput): Observable<Competency> {
    return this.api.post<Competency>(Endpoints.competencies.create, input);
  }

  updateCompetency(id: string, input: Partial<CompetencyInput>): Observable<Competency> {
    return this.api.put<Competency>(Endpoints.competencies.update(id), input);
  }

  deleteCompetency(id: string): Observable<void> {
    return this.api.delete<void>(Endpoints.competencies.delete(id));
  }

  getFunctions(): Observable<CompetencyFunction[]> {
    return this.api.get<CompetencyFunction[]>(Endpoints.competencies.functions);
  }

  /**
   * Imports one or more completed JCP workbooks.
   *
   * The same parse the console importer runs, reached from the browser — each
   * function's dictionary and its job profiles, in one upload.
   */
  importWorkbooks(files: File[]): Observable<UploadProgress<ImportResult>> {
    const form = new FormData();
    for (const file of files) {
      form.append('files[]', file, file.name);
    }
    return this.api.upload<ImportResult>(Endpoints.competencies.import, form);
  }

  // --- job profiles -------------------------------------------------------

  getProfiles(filters: ProfileFilters = {}): Observable<Paged<JobCompetencyProfile>> {
    return this.api.getPaged<JobCompetencyProfile>(Endpoints.jobProfiles.list, { ...filters });
  }

  getProfile(id: string): Observable<JobCompetencyProfile> {
    return this.api.get<JobCompetencyProfile>(Endpoints.jobProfiles.show(id));
  }

  createProfile(input: JobProfileInput): Observable<JobCompetencyProfile> {
    return this.api.post<JobCompetencyProfile>(Endpoints.jobProfiles.create, input);
  }

  updateProfile(id: string, input: JobProfileInput): Observable<JobCompetencyProfile> {
    return this.api.put<JobCompetencyProfile>(Endpoints.jobProfiles.update(id), input);
  }

  deleteProfile(id: string): Observable<void> {
    return this.api.delete<void>(Endpoints.jobProfiles.delete(id));
  }

  // --- assessments --------------------------------------------------------

  getAssessments(filters: AssessmentFilters = {}): Observable<Paged<CompetencyAssessment>> {
    return this.api.getPaged<CompetencyAssessment>(Endpoints.competencyAssessments.list, { ...filters });
  }

  getAssessment(id: string): Observable<CompetencyAssessment> {
    return this.api.get<CompetencyAssessment>(Endpoints.competencyAssessments.show(id));
  }

  /** The caller's own assessments — what a learner self-assesses against. */
  getMyAssessments(cycle?: string): Observable<Paged<CompetencyAssessment>> {
    return this.api.getPaged<CompetencyAssessment>(Endpoints.competencyAssessments.mine, { cycle });
  }

  /** The full individual report for one assessment. */
  getReport(id: string): Observable<CompetencyReport> {
    return this.api.get<CompetencyReport>(Endpoints.competencyAssessments.report(id));
  }

  /** Saves the qualitative side of a report — the assessor's work. */
  saveReport(id: string, narrative: ReportNarrative): Observable<CompetencyAssessment> {
    return this.api.put<CompetencyAssessment>(Endpoints.competencyAssessments.report(id), narrative);
  }

  /** Starts an assessment for a person against a job profile. */
  startAssessment(userId: string, profileId: string, cycle?: string): Observable<CompetencyAssessment> {
    return this.api.post<CompetencyAssessment>(Endpoints.competencyAssessments.create, {
      userId,
      profileId,
      cycle,
    });
  }

  /**
   * Assigns a profile to many people at once — the bulk link, so each person
   * sees the assessment on their self-assessment. Those already assigned are
   * skipped rather than failed.
   */
  assignProfile(
    profileId: string,
    userIds: string[],
    cycle?: string,
  ): Observable<{ created: number; skipped: number; total: number }> {
    return this.api.post<{ created: number; skipped: number; total: number }>(
      Endpoints.competencyAssessments.bulk,
      { profileId, userIds, cycle },
    );
  }

  /** The learner records their own levels and overall comment, moving it to self-assessed. */
  selfAssess(id: string, levels: LevelSubmission, comment?: string): Observable<CompetencyAssessment> {
    return this.api.post<CompetencyAssessment>(Endpoints.competencyAssessments.self(id), { levels, comment });
  }

  /** The expert confirms the levels, moving it to reviewed. */
  review(id: string, levels: LevelSubmission): Observable<CompetencyAssessment> {
    return this.api.post<CompetencyAssessment>(Endpoints.competencyAssessments.review(id), { levels });
  }

  finalize(id: string, comment?: string): Observable<CompetencyAssessment> {
    return this.api.post<CompetencyAssessment>(Endpoints.competencyAssessments.finalize(id), { comment });
  }

  deleteAssessment(id: string): Observable<void> {
    return this.api.delete<void>(Endpoints.competencyAssessments.delete(id));
  }

  // --- the outcome report -------------------------------------------------

  getOutcome(cycle?: string): Observable<OutcomeReport> {
    return this.api.get<OutcomeReport>(Endpoints.competencyAssessments.outcome, cycle ? { cycle } : {});
  }
}
