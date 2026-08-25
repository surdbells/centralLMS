import {GlobalComponent} from '../../../global-component';

// course-content/course-content.service.ts
import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, BehaviorSubject } from 'rxjs';
import { map, tap } from 'rxjs/operators';
import {
  Course,
  Module,
  Lesson,
  Assessment,
  Question,
  QuestionOption,
  ModuleForm,
  LessonForm,
  AssessmentForm,
  QuestionForm,
  CourseContentResponse,
  ApiResponse
} from './course-content.interfaces';

// Media interface for content selection
export interface MediaFile {
  media_id: number;
  tenant_id: number;
  title: string;
  media_type: 'VIDEO' | 'AUDIO' | 'PDF' | 'SCORM';
  file_url: string;
  launch_url?: string; // For SCORM
  file_size?: number;
  duration_seconds?: number;
  thumbnail_url?: string;
  created_at?: string;
}

@Injectable({
  providedIn: 'root'
})
export class CourseContentService {
  private apiUrl = GlobalComponent.courseContent;
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
  // State
  private courseSubject = new BehaviorSubject<Course | null>(null);
  private modulesSubject = new BehaviorSubject<Module[]>([]);
  private mediaFilesSubject = new BehaviorSubject<MediaFile[]>([]);

  course$ = this.courseSubject.asObservable();
  modules$ = this.modulesSubject.asObservable();
  mediaFiles$ = this.mediaFilesSubject.asObservable();

  constructor(private http: HttpClient) {}

  // ==========================================
  // MEDIA OPERATIONS
  // ==========================================

  /**
   * Load media files for content selection
   */
  loadMediaFiles(mediaType?: string): Observable<MediaFile[]> {
    const params: any = { ...this.getAuthParams(), action: 'get-media' };
    if (mediaType) {
      params.media_type = mediaType;
    }

    return this.http.get<ApiResponse<MediaFile[]>>(
      this.apiUrl,
      { params }
    ).pipe(
      map(res => res.data || []),
      tap(files => this.mediaFilesSubject.next(files))
    );
  }

  /**
   * Get media files by type for lesson content selection
   */
  getMediaByType(mediaType: string): Observable<MediaFile[]> {
    const params = {
      ...this.getAuthParams(),
      action: 'get-media',
      media_type: mediaType.toUpperCase()
    };

    return this.http.get<ApiResponse<MediaFile[]>>(
      this.apiUrl,
      { params }
    ).pipe(map(res => res.data || []));
  }

  /**
   * Build content URL from media file
   * For SCORM: replace .zip with / and append launch_url
   * For others: use file_url directly
   */
  buildContentUrl(media: MediaFile): string {
    if (media.media_type === 'SCORM' && media.file_url && media.launch_url) {
      // Replace .zip with / and append launch_url
      const baseUrl = media.file_url.replace(/\.zip$/i, '/');
      return baseUrl + media.launch_url;
    }
    return media.file_url || '';
  }

  /**
   * Get cached media files
   */
  getCachedMediaFiles(): MediaFile[] {
    return this.mediaFilesSubject.getValue();
  }

  // ==========================================
  // AUTH HELPERS
  // ==========================================

  private getAuthParams(): { token: string; user_id: number; tenant_id: number } {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = JSON.parse(atob(this.session_data));
    return {
      token: this.user_session.token,
      user_id: this.user_session.id,
      tenant_id: this.user_session.tenant_id
    };
  }


  private buildBody(data: any): any {
    return {
      ...this.getAuthParams(),
      ...data
    };
  }

  // ==========================================
  // COURSE CONTENT LOADING
  // ==========================================

  /**
   * Load complete course content (modules, lessons, assessments)
   */
  loadCourseContent(courseId: number): Observable<CourseContentResponse> {
    const params = { ...this.getAuthParams(), course_id: courseId.toString() };

    return this.http.get<ApiResponse<CourseContentResponse>>(
      `${this.apiUrl}?action=load`,
      { params }
    ).pipe(
      map(res => res.data),
      tap(data => {
        this.courseSubject.next(data.course);
        this.modulesSubject.next(data.modules);
      })
    );
  }

  /**
   * Refresh modules list
   */
  refreshModules(courseId: number): Observable<Module[]> {
    const params = { ...this.getAuthParams(), course_id: courseId.toString() };

    return this.http.get<ApiResponse<Module[]>>(
      `${this.apiUrl}?action=get-modules`,
      { params }
    ).pipe(
      map(res => res.data),
      tap(modules => this.modulesSubject.next(modules))
    );
  }

  // ==========================================
  // MODULE OPERATIONS
  // ==========================================

  /**
   * Create new module
   */
  createModule(courseId: number, data: ModuleForm): Observable<Module> {
    const body = this.buildBody({
      course_id: courseId,
      title: data.title,
      description: data.description,
      is_active: data.is_active
    });

    return this.http.post<ApiResponse<Module>>(
      `${this.apiUrl}?action=create-module`,
      body
    ).pipe(map(res => res.data));
  }

