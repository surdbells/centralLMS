// course-content/course-content.interfaces.ts

export interface Course {
  course_id: number;
  tenant_id: number;
  title: string;
  description?: string;
  category_id?: number;
  instructor_id?: number;
  level?: 'beginner' | 'intermediate' | 'advanced';
  duration_minutes?: number;
  pass_percentage?: number;
  is_mandatory?: boolean;
  is_active?: boolean;
  created_at?: string;
  updated_at?: string;
}

export interface Module {
  module_id: number;
  course_id: number;
  tenant_id: number;
  title: string;
  description?: string;
  sequence_order: number;
  is_active: boolean;
  created_at?: string;
  updated_at?: string;
  lessons?: Lesson[];
  assessments?: Assessment[];
  expanded?: boolean; // UI state
}

export interface Lesson {
  lesson_id: number;
  module_id: number;
  tenant_id: number;
  title: string;
  description?: string;
  content_type: LessonContentType;
  content_url?: string;
  content_text?: string;
  duration_minutes?: number;
  sequence_order: number;
  is_mandatory: boolean;
  is_active: boolean;
  pass_percentage?: number;
  created_at?: string;
  updated_at?: string;
}

export type LessonContentType = 'video' | 'audio' | 'pdf' | 'html' | 'scorm' | 'text' | 'quiz';

export interface Assessment {
  assessment_id: number;
  module_id?: number;
  course_id: number;
  tenant_id: number;
  title: string;
  description?: string;
  assessment_type: AssessmentType;
  time_limit_minutes?: number;
  pass_percentage: number;
  max_attempts?: number;
  shuffle_questions: boolean;
  show_correct_answers: boolean;
  sequence_order: number;
  is_active: boolean;
  created_at?: string;
  updated_at?: string;
  questions?: Question[];
  question_count?: number;
}

export type AssessmentType = 'quiz' | 'exam' | 'survey' | 'assignment';

export interface Question {
  question_id: number;
  assessment_id: number;
  tenant_id: number;
  question_text: string;
  question_type: QuestionType;
  points: number;
  sequence_order: number;
  explanation?: string;
  is_active: boolean;
  options?: QuestionOption[];
}

export type QuestionType = 'multiple_choice' | 'single_choice' | 'true_false' | 'short_answer' | 'essay' | 'matching' | 'fill_blank';

export interface QuestionOption {
  option_id: number;
  question_id: number;
  option_text: string;
  is_correct: boolean;
  sequence_order: number;
  feedback?: string;
}

// Form interfaces
export interface ModuleForm {
  module_id?: number;
  title: string;
  description: string;
  is_active: boolean;
}

export interface LessonForm {
  lesson_id?: number;
  module_id: number;
  title: string;
  description: string;
  content_type: LessonContentType;
  content_url: string;
  content_text: string;
  duration_minutes: number;
  is_mandatory: boolean;
  is_active: boolean;
  pass_percentage: number;
}

export interface AssessmentForm {
  assessment_id?: number;
  module_id?: number;
  title: string;
  description: string;
  assessment_type: AssessmentType;
  time_limit_minutes: number;
  pass_percentage: number;
  max_attempts: number;
  shuffle_questions: boolean;
  show_correct_answers: boolean;
  is_active: boolean;
}

export interface QuestionForm {
  question_id?: number;
  question_text: string;
  question_type: QuestionType;
  points: number;
  explanation: string;
  is_active: boolean;
  options: QuestionOptionForm[];
}

export interface QuestionOptionForm {
  option_id?: number;
  option_text: string;
  is_correct: boolean;
  feedback: string;
}

// API Response interfaces
export interface CourseContentResponse {
  course: Course;
  modules: Module[];
  stats: {
    total_modules: number;
    total_lessons: number;
    total_assessments: number;
    total_questions: number;
    total_duration_minutes: number;
  };
}

export interface ApiResponse<T = any> {
  status: string;
  message: string;
  data: T;
}

// Drag and drop
export interface DragDropEvent {
  type: 'module' | 'lesson' | 'assessment' | 'question';
  id: number;
  fromIndex: number;
  toIndex: number;
  parentId?: number;
}
