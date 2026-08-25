import { decodeSessionBlob } from '../../../core/utils/session-blob';
// course-player/components/course-player/course-player.component.ts

import { Component, OnInit, OnDestroy, HostListener } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import {
  CoursePlayerService,
  CoursePlayerData,
  Lesson,
  Module,
  Assessment,
  Note,
  QuizResult
} from '../../services/course-player.service';
import { ScormAdapterService } from '../../services/scorm-adapter.service';
import { QuizComponent } from '../quiz/quiz.component';
import {GlobalComponent} from '../../../global-component';
import {HotToastService} from '@ngneat/hot-toast';

@Component({
  selector: 'app-course-player',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterModule, QuizComponent],
  templateUrl: './course-player.component.html',
  styleUrls: ['./course-player.component.scss']
})
export class CoursePlayerComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();

  // Course data
  courseData: CoursePlayerData | null = null;
  currentLesson: Lesson | null = null;
  loading = true;
  error: string | null = null;

  // UI State
  sidebarCollapsed = false;
  activeTab: 'content' | 'notes' | 'resources' = 'content';
  showCompletionModal = false;
  showRatingModal = false;
  showNoteModal = false;
  scormReady = false;
  currentScormUrl: SafeResourceUrl | null = null;

  // Quiz State
  showQuizView = false;
  currentAssessment: Assessment | null = null;

  // Notes
  notes: Note[] = [];
  newNote = '';
  noteTimestamp = 0;

  // Video tracking
  videoElement: HTMLVideoElement | null = null;
  isPlaying = false;
  currentTime = 0;
  duration = 0;
  volume = 1;
  playbackRate = 1;

  // Rating
  userRating = 0;
  userReview = '';

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private toast: HotToastService,
    private sanitizer: DomSanitizer,
    private scormAdapter: ScormAdapterService,
    public playerService: CoursePlayerService
  ) {}

  // Safe URL cache to avoid re-sanitizing
  private safeUrlCache = new Map<string, SafeResourceUrl>();

  // Get safe resource URL for iframes
  getSafeUrl(url: string | undefined): SafeResourceUrl {
    if (!url) return '';

    if (!this.safeUrlCache.has(url)) {
      this.safeUrlCache.set(url, this.sanitizer.bypassSecurityTrustResourceUrl(url));
    }
    return this.safeUrlCache.get(url)!;
  }

  // Wrapper methods for template access
  hasPreviousLesson(): boolean {
    return this.playerService.getPreviousLesson() !== null;
  }

  hasNextLesson(): boolean {
    return this.playerService.getNextLesson() !== null;
  }

  // Media loaded handler for both video and audio
  onMediaLoaded(media: HTMLMediaElement): void {
    this.duration = media.duration;

    // Resume from last position
    if (this.currentLesson && this.currentLesson.last_position > 0) {
      media.currentTime = this.currentLesson.last_position;
    }
  }

  ngOnInit(): void {
    this.route.params.pipe(takeUntil(this.destroy$)).subscribe(params => {
      const courseId = +params['courseId'];
      if (courseId) {
        this.loadCourse(courseId);
      }
    });

    // Subscribe to service observables
    this.playerService.courseData$
      .pipe(takeUntil(this.destroy$))
      .subscribe(data => {
        this.courseData = data;
        if (data) {
          this.loadNotes();
        }
      });

    this.playerService.currentLesson$
      .pipe(takeUntil(this.destroy$))
      .subscribe(lesson => {
        this.currentLesson = lesson;
        if (lesson) {
          this.loadLessonNotes(lesson.lesson_id);
        }
      });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();

    // Terminate SCORM session if active
    this.scormAdapter.terminate();

    this.playerService.endSession().subscribe();
    this.playerService.cleanup();
  }

  // Handle browser close/navigate away
  @HostListener('window:beforeunload', ['$event'])
  beforeUnload(event: Event): void {
    // Terminate SCORM session if active
    this.scormAdapter.terminate();

    this.playerService.endSession().subscribe();
  }

  // ==========================================
  // COURSE LOADING
  // ==========================================

  loadCourse(courseId: number): void {
    this.loading = true;
    this.error = null;

    this.playerService.loadCourse(courseId).subscribe({
      next: (data) => {
        this.loading = false;

        // Start session
        this.playerService.startSession(
          data.enrollment.enrollment_id,
          data.course.course_id
        ).subscribe();

        // Auto-select lesson
        this.autoSelectLesson(data);

        // Set existing rating
        if (data.user_rating) {
          this.userRating = data.user_rating.rating;
          this.userReview = data.user_rating.review;
        }
      },
      error: (err) => {
        this.loading = false;
        this.error = err.error?.message || 'Failed to load course';
      }
    });
  }

  private autoSelectLesson(data: CoursePlayerData): void {
    // Get resume position first
    this.playerService.getResumePosition(data.enrollment.enrollment_id).subscribe({
      next: (resume) => {
        if (resume.lesson_id) {
          // Find and select the lesson
          for (const module of data.modules) {
            const lesson = module.lessons.find(l => l.lesson_id === resume.lesson_id);
            if (lesson) {
              this.selectLesson(lesson);
              return;
            }
          }
        }
        // Fallback to first lesson
        this.selectFirstLesson(data);
      },
      error: () => this.selectFirstLesson(data)
    });
  }

  private selectFirstLesson(data: CoursePlayerData): void {
    if (data.modules.length > 0 && data.modules[0].lessons.length > 0) {
      this.selectLesson(data.modules[0].lessons[0]);
    }
  }

  // ==========================================
  // LESSON NAVIGATION
  // ==========================================

  selectLesson(lesson: Lesson): void {
    // Close quiz view if open
    this.showQuizView = false;
    this.currentAssessment = null;

    // Reset SCORM state
    this.scormReady = false;
    this.currentScormUrl = null;

    // Terminate existing SCORM session if any
    if (this.currentLesson?.content_type === 'scorm') {
      this.scormAdapter.terminate();
    }

    this.playerService.selectLesson(lesson);
    this.activeTab = 'content';

    // Track content interaction based on type
    if (lesson.content_type === 'scorm' && this.courseData) {
      this.initializeScorm(lesson);
      // Track SCORM launch after a small delay to ensure courseData is set
      setTimeout(() => this.onScormLaunch(), 200);
    } else if (lesson.content_type === 'pdf' || lesson.content_type === 'html') {
      // Track document view
      setTimeout(() => this.onDocumentView(), 200);
    }
  }

  private initializeScorm(lesson: Lesson): void {
    if (!this.courseData) return;

    console.log('[CoursePlayer] Initializing SCORM for lesson:', lesson.lesson_id);

    // Initialize SCORM adapter for tracking
    this.scormAdapter.initialize(
      this.courseData.enrollment.enrollment_id,
      lesson.lesson_id
    );

    // Generate and cache the SCORM URL ONCE (prevents infinite loop)
    this.currentScormUrl = this.buildScormUrl(lesson);

    // Small delay to ensure everything is ready, then show iframe
    setTimeout(() => {
      this.scormReady = true;
      console.log('[CoursePlayer] SCORM ready, iframe will load now');
    }, 100);
  }
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
    role: "superadmin",
    is_2fa: false,
    is_active: false,
    account_locked: false,
    is_admin: false,
    is_content: false,
    is_instructor: false,
    is_learner: false,
  };

  // Build SCORM wrapper URL (called once per lesson, cached)
  private buildScormUrl(lesson: Lesson): SafeResourceUrl {
    if (!this.courseData || !lesson.content_url) {
      return this.sanitizer.bypassSecurityTrustResourceUrl('');
    }
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = decodeSessionBlob(this.session_data) ?? this.user_session;
    const apiUrl = GlobalComponent.scormBaseURL;
    const baseApiUrl = apiUrl.replace('/user', '');
    const wrapperUrl = `${baseApiUrl}/user/scorm-wrapper`;

    const params = new URLSearchParams({
      content_url: lesson.content_url,
      enrollment_id: this.courseData.enrollment.enrollment_id.toString(),
      lesson_id: lesson.lesson_id.toString(),
      user_id: this.user_session.id.toString(),
      user_name: this.user_session.first_name + " " + this.user_session.last_name,
    });

    const fullUrl = `${wrapperUrl}?${params.toString()}`;
    console.log('[CoursePlayer] SCORM wrapper URL:', fullUrl);

    return this.sanitizer.bypassSecurityTrustResourceUrl(fullUrl);
  }

  // Handle postMessage events from SCORM wrapper iframe
  @HostListener('window:message', ['$event'])
  onScormMessage(event: MessageEvent): void {
    const data = event.data;
    console.log('[SCORM] SCORM message:', data);
    if (!data || !data.type) return;

    console.log('[CoursePlayer] Received SCORM message:', data.type, data);

    switch (data.type) {
      case 'scorm_wrapper_ready':
        console.log('[CoursePlayer] SCORM wrapper is ready');
        break;

      case 'scorm_initialize':
        console.log('[CoursePlayer] SCORM content initialized');
        break;

      case 'scorm_commit':
        // Save SCORM data to server (includes cmi data with scores, status, etc.)
        if (this.courseData && this.currentLesson) {
          const enrollmentId = data.enrollmentId || this.courseData.enrollment.enrollment_id;
          const lessonId = data.lessonId || this.currentLesson.lesson_id;
          const scorm_data = this.scormAdapter.getData();
          console.log(scorm_data);



          this.playerService.saveScormData(
            enrollmentId,
            lessonId,
            data.cmiData
          ).subscribe({
            next: () => console.log('[CoursePlayer] SCORM data saved'),
            error: (err) => console.error('[CoursePlayer] Failed to save SCORM data:', err)
          });
        }
        break;

      case 'scorm_status_change':
        console.log('[CoursePlayer] SCORM status changed:', data.status);
        // Only mark complete when SCORM reports passed/completed
        if (data.status === 'completed' || data.status === 'passed') {
          this.completeScormLesson(data.status, data.score);
        }
        break;

      case 'scorm_finish':
        console.log('[CoursePlayer] SCORM finished with status:', data.status, 'score:', data.score);
        // Final completion - save all data and mark lesson complete if passed
        if (this.courseData && this.currentLesson) {
          // Save final SCORM data
          this.playerService.saveScormData(
            this.courseData.enrollment.enrollment_id,
            this.currentLesson.lesson_id,
            data.cmiData || {}
          ).subscribe();

          // Mark complete only if passed or completed
          if (data.status === 'completed' || data.status === 'passed') {
            this.completeScormLesson(data.status, data.score);
          }
        }
        break;
    }
  }

  // Complete SCORM lesson with score
  private completeScormLesson(status: string, score?: number): void {
    if (!this.courseData || !this.currentLesson) return;
    if (this.currentLesson.status === 'completed') return; // Already completed

    console.log('[CoursePlayer] Completing SCORM lesson:', this.currentLesson.lesson_id, 'status:', status, 'score:', score);

    // Call specific SCORM completion endpoint
    this.playerService.completeScormLesson(
      this.courseData.enrollment.enrollment_id,
      this.currentLesson.lesson_id,
      status,
      score
    ).subscribe({
      next: () => {
        console.log('[CoursePlayer] SCORM lesson marked complete');
        this.success_notification('lesson completed successfully');
        // Refresh course data to update UI
        this.loadCourse(this.courseData!.course.course_id);

        // Auto-advance to next lesson
        setTimeout(() => {
          const next = this.playerService.getNextLesson();
          if (next) {
            this.selectLesson(next);
          } else if (this.isAllLessonsCompleted()) {
            this.showCompletionModal = true;
          }
        }, 1000);
      },
      error: (err) => console.error('[CoursePlayer] Failed to complete SCORM lesson:', err)
    });
  }

  nextLesson(): void {
    const next = this.playerService.getNextLesson();
    if (next) {
      this.selectLesson(next);
    } else if (this.isAllLessonsCompleted()) {
      this.showCompletionModal = true;
    }
  }

  previousLesson(): void {
    const prev = this.playerService.getPreviousLesson();
    if (prev) {
      this.selectLesson(prev);
    }
  }

  isAllLessonsCompleted(): boolean {
    if (!this.courseData) return false;
    return this.courseData.stats.completed_lessons >= this.courseData.stats.total_lessons;
  }

  // ==========================================
  // LESSON COMPLETION
  // ==========================================

  markLessonComplete(): void {
    if (!this.courseData || !this.currentLesson) return;

    // Don't re-complete already completed lessons
    if (this.currentLesson.status === 'completed') return;

    const timeSpent = Math.floor(this.currentTime);

    // Update local status immediately for UI feedback
    this.currentLesson.status = 'completed';

    this.playerService.completeLesson(
      this.courseData.enrollment.enrollment_id,
      this.currentLesson.lesson_id,
      timeSpent
    ).subscribe({
      next: (update) => {
        // Update module lesson status
        for (const module of this.courseData!.modules) {
          const lesson = module.lessons.find(l => l.lesson_id === this.currentLesson!.lesson_id);
          if (lesson) {
            lesson.status = 'completed';
            this.success_notification('lesson completed successfully');
            module.completed_lessons = (module.completed_lessons || 0) + 1;
            break;
          }
        }

        // Update stats
        if (this.courseData) {
          this.courseData.stats.completed_lessons = update.lessons_completed;
          this.courseData.enrollment.progress_percentage = update.overall_progress;
        }

        // Check if course is complete
        if (update.overall_progress >= 100) {
          this.showCompletionModal = true;
        } else {
          // Auto-advance to next lesson
          setTimeout(() => this.nextLesson(), 1000);
        }

      }
    });
  }

  completeCourse(): void {
    if (!this.courseData) return;

    this.playerService.completeCourse(
      this.courseData.enrollment.enrollment_id
    ).subscribe({
      next: (result) => {
        this.showCompletionModal = false;
        this.showRatingModal = true;

        if (result.certificate_id) {
          // Show certificate info
        }
      }
    });
  }

  // ==========================================
  // VIDEO CONTROLS
  // ==========================================

  onVideoLoaded(video: HTMLVideoElement): void {
    this.videoElement = video;
    this.duration = video.duration;

    // Resume from last position
    if (this.currentLesson && this.currentLesson.last_position > 0) {
      video.currentTime = this.currentLesson.last_position;
    }
  }

  onTimeUpdate(time: number): void {
    this.currentTime = time;
    this.playerService.updateVideoPosition(Math.floor(time));

    // Auto-complete at 90% for videos (not SCORM)
    if (this.currentLesson?.content_type === 'video' &&
      this.duration > 0 &&
      (time / this.duration) >= 0.9 &&
      this.currentLesson.status !== 'completed') {
      this.onVideoComplete();
    }
  }

  // Content Interaction Tracking
  onVideoPlay(): void {
    this.isPlaying = true;
    this.trackContentInteraction('video_play', Math.floor(this.currentTime));
  }

  onVideoPause(): void {
    this.isPlaying = false;
    this.trackContentInteraction('video_pause', Math.floor(this.currentTime));
  }

  onVideoComplete(): void {
    this.isPlaying = false;
    this.trackContentInteraction('video_complete', Math.floor(this.duration), Math.floor(this.duration));
    this.markLessonComplete();
  }

  onAudioComplete(): void {
    this.trackContentInteraction('video_complete', Math.floor(this.duration), Math.floor(this.duration));
    this.markLessonComplete();
  }

  onDocumentView(): void {
    this.trackContentInteraction('document_view');
  }

  onDocumentDownload(): void {
    this.trackContentInteraction('document_download');
  }

  onScormLaunch(): void {
    this.trackContentInteraction('scorm_launch');
  }

  private trackContentInteraction(
    type: 'video_play' | 'video_pause' | 'video_complete' | 'document_view' | 'document_download' | 'scorm_launch',
    positionSeconds?: number,
    durationSeconds?: number
  ): void {
    if (!this.courseData || !this.currentLesson) return;

    this.playerService.trackContentInteraction(
      this.courseData.course.course_id,
      this.currentLesson.lesson_id,
      type,
      positionSeconds,
      durationSeconds
    ).subscribe({
      error: (err) => console.error('[CoursePlayer] Failed to track interaction:', err)
    });
  }

  togglePlay(): void {
    if (!this.videoElement) return;

    if (this.isPlaying) {
      this.videoElement.pause();
    } else {
      this.videoElement.play();
    }
    this.isPlaying = !this.isPlaying;
  }

  seek(seconds: number): void {
    if (!this.videoElement) return;
    this.videoElement.currentTime = Math.max(0, Math.min(this.duration, this.videoElement.currentTime + seconds));
  }

  setPlaybackRate(rate: number): void {
    if (!this.videoElement) return;
    this.videoElement.playbackRate = rate;
    this.playbackRate = rate;
  }

  setVolume(vol: number): void {
    if (!this.videoElement) return;
    this.videoElement.volume = vol;
    this.volume = vol;
  }

  // ==========================================
  // BOOKMARKS
  // ==========================================

  addBookmark(): void {
    if (!this.courseData || !this.currentLesson) return;

    const note = prompt('Add a note for this bookmark (optional):');

    this.playerService.saveBookmark(
      this.courseData.enrollment.enrollment_id,
      this.currentLesson.lesson_id,
      Math.floor(this.currentTime),
      note || undefined
    ).subscribe();
  }

  goToBookmark(position: number): void {
    if (this.videoElement) {
      this.videoElement.currentTime = position;
    }
  }

  // ==========================================
  // NOTES
  // ==========================================

  loadNotes(): void {
    if (!this.courseData) return;

    this.playerService.getNotes(this.courseData.enrollment.enrollment_id).subscribe({
      next: (notes) => this.notes = notes
    });
  }

  loadLessonNotes(lessonId: number): void {
    if (!this.courseData) return;

    this.playerService.getNotes(
      this.courseData.enrollment.enrollment_id,
      lessonId
    ).subscribe({
      next: (notes) => this.notes = notes
    });
  }

  openNoteModal(): void {
    this.noteTimestamp = Math.floor(this.currentTime);
    this.newNote = '';
    this.showNoteModal = true;

    // Pause video when opening note modal
    if (this.videoElement && this.isPlaying) {
      this.videoElement.pause();
    }
  }

  closeNoteModal(): void {
    this.showNoteModal = false;
    this.newNote = '';
  }

  saveNote(): void {
    if (!this.courseData || !this.currentLesson || !this.newNote.trim()) return;

    this.playerService.saveNote(
      this.courseData.enrollment.enrollment_id,
      this.currentLesson.lesson_id,
      this.newNote.trim(),
      this.noteTimestamp
    ).subscribe({
      next: () => {
        this.newNote = '';
        this.showNoteModal = false;
        this.loadLessonNotes(this.currentLesson!.lesson_id);

        // Switch to notes tab to show new note
        this.activeTab = 'notes';
      }
    });
  }

  saveNoteFromPanel(): void {
    if (!this.courseData || !this.currentLesson || !this.newNote.trim()) return;

    this.playerService.saveNote(
      this.courseData.enrollment.enrollment_id,
      this.currentLesson.lesson_id,
      this.newNote.trim(),
      Math.floor(this.currentTime)
    ).subscribe({
      next: () => {
        this.newNote = '';
        this.loadLessonNotes(this.currentLesson!.lesson_id);
      }
    });
  }

  // ==========================================
  // RATING
  // ==========================================

  setRating(rating: number): void {
    this.userRating = rating;
  }

  submitRating(): void {
    if (!this.courseData || this.userRating === 0) return;

    this.playerService.rateCourse(
      this.courseData.course.course_id,
      this.userRating,
      this.userReview
    ).subscribe({
      next: () => {
        this.showRatingModal = false;
        this.router.navigate(['/courses', this.courseData!.course.course_id]);
      }
    });
  }

  skipRating(): void {
    this.showRatingModal = false;
    this.router.navigate(['/courses', this.courseData!.course.course_id]);
  }

  // ==========================================
  // UI HELPERS
  // ==========================================

  toggleSidebar(): void {
    this.sidebarCollapsed = !this.sidebarCollapsed;
  }

  formatTime(seconds: number): string {
    return this.playerService.formatDuration(Math.floor(seconds));
  }

  getProgressPercentage(): number {
    return this.courseData?.enrollment.progress_percentage || 0;
  }

  getLessonIcon(contentType: string): string {
    const icons: Record<string, string> = {
      'video': 'bi-play-circle',
      'audio': 'bi-music-note',
      'pdf': 'bi-file-pdf',
      'scorm': 'bi-box',
      'html': 'bi-code-slash',
      'quiz': 'bi-question-circle'
    };
    return icons[contentType] || 'bi-file-text';
  }

  getStatusIcon(status: string): string {
    const icons: Record<string, string> = {
      'completed': 'bi-check-circle-fill text-success',
      'in_progress': 'bi-circle-half text-warning',
      'not_started': 'bi-circle text-muted'
    };
    return icons[status] || 'bi-circle';
  }

  // ==========================================
  // ASSESSMENT / QUIZ
  // ==========================================

  selectAssessment(assessment: Assessment): void {
    // Close any current lesson
    this.currentLesson = null;

    // Terminate SCORM if active
    if (this.scormReady) {
      this.scormAdapter.terminate();
      this.scormReady = false;
      this.currentScormUrl = null;
    }

    this.currentAssessment = assessment;
    this.showQuizView = true;
  }

  closeQuizView(): void {
    this.showQuizView = false;
    this.currentAssessment = null;

    // Return to last lesson or first lesson
    if (this.courseData) {
      this.autoSelectLesson(this.courseData);
    }
  }

  onQuizComplete(result: QuizResult): void {
    // Update assessments in courseData
    if (this.courseData && this.currentAssessment) {
      // Update in flat assessments list
      const assessmentIndex = this.courseData.assessments.findIndex(
        a => a.assessment_id === this.currentAssessment!.assessment_id
      );
      if (assessmentIndex !== -1) {
        this.courseData.assessments[assessmentIndex].attempts_used = result.attempts_used;
        this.courseData.assessments[assessmentIndex].best_score = Math.max(
          this.courseData.assessments[assessmentIndex].best_score || 0,
          result.percentage
        );
        this.courseData.assessments[assessmentIndex].has_passed =
          this.courseData.assessments[assessmentIndex].has_passed || result.passed;
      }

      // Update in module assessments
      for (const module of this.courseData.modules) {
        const modAssessment = module.assessments?.find(
          a => a.assessment_id === this.currentAssessment!.assessment_id
        );
        if (modAssessment) {
          modAssessment.attempts_used = result.attempts_used;
          modAssessment.best_score = Math.max(modAssessment.best_score || 0, result.percentage);
          modAssessment.has_passed = modAssessment.has_passed || result.passed;
          break;
        }
      }
    }

    // Close quiz view
    this.closeQuizView();
  }

  getAssessmentIcon(type: string): string {
    const icons: Record<string, string> = {
      'quiz': 'bi-patch-question',
      'exam': 'bi-file-earmark-text',
      'assignment': 'bi-journal-text'
    };
    return icons[type] || 'bi-question-circle';
  }

  getAssessmentStatusIcon(assessment: Assessment): string {
    if (assessment.has_passed) {
      return 'bi-check-circle-fill text-success';
    }
    if (assessment.attempts_used > 0) {
      return 'bi-circle-half text-warning';
    }
    return 'bi-circle text-muted';
  }

  getAssessmentBadgeClass(type: string): string {
    const classes: Record<string, string> = {
      'quiz': 'bg-primary-subtle text-primary',
      'exam': 'bg-danger-subtle text-danger',
      'assignment': 'bg-info-subtle text-info'
    };
    return classes[type] || 'bg-secondary-subtle text-secondary';
  }

  // ==========================================
  // KEYBOARD SHORTCUTS
  // ==========================================

  @HostListener('document:keydown', ['$event'])
  handleKeyboard(event: KeyboardEvent): void {
    // Ignore if typing in input
    if (event.target instanceof HTMLInputElement || event.target instanceof HTMLTextAreaElement) {
      return;
    }

    switch (event.key) {
      case ' ':
        event.preventDefault();
        this.togglePlay();
        break;
      case 'ArrowLeft':
        this.seek(-10);
        break;
      case 'ArrowRight':
        this.seek(10);
        break;
      case 'ArrowUp':
        event.preventDefault();
        this.setVolume(Math.min(1, this.volume + 0.1));
        break;
      case 'ArrowDown':
        event.preventDefault();
        this.setVolume(Math.max(0, this.volume - 0.1));
        break;
      case 'm':
        this.setVolume(this.volume > 0 ? 0 : 1);
        break;
      case 'f':
        this.videoElement?.requestFullscreen();
        break;
      case 'b':
        this.addBookmark();
        break;
      case 'n':
        this.openNoteModal();
        break;
    }
  }

  error_notification(message: string) {
    this.toast.error(message);
  }
  success_notification(message: string) {
    this.toast.success(message);
  }
}
