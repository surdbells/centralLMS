// course-player/index.ts

// Routes
export { COURSE_PLAYER_ROUTES } from './course-player.routes';

// Services
export { CoursePlayerService } from './services/course-player.service';
export { ScormAdapterService } from './services/scorm-adapter.service';
export { AnalyticsService } from './services/analytics.service';

// Components
export { CoursePlayerComponent } from './components/course-player/course-player.component';
export { VideoPlayerComponent } from './components/video-player/video-player.component';
export { QuizComponent } from './components/quiz/quiz.component';

// Interfaces (re-export from service)
export type {
  CoursePlayerData,
  Course,
  Enrollment,
  Module,
  Lesson,
  Assessment,
  Bookmark,
  UserRating,
  CourseStats,
  Note,
  QuizResult,
  QuestionResult,
  ProgressUpdate,
  ResumePosition
} from './services/course-player.service';

// Video player interfaces
export type {
  VideoEvent,
  VideoProgress
} from './components/video-player/video-player.component';

// Quiz interfaces
export type {
  Question,
  QuestionOption
} from './components/quiz/quiz.component';

// Analytics interfaces
export type {
  AnalyticsEvent,
  EngagementMetrics,
  VideoAnalytics
} from './services/analytics.service';

// SCORM interfaces
export type {
  ScormData,
  ScormCommitResult
} from './services/scorm-adapter.service';
