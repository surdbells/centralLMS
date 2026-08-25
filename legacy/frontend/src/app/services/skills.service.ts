  // skills/skills.service.ts
  import { Injectable } from '@angular/core';
  import { HttpClient } from '@angular/common/http';
  import { Observable } from 'rxjs';
  import { map } from 'rxjs/operators';
  import {GlobalComponent} from '../global-component';

  // ==========================================
  // INTERFACES
  // ==========================================

  export interface SkillCategory {
    category_id: number;
    tenant_id: number;
    name: string;
    description: string | null;
    color: string;
    is_active: boolean;
    skill_count: number;
    created_at: string;
    updated_at: string | null;
  }

  export interface Skill {
    skill_id: number;
    tenant_id: number;
    category_id: number | null;
    name: string;
    description: string | null;
    level: 'beginner' | 'intermediate' | 'advanced' | 'expert';
    icon: string | null;
    color: string;
    is_active: boolean;
    category_name: string | null;
    category_color: string | null;
    course_count: number;
    learners_acquired: number;
    created_at: string;
    updated_at: string | null;
  }

  export interface CourseSkill {
    course_skill_id: number;
    course_id: number;
    skill_id: number;
    proficiency_level: 'beginner' | 'intermediate' | 'advanced' | 'expert';
    is_primary: boolean;
    skill_name: string;
    skill_description: string | null;
    skill_level: string;
    icon: string | null;
    color: string;
    category_name: string | null;
    category_color: string | null;
    created_at: string;
  }

  export interface SkillCourse {
    course_id: number;
    title: string;
    description: string | null;
    thumbnail_url: string | null;
    duration_minutes: number | null;
    course_level: string | null;
    proficiency_level: string;
    is_primary: boolean;
    category_name: string | null;
  }

  export interface LearnerSkill {
    skill_id: number;
    skill_name: string;
    description: string | null;
    skill_level: string;
    icon: string | null;
    color: string;
    category_name: string | null;
    category_color: string | null;
    courses_completed: number;
    last_acquired: string;
    proficiency_levels: string;
    highest_proficiency: string;
  }

  export interface LearnerSkillCategory {
    category_name: string;
    category_color: string;
    skills: LearnerSkill[];
    total_skills: number;
  }

  export interface SkillProgressCourse {
    course_id: number;
    course_title: string;
    proficiency_level: string;
    is_primary: boolean;
    enrollment_status: string;
    progress_percentage: number;
    completed_at: string | null;
  }

  export interface SkillProgress {
    skill_id: number;
    skill_name: string;
    description: string | null;
    skill_level: string;
    icon: string | null;
    color: string;
    category_name: string | null;
    category_color: string | null;
    courses: SkillProgressCourse[];
    total_courses: number;
    completed_courses: number;
    in_progress_courses: number;
    is_acquired: boolean;
    progress_percentage: number;
  }

  export interface SkillStats extends Skill {
    learners_in_progress: number;
  }

  export interface SkillSummary {
    total_skills: number;
    active_skills: number;
    inactive_skills: number;
    total_categories: number;
    courses_with_skills: number;
    learners_with_skills: number;
  }

  export interface SkillLearner {
    user_id: number;
    first_name: string;
    last_name: string;
    full_name: string;
    email: string;
    profile_image: string | null;
    courses_completed: number;
    skill_acquired_at: string;
    proficiency_levels: string;
    highest_proficiency: string;
  }

  export interface SkillGap extends Skill {
    total_users: number;
    adoption_rate: number;
  }

  export interface TopSkill {
    skill_id: number;
    name: string;
    icon: string | null;
    color: string;
    category_name: string | null;
    learners_acquired: number;
  }

  export interface SkillAssignment {
    skill_id: number;
    proficiency_level: 'beginner' | 'intermediate' | 'advanced' | 'expert';
    is_primary: boolean;
  }

  export interface UserBasic {
    user_id: number;
    first_name: string;
    last_name: string;
    email: string;
    profile_image?: string | null;
  }

  export interface CourseBasic {
    course_id: number;
    title: string;
    category_name?: string | null;
    status?: string;
  }

  export interface ApiResponse<T> {
    code: number;
    status: string;
    data: T;
    message: string;
  }

  // ==========================================
  // SERVICE
  // ==========================================

  @Injectable({
    providedIn: 'root'
  })
  export class SkillsService {
    private apiUrl = GlobalComponent.skills;
    session_data: any = ""
    user_session = {
      id: 0,
      tenant_id: 0,
      tenant: {
        id: 0,
        name: "",
        status: "",
      },
      token: "",
      first_name: "",
      last_name: "",
      email: "",
      phone: "",
      avatar: "",
      is_2fa: false,
      is_active: false,
      account_locked: false,
      is_admin: false,
      is_content: false,
      is_instructor: false,
      is_learner: false,
    };
    constructor(private http: HttpClient) {}
    private getAuthPayload(): any {
      this.session_data = sessionStorage.getItem("SESSION");
      this.user_session = JSON.parse(atob(this.session_data));
      return {
        token: this.user_session.token,
        user_id: this.user_session.id,
        tenant_id: this.user_session.tenant_id,
      };
    }
    // ==========================================
    // SKILL CATEGORIES
    // ==========================================
    createCategory(data: Partial<SkillCategory>): Observable<{ category_id: number }> {
      return this.http.post<ApiResponse<{ category_id: number }>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'create-category',
        ...data
      }).pipe(map(res => res.data));
    }

    updateCategory(category_id: number, data: Partial<SkillCategory>): Observable<void> {
      return this.http.post<ApiResponse<void>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'update-category',
        category_id,
        ...data
      }).pipe(map(() => {}));
    }

    deleteCategory(category_id: number): Observable<void> {
      return this.http.post<ApiResponse<void>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'delete-category',
        category_id
      }).pipe(map(() => {}));
    }

    getCategory(category_id: number): Observable<SkillCategory> {
      return this.http.post<ApiResponse<SkillCategory>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'get-category',
        category_id
      }).pipe(map(res => res.data));
    }

    getCategories(activeOnly: boolean = false): Observable<SkillCategory[]> {
      return this.http.post<ApiResponse<SkillCategory[]>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'get-categories',
        active_only: activeOnly
      }).pipe(map(res => res.data));
    }

    // ==========================================
    // SKILLS CRUD
    // ==========================================

    createSkill(data: Partial<Skill>): Observable<{ skill_id: number }> {
      return this.http.post<ApiResponse<{ skill_id: number }>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'create-skill',
        ...data
      }).pipe(map(res => res.data));
    }

    updateSkill(skill_id: number, data: Partial<Skill>): Observable<void> {
      return this.http.post<ApiResponse<void>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'update-skill',
        skill_id,
        ...data
      }).pipe(map(() => {}));
    }

    deleteSkill(skill_id: number): Observable<void> {
      return this.http.post<ApiResponse<void>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'delete-skill',
        skill_id
      }).pipe(map(() => {}));
    }

    getSkill(skill_id: number): Observable<Skill> {
      return this.http.post<ApiResponse<Skill>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'get-skill',
        skill_id
      }).pipe(map(res => res.data));
    }

    getSkills(filters?: {
      active_only?: boolean;
      category_id?: number;
      level?: string;
      search?: string;
    }): Observable<Skill[]> {
      return this.http.post<ApiResponse<Skill[]>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'get-skills',
        ...filters
      }).pipe(map(res => res.data));
    }

    // ==========================================
    // COURSE-SKILL MAPPING
    // ==========================================

    assignCourseSkills(course_id: number, skills: SkillAssignment[]): Observable<{ assigned_count: number }> {
      return this.http.post<ApiResponse<{ assigned_count: number }>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'assign-course-skills',
        course_id,
        skills
      }).pipe(map(res => res.data));
    }

    getCourseSkills(course_id: number): Observable<CourseSkill[]> {
      return this.http.post<ApiResponse<CourseSkill[]>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'get-course-skills',
        course_id
      }).pipe(map(res => res.data));
    }

    getSkillCourses(skill_id: number): Observable<SkillCourse[]> {
      return this.http.post<ApiResponse<SkillCourse[]>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'get-skill-courses',
        skill_id
      }).pipe(map(res => res.data));
    }

    // ==========================================
    // ADMIN ANALYTICS
    // ==========================================

    getSkillsSummary(): Observable<SkillSummary> {
      return this.http.post<ApiResponse<SkillSummary>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'skills-summary'
      }).pipe(map(res => res.data));
    }

    getSkillStats(): Observable<SkillStats[]> {
      return this.http.post<ApiResponse<SkillStats[]>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'skill-stats'
      }).pipe(map(res => res.data));
    }

    getTopSkills(limit: number = 10): Observable<TopSkill[]> {
      return this.http.post<ApiResponse<TopSkill[]>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'top-skills',
        limit
      }).pipe(map(res => res.data));
    }

    getSkillLearners(skill_id: number, limit: number = 50): Observable<SkillLearner[]> {
      return this.http.post<ApiResponse<SkillLearner[]>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'skill-learners',
        skill_id,
        limit
      }).pipe(map(res => res.data));
    }

    getSkillGaps(): Observable<SkillGap[]> {
      return this.http.post<ApiResponse<SkillGap[]>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'skill-gaps'
      }).pipe(map(res => res.data));
    }

    // ==========================================
    // LEARNER SKILLS
    // ==========================================

    getMySkills(): Observable<LearnerSkill[]> {
      return this.http.post<ApiResponse<LearnerSkill[]>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'my-skills'
      }).pipe(map(res => res.data));
    }

    getMySkillsByCategory(): Observable<LearnerSkillCategory[]> {
      return this.http.post<ApiResponse<LearnerSkillCategory[]>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'my-skills-by-category'
      }).pipe(map(res => res.data));
    }

    getMySkillProgress(): Observable<SkillProgress[]> {
      return this.http.post<ApiResponse<SkillProgress[]>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'my-skill-progress'
      }).pipe(map(res => res.data));
    }

    // For admin viewing a specific user's skills
    getUserSkills(target_user_id: number): Observable<LearnerSkill[]> {
      return this.http.post<ApiResponse<LearnerSkill[]>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'user-skills',
        target_user_id
      }).pipe(map(res => res.data));
    }

    getUserSkillProgress(target_user_id: number): Observable<SkillProgress[]> {
      return this.http.post<ApiResponse<SkillProgress[]>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'user-skill-progress',
        target_user_id
      }).pipe(map(res => res.data));
    }

    // Get users list for dropdown
    getUsers(search?: string): Observable<UserBasic[]> {
      return this.http.post<ApiResponse<UserBasic[]>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'get-users',
        search: search || ''
      }).pipe(map(res => res.data));
    }

    // Get courses list for dropdown
    getCourses(): Observable<CourseBasic[]> {
      return this.http.post<ApiResponse<CourseBasic[]>>(this.apiUrl, {
        ...this.getAuthPayload(),
        action: 'get-courses'
      }).pipe(map(res => res.data));
    }
  }
