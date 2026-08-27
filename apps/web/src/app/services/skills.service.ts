import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiClient, Endpoints, type Paged } from '../core/api';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export type Proficiency = 'beginner' | 'intermediate' | 'advanced' | 'expert';

export interface SkillCategory {
  id: string;
  name: string;
  description: string | null;
  colour: string;
  isActive: boolean;
  skillCount: number;
  createdAt: string | null;
}

export interface Skill {
  id: string;
  name: string;
  description: string | null;
  level: Proficiency;
  levelLabel: string;
  icon: string | null;
  colour: string;
  isActive: boolean;
  categoryId: string | null;
  categoryName: string | null;
  categoryColour: string | null;
  /** How many courses teach it. Counted, never stored. */
  courseCount: number;
  /** How many people have finished at least one of them. */
  learnerCount: number;
  /** How many are enrolled on one and have not finished any yet. */
  learnersInProgress: number;
  createdAt: string | null;
}

/** A skill somebody holds, or is on the way to holding. */
export interface LearnerSkill {
  skillId: string;
  name: string;
  description: string | null;
  icon: string | null;
  colour: string;
  categoryId: string | null;
  categoryName: string | null;
  categoryColour: string | null;
  proficiency: Proficiency;
  proficiencyLabel: string;
  isHeld: boolean;
  coursesCompleted: number;
  coursesInProgress: number;
  coursesAvailable: number;
  percentComplete: number;
  acquiredAt: string | null;
}

export interface LearnerSkillsByCategory {
  categoryId: string | null;
  categoryName: string;
  categoryColour: string;
  skills: LearnerSkill[];
}

/**
 * What one person holds and what they are working towards.
 *
 * One call. The legacy had five endpoints for this — `my-skills`,
 * `my-skills-by-category`, `my-skill-progress`, `user-skills` and
 * `user-skill-progress` — which were near-identical queries that disagreed
 * about whether an in-progress course counted towards proficiency.
 */
export interface LearnerSkills {
  held: LearnerSkill[];
  inProgress: LearnerSkill[];
  byCategory: LearnerSkillsByCategory[];
  totals: { held: number; inProgress: number };
}

export interface CourseSkill {
  skillId: string;
  name: string;
  description: string | null;
  icon: string | null;
  colour: string;
  categoryName: string | null;
  categoryColour: string | null;
  proficiency: Proficiency;
  isPrimary: boolean;
}

export interface SkillCourse {
  courseId: string;
  title: string;
  status: string;
  durationMinutes: number | null;
  thumbnailUrl: string | null;
  proficiency: Proficiency;
  isPrimary: boolean;
  completions: number;
}

export interface SkillHolder {
  userId: string;
  fullName: string | null;
  email: string;
  avatarUrl: string | null;
  courses: number;
  proficiency: Proficiency;
  acquiredAt: string | null;
}

export interface SkillStatistics {
  skills: number;
  activeSkills: number;
  categories: number;
  coursesMapped: number;
  /** How many people hold at least one skill. */
  learnersWithSkills: number;
  /** Skills the organisation has declared and has no way to give anybody. */
  untaught: Array<{ skillId: string; name: string; colour: string }>;
  mostHeld: Array<{ skillId: string; name: string; colour: string; learners: number }>;
}

export interface SkillFilters {
  search?: string;
  categoryId?: string;
  level?: Proficiency;
  active?: boolean;
  page?: number;
  perPage?: number;
}

export interface SkillInput {
  name: string;
  description?: string | null;
  level?: Proficiency;
  categoryId?: string | null;
  icon?: string | null;
  colour?: string;
  isActive?: boolean;
}

export interface CourseSkillAssignment {
  skillId: string;
  proficiency?: Proficiency;
  isPrimary?: boolean;
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * Skills, their categories, and who holds them.
 *
 * Twenty-six methods became sixteen, and the reduction is almost entirely the
 * five `my-skills` variants collapsing into one — see {@link LearnerSkills}.
 *
 * **A skill is held by finishing a course that teaches it.** There is nothing
 * here that grants one, because nothing grants one: the API derives a person's
 * skills from their completed enrolments. The legacy had two tables built to
 * store them, both empty, and neither was ever read.
 */
@Injectable({ providedIn: 'root' })
export class SkillsService {
  private readonly api = inject(ApiClient);

  // --- categories ---------------------------------------------------------

  getCategories(activeOnly = false): Observable<SkillCategory[]> {
    return this.api.get<SkillCategory[]>(Endpoints.skills.categories, { active: activeOnly });
  }

  createCategory(input: Partial<SkillCategory>): Observable<SkillCategory> {
    return this.api.post<SkillCategory>(Endpoints.skills.createCategory, input);
  }

  updateCategory(id: string, input: Partial<SkillCategory>): Observable<SkillCategory> {
    return this.api.put<SkillCategory>(Endpoints.skills.updateCategory(id), input);
  }

  /** The skills filed under it survive, uncategorised. */
  deleteCategory(id: string): Observable<void> {
    return this.api.delete<void>(Endpoints.skills.deleteCategory(id));
  }

  // --- the dictionary -----------------------------------------------------

  getSkills(filters: SkillFilters = {}): Observable<Paged<Skill>> {
    return this.api.getPaged<Skill>(Endpoints.skills.list, { ...filters });
  }

  getSkill(id: string): Observable<Skill> {
    return this.api.get<Skill>(Endpoints.skills.show(id));
  }

  createSkill(input: SkillInput): Observable<Skill> {
    return this.api.post<Skill>(Endpoints.skills.create, input);
  }

  updateSkill(id: string, input: Partial<SkillInput>): Observable<Skill> {
    return this.api.put<Skill>(Endpoints.skills.update(id), input);
  }

  deleteSkill(id: string): Observable<void> {
    return this.api.delete<void>(Endpoints.skills.delete(id));
  }

  getStatistics(): Observable<SkillStatistics> {
    return this.api.get<SkillStatistics>(Endpoints.skills.statistics);
  }

  // --- what teaches what --------------------------------------------------

  getCourseSkills(courseId: string): Observable<CourseSkill[]> {
    return this.api.get<CourseSkill[]>(Endpoints.courses.skills(courseId));
  }

  /**
   * Replaces what a course teaches, wholesale.
   *
   * The whole set, not an addition: a course teaches these three things. Only
   * one of them may be the main one.
   */
  assignCourseSkills(courseId: string, skills: CourseSkillAssignment[]): Observable<CourseSkill[]> {
    return this.api.put<CourseSkill[]>(Endpoints.courses.skills(courseId), { skills });
  }

  getSkillCourses(id: string): Observable<SkillCourse[]> {
    return this.api.get<SkillCourse[]>(Endpoints.skills.courses(id));
  }

  getSkillLearners(id: string, page = 1, perPage = 25): Observable<Paged<SkillHolder>> {
    return this.api.getPaged<SkillHolder>(Endpoints.skills.learners(id), { page, perPage });
  }

  // --- who holds what -----------------------------------------------------

  /** The caller's own. No id, so there is nothing to tamper with. */
  getMySkills(): Observable<LearnerSkills> {
    return this.api.get<LearnerSkills>(Endpoints.me.skills);
  }

  /** Somebody else's, which needs `user.view`. */
  getUserSkills(userId: string): Observable<LearnerSkills> {
    return this.api.get<LearnerSkills>(Endpoints.users.skills(userId));
  }
}