  /**
   * Update module
   */
  updateModule(moduleId: number, data: ModuleForm): Observable<Module> {
    const body = this.buildBody({
      module_id: moduleId,
      title: data.title,
      description: data.description,
      is_active: data.is_active
    });

    return this.http.post<ApiResponse<Module>>(
      `${this.apiUrl}?action=update-module`,
      body
    ).pipe(map(res => res.data));
  }

  /**
   * Delete module
   */
  deleteModule(moduleId: number): Observable<void> {
    const body = this.buildBody({ module_id: moduleId });

    return this.http.post<ApiResponse>(
      `${this.apiUrl}?action=delete-module`,
      body
    ).pipe(map(() => {}));
  }

  /**
   * Reorder modules
   */
  reorderModules(courseId: number, moduleIds: number[]): Observable<void> {
    const body = this.buildBody({
      course_id: courseId,
      module_ids: moduleIds
    });

    return this.http.post<ApiResponse>(
      `${this.apiUrl}?action=reorder-modules`,
      body
    ).pipe(map(() => {}));
  }

  // ==========================================
  // LESSON OPERATIONS
  // ==========================================

  /**
   * Get lessons for a module
   */
  getLessons(moduleId: number): Observable<Lesson[]> {
    const params = { ...this.getAuthParams(), module_id: moduleId.toString() };

    return this.http.get<ApiResponse<Lesson[]>>(
      `${this.apiUrl}?action=get-lessons`,
      { params }
    ).pipe(map(res => res.data));
  }

  /**
   * Create new lesson
   */
  createLesson(courseId: number, data: LessonForm): Observable<Lesson> {
    const body = this.buildBody({
      module_id: data.module_id,
      title: data.title,
      course_id: courseId,
      description: data.description,
      content_type: data.content_type,
      content_url: data.content_url,
      content_text: data.content_text,
      duration_minutes: data.duration_minutes,
      is_mandatory: data.is_mandatory,
      is_active: data.is_active,
      pass_percentage: data.pass_percentage
    });

    return this.http.post<ApiResponse<Lesson>>(
      `${this.apiUrl}?action=create-lesson`,
      body
    ).pipe(map(res => res.data));
  }

  /**
   * Update lesson
   */
  updateLesson(lessonId: number, data: LessonForm): Observable<Lesson> {
    const body = this.buildBody({
      lesson_id: lessonId,
      module_id: data.module_id,
      title: data.title,
      description: data.description,
      content_type: data.content_type,
      content_url: data.content_url,
      content_text: data.content_text,
      duration_minutes: data.duration_minutes,
      is_mandatory: data.is_mandatory,
      is_active: data.is_active,
      pass_percentage: data.pass_percentage
    });

    return this.http.post<ApiResponse<Lesson>>(
      `${this.apiUrl}?action=update-lesson`,
      body
    ).pipe(map(res => res.data));
  }

  /**
   * Delete lesson
   */
  deleteLesson(lessonId: number): Observable<void> {
    const body = this.buildBody({ lesson_id: lessonId });

    return this.http.post<ApiResponse>(
      `${this.apiUrl}?action=delete-lesson`,
      body
    ).pipe(map(() => {}));
  }

  /**
   * Reorder lessons within a module
   */
  reorderLessons(moduleId: number, lessonIds: number[]): Observable<void> {
    const body = this.buildBody({
      module_id: moduleId,
      lesson_ids: lessonIds
    });

    return this.http.post<ApiResponse>(
      `${this.apiUrl}?action=reorder-lessons`,
      body
    ).pipe(map(() => {}));
  }

  /**
   * Move lesson to different module
   */
  moveLesson(lessonId: number, targetModuleId: number): Observable<void> {
    const body = this.buildBody({
      lesson_id: lessonId,
      target_module_id: targetModuleId
    });

    return this.http.post<ApiResponse>(
      `${this.apiUrl}?action=move-lesson`,
      body
    ).pipe(map(() => {}));
  }

  // ==========================================
  // ASSESSMENT OPERATIONS
  // ==========================================

  /**
   * Get assessments for a module or course
   */
  getAssessments(moduleId?: number, courseId?: number): Observable<Assessment[]> {
    const params: any = { ...this.getAuthParams() };
    if (moduleId) params.module_id = moduleId.toString();
    if (courseId) params.course_id = courseId.toString();

    return this.http.get<ApiResponse<Assessment[]>>(
      `${this.apiUrl}?action=get-assessments`,
      { params }
    ).pipe(map(res => res.data));
  }

  /**
   * Create new assessments
   */
  createAssessment(courseId: number, data: AssessmentForm): Observable<Assessment> {
    const body = this.buildBody({
      course_id: courseId,
      module_id: data.module_id,
      title: data.title,
      description: data.description,
      assessment_type: data.assessment_type,
      time_limit_minutes: data.time_limit_minutes,
      pass_percentage: data.pass_percentage,
      max_attempts: data.max_attempts,
      shuffle_questions: data.shuffle_questions,
      show_correct_answers: data.show_correct_answers,
      is_active: data.is_active
    });

    return this.http.post<ApiResponse<Assessment>>(
      `${this.apiUrl}?action=create-assessment`,
      body
    ).pipe(map(res => res.data));
  }

  /**
   * Update assessments
   */
  updateAssessment(assessmentId: number, data: AssessmentForm): Observable<Assessment> {
    const body = this.buildBody({
      assessment_id: assessmentId,
      module_id: data.module_id,
      title: data.title,
      description: data.description,
      assessment_type: data.assessment_type,
      time_limit_minutes: data.time_limit_minutes,
      pass_percentage: data.pass_percentage,
      max_attempts: data.max_attempts,
      shuffle_questions: data.shuffle_questions,
      show_correct_answers: data.show_correct_answers,
      is_active: data.is_active
    });

    return this.http.post<ApiResponse<Assessment>>(
      `${this.apiUrl}?action=update-assessment`,
      body
    ).pipe(map(res => res.data));
  }

  /**
   * Delete assessments
   */
  deleteAssessment(assessmentId: number): Observable<void> {
    const body = this.buildBody({ assessment_id: assessmentId });

    return this.http.post<ApiResponse>(
      `${this.apiUrl}?action=delete-assessment`,
      body
    ).pipe(map(() => {}));
  }

  // ==========================================
  // QUESTION OPERATIONS
  // ==========================================

  /**
   * Get question-banks for an assessments
   */
  getQuestions(assessmentId: number): Observable<Question[]> {
    const params = { ...this.getAuthParams(), assessment_id: assessmentId.toString() };

    return this.http.get<ApiResponse<Question[]>>(
      `${this.apiUrl}?action=get-questions`,
      { params }
    ).pipe(map(res => res.data));
  }

  /**
   * Create new question
   */
  createQuestion(assessmentId: number, data: QuestionForm): Observable<Question> {
    const body = this.buildBody({
      assessment_id: assessmentId,
      question_text: data.question_text,
      question_type: data.question_type,
      points: data.points,
      explanation: data.explanation,
      is_active: data.is_active,
      options: data.options
    });

    return this.http.post<ApiResponse<Question>>(
      `${this.apiUrl}?action=create-question`,
      body
    ).pipe(map(res => res.data));
  }

  /**
   * Update question
   */
  updateQuestion(questionId: number, data: QuestionForm): Observable<Question> {
    const body = this.buildBody({
      question_id: questionId,
      question_text: data.question_text,
      question_type: data.question_type,
      points: data.points,
      explanation: data.explanation,
      is_active: data.is_active,
      options: data.options
    });

    return this.http.post<ApiResponse<Question>>(
      `${this.apiUrl}?action=update-question`,
      body
    ).pipe(map(res => res.data));
  }

  /**
   * Delete question
   */
  deleteQuestion(questionId: number): Observable<void> {
    const body = this.buildBody({ question_id: questionId });

    return this.http.post<ApiResponse>(
      `${this.apiUrl}?action=delete-question`,
      body
    ).pipe(map(() => {}));
  }

  /**
   * Reorder question-banks
   */
  reorderQuestions(assessmentId: number, questionIds: number[]): Observable<void> {
    const body = this.buildBody({
      assessment_id: assessmentId,
      question_ids: questionIds
    });

    return this.http.post<ApiResponse>(
      `${this.apiUrl}?action=reorder-questions`,
      body
    ).pipe(map(() => {}));
  }

  // ==========================================
  // BULK OPERATIONS
  // ==========================================

  /**
   * Duplicate module (with lessons and assessments)
   */
  duplicateModule(moduleId: number): Observable<Module> {
    const body = this.buildBody({ module_id: moduleId });

    return this.http.post<ApiResponse<Module>>(
      `${this.apiUrl}?action=duplicate-module`,
      body
    ).pipe(map(res => res.data));
  }

  /**
   * Duplicate lesson
   */
  duplicateLesson(lessonId: number): Observable<Lesson> {
    const body = this.buildBody({ lesson_id: lessonId });

    return this.http.post<ApiResponse<Lesson>>(
      `${this.apiUrl}?action=duplicate-lesson`,
      body
    ).pipe(map(res => res.data));
  }

  /**
   * Duplicate assessments (with question-banks)
   */
  duplicateAssessment(assessmentId: number): Observable<Assessment> {
    const body = this.buildBody({ assessment_id: assessmentId });

    return this.http.post<ApiResponse<Assessment>>(
      `${this.apiUrl}?action=duplicate-assessment`,
      body
    ).pipe(map(res => res.data));
  }

  // ==========================================
  // STATE HELPERS
  // ==========================================

  getCurrentModules(): Module[] {
    return this.modulesSubject.getValue();
  }

  updateLocalModules(modules: Module[]): void {
    this.modulesSubject.next(modules);
  }

  clearState(): void {
    this.courseSubject.next(null);
    this.modulesSubject.next([]);
  }
}
