// course-player/components/course-player/course-player.component.ts

import { Component, ElementRef, OnInit, OnDestroy, HostListener, ViewChild } from '@angular/core';
import { CommonModule } from '@angular/common';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';
import { Subject } from 'rxjs';
import { finalize, takeUntil } from 'rxjs/operators';
import {
  CoursePlayerService,
  CoursePlayerData,
  Lesson,
  Module,
  Assessment,
  Note,
  QuizResult,
  Classmate
} from '../../services/course-player.service';
import { ScormAdapterService, scormSnapshot, toScormState } from '../../services/scorm-adapter.service';
import { QuizComponent } from '../quiz/quiz.component';
import { CourseDiscussionComponent } from '../course-discussion/course-discussion.component';
import { AvatarComponent } from '../../../shared/avatar/avatar.component';
import {
  CourseResourcesService,
  type CourseResource,
} from '../../../services/course-resources.service';
import {HotToastService} from '@ngneat/hot-toast';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';

export type BelowTab = 'transcript' | 'notes' | 'resources' | 'discussion';

/** Below this width the outline becomes a drawer and the stage comes first. */
const NARROW_QUERY = '(max-width: 1100px)';

@Component({
  selector: 'app-course-player',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterModule, QuizComponent, TranslatePipe, CourseDiscussionComponent, AvatarComponent, SkeletonComponent],
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
  /** Desktop: the outline column is folded away and the stage takes the width. */
  sidebarCollapsed = false;
  /** Narrow screens: the outline is an off-canvas drawer, closed by default. */
  outlineOpen = false;
  activeTab: BelowTab = 'transcript';
  /** Files attached to the course (any type), shown in the Resources tab. */
  courseResources: CourseResource[] = [];
  sidebarTab: 'path' | 'learners' = 'path';
  showCompletionModal = false;
  showRatingModal = false;
  scormReady = false;
  currentScormUrl: SafeResourceUrl | null = null;

  // Quiz State
  showQuizView = false;
  currentAssessment: Assessment | null = null;

  // Notes
  notes: Note[] = [];
  newNote = '';
  noteTimestamp = 0;

  // Learners (the Discussion tab is the standalone CourseDiscussionComponent).
  courseId = '';
  classmates: Classmate[] = [];
  classmatesLoaded = false;
  /** Set once the Discussion tab is first opened; the forum then stays mounted
   *  (hidden on other tabs) so a half-written post or filter survives a switch. */
  discussMounted = false;
  /** Thread count reported by the forum once it has loaded; null until then. */
  discussionCount: number | null = null;

  // Video tracking
  settingsOpen = false;
  private openModules = new Set<string>();
  videoElement: HTMLVideoElement | null = null;
  isPlaying = false;
  currentTime = 0;
  duration = 0;
  volume = 1;
  playbackRate = 1;
  readonly playbackRates = [0.5, 0.75, 1, 1.25, 1.5, 2];
  /** Controls fade out while playing and the pointer is still. */
  controlsIdle = false;
  isFullscreen = false;
  private idleTimer: ReturnType<typeof setTimeout> | null = null;

  // SCORM runtime routing
  /** The lesson the SCORM iframe currently on screen was opened for. */
  private scormFrameLessonId: string | null = null;
  /** Origin the wrapper is served from (the API's), for checking messages. */
  private scormOrigin: string | null = null;
  /** The latest CMI map each package reported, by lesson. */
  private scormLatest = new Map<string, Record<string, unknown>>();
  /** Lessons whose latest CMI map has not been saved yet. */
  private scormDirty = new Set<string>();
  /** SCORM lessons whose completion write is in flight (no double write/toast). */
  private scormCompleting = new Set<string>();

  @ViewChild('scormFrame') private scormFrame?: ElementRef<HTMLIFrameElement>;
  @ViewChild('videoWrap') private videoWrap?: ElementRef<HTMLElement>;

  // Rating
  userRating = 0;
  userReview = '';

  constructor(
    private route: ActivatedRoute,
    public router: Router,
    private toast: HotToastService,
    private sanitizer: DomSanitizer,
    private scormAdapter: ScormAdapterService,
    public playerService: CoursePlayerService,
    private courseResourcesService: CourseResourcesService,
    private i18n: I18nService,
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

  get previousLessonRef(): Lesson | null {
    return this.playerService.getPreviousLesson();
  }

  get nextLessonRef(): Lesson | null {
    return this.playerService.getNextLesson();
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
      // A string, not `+params['courseId']`. Every link into this player —
      // the dashboard, My Learning, a report row, a notification — passes a
      // UUID, and `+uuid` is NaN, so the player asked the server for course
      // "NaN" and showed a failure from every entry point it has.
      const courseId = String(params['courseId'] ?? '');

      if (courseId !== '') {
        this.courseId = courseId;
        this.loadCourse(courseId);
        this.loadCourseResources(courseId);
      }
    });

    // Course data re-emits after every progress write (the header percentage
    // moves). Notes are not reloaded here: they follow the lesson, below, and
    // reloading the whole course's notes on each heartbeat replaced the
    // lesson's list with everyone else's.
    this.playerService.courseData$
      .pipe(takeUntil(this.destroy$))
      .subscribe(data => {
        this.courseData = data;
      });

    this.playerService.currentLesson$
      .pipe(takeUntil(this.destroy$))
      .subscribe(lesson => {
        const changed = lesson?.lesson_id !== this.currentLesson?.lesson_id;
        this.currentLesson = lesson;
        if (lesson && changed) {
          this.loadLessonNotes(lesson.lesson_id);
        }
      });
  }

  ngOnDestroy(): void {
    // In-app navigation: ordinary requests complete, so the last SCORM state
    // goes the normal way. The wrapper's own pagehide commit arrives after
    // this listener is gone, which is why the latest map is kept here.
    this.flushAllScorm(false);
    this.clearIdleTimer();

    this.destroy$.next();
    this.destroy$.complete();

    this.playerService.endSession().subscribe();
    this.playerService.cleanup();
  }

  /**
   * The page is going away — tab closed, reload, a link off the app. Requests
   * made now are cancelled with the document, so what is owed (SCORM state,
   * unreported time) goes out as `keepalive` fetches. Both events can fire for
   * one unload; each flush marks what it sent, so the second sends nothing.
   */
  @HostListener('window:beforeunload')
  @HostListener('window:pagehide')
  flushForUnload(): void {
    this.flushAllScorm(true);
    this.playerService.flushOnUnload();
  }

  // ==========================================
  // COURSE LOADING
  // ==========================================

  loadCourse(courseId: string): void {
    this.loading = true;
    this.error = null;

    this.playerService.loadCourse(courseId).subscribe({
      next: (data) => {
        this.loading = false;

        // Begins counting time against whatever lesson is opened next.
        this.playerService.startSession().subscribe();

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
        this.error = err.error?.message || this.i18n.t('course_player.toast_load_failed');
      }
    });
  }

  private autoSelectLesson(data: CoursePlayerData): void {
    // Get resume position first
    this.playerService.getResumePosition().subscribe({
      next: (resume) => {
        if (resume !== null && resume.lesson_id) {
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
    // Save the package being left before its iframe goes. Its own final
    // commit may still arrive, and is routed by the lessonId it carries.
    const leaving = this.currentLesson;
    if (leaving?.content_type === 'scorm' && leaving.lesson_id !== lesson.lesson_id) {
      this.flushScorm(leaving.lesson_id, false);
    }

    // Close quiz view if open
    this.showQuizView = false;
    this.currentAssessment = null;

    // Reset SCORM state
    this.scormReady = false;
    this.currentScormUrl = null;
    this.scormFrameLessonId = null;

    // Reset playback state; the new media reports its own on load.
    this.isPlaying = false;
    this.currentTime = 0;
    this.duration = 0;
    this.controlsIdle = false;

    this.playerService.selectLesson(lesson);
    // Opening a lesson shows its transcript first — unless the learner is in
    // the forum: Next (or a lesson click) must not throw them out of a
    // half-written post. The forum's "this lesson" filter follows the input.
    if (this.activeTab !== 'discussion') {
      this.activeTab = 'transcript';
    }
    this.settingsOpen = false;
    this.outlineOpen = false;

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

    // Asynchronous: the content needs a ticket before an iframe can fetch
    // it. `scormReady` flips when the URL arrives. The wrapper then asks for
    // its seed (`scorm_wrapper_ready`) before launching the package.
    this.buildScormUrl(lesson);
  }

  /**
   * The package's own entry point, with a ticket that lets it be read.
   *
   * The URL is the API's SCORM wrapper page, which carries the runtime the
   * package talks to and relays every call here by postMessage. Its origin is
   * remembered so messages can be checked against it.
   */
  private buildScormUrl(lesson: Lesson): void {
    this.currentScormUrl = null;

    if (!this.courseData || !lesson.content_url) {
      return;
    }

    this.playerService
      .scormLaunchUrl(lesson.content_url)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (url) => {
          // A slow ticket for a lesson already left must not open its package
          // over the lesson now on screen.
          if (this.currentLesson?.lesson_id !== lesson.lesson_id) {
            return;
          }

          if (url !== null) {
            try {
              this.scormOrigin = new URL(url, window.location.href).origin;
            } catch {
              this.scormOrigin = null;
            }
          }

          this.scormFrameLessonId = url === null ? null : lesson.lesson_id;
          this.currentScormUrl = url === null
            ? null
            : this.sanitizer.bypassSecurityTrustResourceUrl(url);
          this.scormReady = this.currentScormUrl !== null;
        },
        error: (error: { message?: string }) =>
          this.error_notification(error.message ?? this.i18n.t('course_player.toast_package_failed')),
      });
  }

  /**
   * Messages from the SCORM wrapper iframe.
   *
   * Every message names the lesson it is about (`lessonId`, echoed from the
   * seed), and is saved against THAT lesson — never blindly against whatever
   * is on screen now. A package's last commit routinely lands after the
   * learner has clicked Next, and used to be written onto the next lesson.
   */
  @HostListener('window:message', ['$event'])
  onScormMessage(event: MessageEvent): void {
    const data = event.data;
    if (!data || typeof data !== 'object' || typeof data.type !== 'string' || !data.type.startsWith('scorm_')) {
      return;
    }
    const source = this.scormSourceOf(event);
    if (source === null) {
      return;
    }

    if (data.type === 'scorm_wrapper_ready') {
      this.seedScormWrapper(event);
      return;
    }

    const lessonId = this.scormLessonOf(data, source);
    if (lessonId === null || !this.courseData) {
      return;
    }

    const cmi = data.cmiData && typeof data.cmiData === 'object'
      ? (data.cmiData as Record<string, unknown>)
      : null;
    if (cmi !== null) {
      this.scormLatest.set(lessonId, cmi);
      this.scormDirty.add(lessonId);
    }

    const status = String(data.status ?? '').toLowerCase();
    const passed = status === 'completed' || status === 'passed';

    switch (data.type) {
      case 'scorm_initialize':
        // Nothing to save yet; the map is kept so a flush has the baseline.
        this.scormDirty.delete(lessonId);
        break;

      case 'scorm_commit':
        this.flushScorm(lessonId, false);
        break;

      case 'scorm_status_change':
        // Recorded, not advanced: a package marks itself complete on its last
        // slide and then shows a results screen, which a jump to the next
        // lesson would cut off. The learner moves on with Next.
        if (passed) {
          this.flushScorm(lessonId, false);
          this.completeScormLesson(lessonId, status, this.scormScore(lessonId), false);
        }
        break;

      case 'scorm_finish':
        this.flushScorm(lessonId, false);
        if (passed) {
          this.completeScormLesson(lessonId, status, this.scormScore(lessonId), true);
        }
        break;

      // 'scorm_state' is record-only: the map above is kept current so an
      // unload/destroy flush carries the latest bookmark; nothing is saved yet.
    }
  }

  /**
   * Only the wrapper page may talk to this handler. The frame on screen is
   * matched by identity; a wrapper that has just been torn down (its pagehide
   * commit arrives after the iframe is gone) is matched by the origin packages
   * are served from — the API's — and must still name one of this course's
   * SCORM lessons (see scormLessonOf) before anything is saved.
   */
  private scormSourceOf(event: MessageEvent): 'frame' | 'origin' | null {
    const frameWindow = this.scormFrame?.nativeElement.contentWindow ?? null;

    if (frameWindow !== null && event.source === frameWindow) {
      return 'frame';
    }

    return this.scormOrigin !== null && event.origin === this.scormOrigin ? 'origin' : null;
  }

  /**
   * The SCORM lesson a message is about, or null if it is not one of ours.
   * Only the frame on screen may fall back to "the lesson it was opened for";
   * anything else must say which lesson it means, or it could be a torn-down
   * package's commit landing on the lesson that replaced it.
   */
  private scormLessonOf(data: { lessonId?: unknown }, source: 'frame' | 'origin'): string | null {
    const named = typeof data.lessonId === 'string' && data.lessonId !== '' ? data.lessonId : null;
    const id = named ?? (source === 'frame' ? this.scormFrameLessonId : null);

    if (id === null) {
      return null;
    }

    return this.playerService.findLesson(id)?.content_type === 'scorm' ? id : null;
  }

  /**
   * Answers the wrapper's `scorm_wrapper_ready` with the learner's saved state,
   * which it loads into its CMI map BEFORE launching the package — the only
   * moment a package reads `cmi.core.entry` / `cmi.suspend_data` to resume.
   * An unsaved map from earlier in this visit wins over the loaded one.
   */
  private seedScormWrapper(event: MessageEvent): void {
    const frameWindow = this.scormFrame?.nativeElement.contentWindow ?? null;
    const lessonId = this.scormFrameLessonId;

    if (lessonId === null || event.source === null || (frameWindow !== null && event.source !== frameWindow)) {
      return;
    }

    const pending = this.scormLatest.get(lessonId);
    const stored = this.playerService.findLesson(lessonId)?.scorm_state ?? null;
    // An unsaved map wins, merged over the stored state so a field the
    // snapshot dropped (over the API's limit) keeps its last good value.
    const saved = pending
      ? { ...(stored ?? toScormState({})!), ...scormSnapshot(pending).state }
      : stored;

    const targetOrigin = event.origin && event.origin !== 'null' ? event.origin : '*';

    (event.source as Window).postMessage(
      { type: 'scorm_seed', lessonId, state: this.scormAdapter.seed(saved) },
      targetOrigin,
    );
  }

  /** Saves a package's latest unsaved CMI map, if it has one. */
  private flushScorm(lessonId: string, keepalive: boolean): void {
    const cmi = this.scormLatest.get(lessonId);
    const courseId = this.courseData?.course.course_id ?? this.courseId;

    if (!cmi || !this.scormDirty.has(lessonId) || !courseId) {
      return;
    }

    this.scormDirty.delete(lessonId);

    this.playerService
      .saveScormData(courseId, lessonId, cmi, { keepalive })
      .subscribe({
        // Not on screen: commits come every few seconds while somebody works
        // through a package, and a toast per failure would bury the lesson.
        // Marked dirty again so the next commit or flush retries it.
        error: () => this.scormDirty.add(lessonId),
      });
  }

  private flushAllScorm(keepalive: boolean): void {
    for (const lessonId of [...this.scormDirty]) {
      this.flushScorm(lessonId, keepalive);
    }
  }

  /**
   * Marks a SCORM lesson complete with its score.
   *
   * It used to reload the whole course afterwards, which re-ran the lesson
   * auto-select and rebuilt the package's iframe mid-results-screen. The
   * completion write already updates the outline and the header in place.
   */
  private completeScormLesson(lessonId: string, status: string, score: number | undefined, advance: boolean): void {
    if (!this.courseData) return;
    const lesson = this.playerService.findLesson(lessonId);
    if (!lesson) return;

    // Already recorded — typically by the status change on the last slide —
    // or being recorded right now: no second write or toast, but a finish
    // (the package closing itself) must still move the learner on.
    if (lesson.status === 'completed' || this.scormCompleting.has(lessonId)) {
      if (advance) this.advanceAfterScorm(lessonId);
      return;
    }

    this.scormCompleting.add(lessonId);
    this.playerService.completeScormLesson(this.courseData.course.course_id, lessonId, status, score)
      .pipe(finalize(() => this.scormCompleting.delete(lessonId)))
      .subscribe({
        next: () => {
          this.success_notification(this.i18n.t('course_player.toast_lesson_completed'));
          if (advance) this.advanceAfterScorm(lessonId);
          else if (this.isAllLessonsCompleted()) this.showCompletionModal = true;
        },
        error: () => undefined,
      });
  }

  /** Moves on after a package closed itself — only if the learner is still on it. */
  private advanceAfterScorm(lessonId: string): void {
    if (this.currentLesson?.lesson_id !== lessonId) return;
    setTimeout(() => {
      if (this.currentLesson?.lesson_id !== lessonId) return;
      const next = this.playerService.getNextLesson();
      if (next) this.selectLesson(next);
      else if (this.isAllLessonsCompleted()) this.showCompletionModal = true;
    }, 1000);
  }

  /** The package's score as the API takes it (0–100), from its latest CMI map. */
  private scormScore(lessonId: string): number | undefined {
    const cmi = this.scormLatest.get(lessonId);
    return cmi ? scormSnapshot(cmi).score : undefined;
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
      this.courseData.course.course_id,
      this.currentLesson.lesson_id,
      timeSpent,
    ).subscribe({
      next: (update) => {
        // Update module lesson status
        for (const module of this.courseData!.modules) {
          const lesson = module.lessons.find(l => l.lesson_id === this.currentLesson!.lesson_id);
          if (lesson) {
            lesson.status = 'completed';
            this.success_notification(this.i18n.t('course_player.toast_lesson_completed'));
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

    // The server marks an enrolment complete inside the transaction that
    // records the last mandatory lesson, so there is nothing to declare —
    // this reads back what it decided. The old endpoint let a client
    // complete a course by asking.
    this.playerService.completeCourse(this.courseData.course.course_id).subscribe({
      next: () => {
        this.showCompletionModal = false;
        this.router.navigate(['/courses', this.courseData!.course.course_id]);
      },
      error: (error: { message?: string }) =>
        this.error_notification(error.message ?? this.i18n.t('course_player.toast_finish_failed')),
    });
  }

  // ==========================================
  // VIDEO CONTROLS
  // ==========================================

  onVideoLoaded(video: HTMLVideoElement): void {
    this.videoElement = video;
    this.duration = video.duration;
    video.playbackRate = this.playbackRate;
    video.volume = this.volume;

    // Resume from last position
    if (this.currentLesson && this.currentLesson.last_position > 0) {
      video.currentTime = this.currentLesson.last_position;
    }
  }

  onTimeUpdate(time: number): void {
    this.currentTime = time;
    this.playerService.updateVideoPosition(Math.floor(time));

    const lesson = this.currentLesson;
    if (!lesson || lesson.status === 'completed' || !(this.duration > 0)) {
      return;
    }

    // How far through the media this is becomes the lesson's progress, so the
    // heartbeat carries a real percentage instead of the 0 it loaded with.
    // Capped at 99: finishing is a separate, explicit write.
    if (lesson.content_type === 'video' || lesson.content_type === 'audio') {
      const pct = Math.min(99, Math.floor((time / this.duration) * 100));
      if (pct > (lesson.progress || 0)) {
        lesson.progress = pct;
      }
    }

    // Auto-complete at 90% for videos (not SCORM)
    if (lesson.content_type === 'video' && (time / this.duration) >= 0.9) {
      this.onVideoComplete();
    }
  }

  // Content Interaction Tracking
  onVideoPlay(): void {
    this.isPlaying = true;
    this.onVideoActivity();
    this.trackContentInteraction('video_play', Math.floor(this.currentTime));
  }

  onVideoPause(): void {
    this.isPlaying = false;
    this.controlsIdle = false;
    this.clearIdleTimer();
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

    // Per-interaction telemetry had a legacy endpoint and no table on
    // this side. Kept as a call so the dozen places that fire it read
    // the same, and it does nothing rather than erroring on every pause.
    this.playerService.trackContentInteraction().subscribe();
  }

  togglePlay(): void {
    if (!this.videoElement) return;

    // The element's play/pause events set `isPlaying`; flipping it here as
    // well went wrong whenever play() was refused (autoplay policy).
    if (this.videoElement.paused) {
      void this.videoElement.play().catch(() => undefined);
    } else {
      this.videoElement.pause();
    }
  }

  seek(seconds: number): void {
    if (!this.videoElement) return;
    this.videoElement.currentTime = Math.max(0, Math.min(this.duration, this.videoElement.currentTime + seconds));
    this.onVideoActivity();
  }

  scrubTo(value: string | number): void {
    if (!this.videoElement) return;
    this.videoElement.currentTime = Number(value) || 0;
  }

  /** How much of the scrubber is filled, 0–100. */
  get playedPercent(): number {
    return this.duration > 0 ? Math.min(100, (this.currentTime / this.duration) * 100) : 0;
  }

  setPlaybackRate(rate: number): void {
    if (!this.videoElement) return;
    this.videoElement.playbackRate = rate;
    this.playbackRate = rate;
  }

  setVolume(vol: number | string): void {
    const value = Math.max(0, Math.min(1, Number(vol)));
    this.volume = value;
    if (this.videoElement) {
      this.videoElement.volume = value;
    }
  }

  /**
   * Fullscreen on the player frame, not the bare <video>: the native
   * element's fullscreen drops these controls for the browser's own.
   */
  toggleFullscreen(): void {
    if (document.fullscreenElement) {
      void document.exitFullscreen?.().catch(() => undefined);
      return;
    }

    const wrap = this.videoWrap?.nativeElement;
    if (document.fullscreenEnabled && wrap?.requestFullscreen) {
      void wrap.requestFullscreen().catch(() => undefined);
      return;
    }

    // iPhone Safari has no element Fullscreen API; only the <video> itself can
    // enter its native player (needed now that the element is playsinline).
    const video = this.videoElement as (HTMLVideoElement & { webkitEnterFullscreen?: () => void }) | null;
    if (document.fullscreenEnabled && video?.requestFullscreen) {
      void video.requestFullscreen().catch(() => undefined);
    } else {
      video?.webkitEnterFullscreen?.();
    }
  }

  @HostListener('document:fullscreenchange')
  onFullscreenChange(): void {
    this.isFullscreen = document.fullscreenElement !== null;
  }

  /** Pointer or key activity over the video: show the controls, then fade them. */
  onVideoActivity(): void {
    this.controlsIdle = false;
    this.clearIdleTimer();
    this.idleTimer = setTimeout(() => {
      if (this.isPlaying && !this.settingsOpen) {
        this.controlsIdle = true;
      }
    }, 2600);
  }

  private clearIdleTimer(): void {
    if (this.idleTimer !== null) {
      clearTimeout(this.idleTimer);
      this.idleTimer = null;
    }
  }

  // ==========================================
  // BOOKMARKS
  // ==========================================

  /**
   * Bookmarks have no server behind them.
   *
   * No table for them survived the migration. A note carries a position,
   * which is the same thing with words attached, so that is where this
   * sends somebody rather than silently dropping what they marked.
   */
  addBookmark(): void {
    if (!this.courseData || !this.currentLesson) return;

    this.noteTimestamp = Math.floor(this.currentTime);
    this.selectBelowTab('notes');
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

    this.playerService.getNotes().subscribe({
      next: (notes) => this.notes = notes,
    });
  }

  loadLessonNotes(lessonId: string): void {
    if (!this.courseData) return;

    this.playerService.getNotes(lessonId).subscribe({
      next: (notes) => this.notes = notes,
    });
  }

  saveNoteFromPanel(): void {
    if (!this.courseData || !this.currentLesson || !this.newNote.trim()) return;

    this.playerService.saveNote(
      this.currentLesson.lesson_id,
      this.newNote.trim(),
      Math.floor(this.currentTime),
      this.currentLesson.title,
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

  /**
   * Rating a course has no server behind it either.
   *
   * `courses.rating` is a column the catalogue reads and nothing writes.
   * Rather than post into the void, this says so and lets somebody leave.
   */
  submitRating(): void {
    if (!this.courseData || this.userRating === 0) return;

    this.error_notification(this.i18n.t('course_player.toast_rating_unavailable'));
    this.skipRating();
  }

  skipRating(): void {
    this.showRatingModal = false;
    this.router.navigate(['/courses', this.courseData!.course.course_id]);
  }

  // ==========================================
  // UI HELPERS
  // ==========================================

  /**
   * One button, two behaviours: on a wide screen it folds the outline column
   * away; on a narrow one — where the stage comes first — it opens the
   * outline as a drawer over the page.
   */
  toggleSidebar(): void {
    if (this.isNarrow()) {
      this.outlineOpen = !this.outlineOpen;
    } else {
      this.sidebarCollapsed = !this.sidebarCollapsed;
    }
  }

  private isNarrow(): boolean {
    return typeof window !== 'undefined'
      && typeof window.matchMedia === 'function'
      && window.matchMedia(NARROW_QUERY).matches;
  }

  formatTime(seconds: number): string {
    return this.playerService.formatDuration(Math.floor(seconds || 0));
  }

  /** Two-digit padding, as the design shows: "01", "00 of 16". */
  padded(value: number): string {
    const n = Math.max(0, Math.round(value || 0));
    return n < 10 ? '0' + n : String(n);
  }

  /** The module holding the lesson on screen — the lesson header's eyebrow. */
  get currentModule(): Module | null {
    const lesson = this.currentLesson;
    if (!lesson || !this.courseData) return null;
    return this.courseData.modules.find(m => m.module_id === lesson.module_id) ?? null;
  }

  /** "Lesson 3 of 12" — where the current lesson sits in the whole course. */
  get lessonPosition(): { current: number; total: number } | null {
    const lesson = this.currentLesson;
    if (!lesson || !this.courseData) return null;
    const all = this.courseData.modules.flatMap(m => m.lessons);
    const index = all.findIndex(l => l.lesson_id === lesson.lesson_id);
    return index === -1 ? null : { current: index + 1, total: all.length };
  }

  /** Completed lessons in a module, counted from the outline so it never drifts. */
  moduleDone(module: Module): number {
    return module.lessons.filter(l => l.status === 'completed').length;
  }

  modulePercent(module: Module): number {
    return module.lessons.length ? Math.round((this.moduleDone(module) / module.lessons.length) * 100) : 0;
  }

  /** The i18n key for a lesson's status chip. */
  statusKey(status: Lesson['status'] | undefined): string {
    return 'course_player.status_' + (status ?? 'not_started');
  }

  /** The circumference of the top bar's progress ring (r = 15). */
  readonly ringCircumference = 2 * Math.PI * 15;

  get ringOffset(): number {
    return this.ringCircumference * (1 - Math.min(100, this.getProgressPercentage()) / 100);
  }

  /** The message under the progress bar, by how far along the learner is. */
  get progressMessageKey(): string {
    const p = this.getProgressPercentage();
    if (p >= 100) return 'course_player.progress_done';
    if (p <= 0) return 'course_player.progress_start';
    return 'course_player.keep_going';
  }

  /** Whether the stage shows a dark theatre for this lesson's media. */
  get hasTheater(): boolean {
    const type = this.currentLesson?.content_type;
    return !this.showQuizView && !!type && ['video', 'audio', 'pdf', 'html', 'scorm'].includes(type);
  }

  // ==========================================
  // MODULE ACCORDION
  // ==========================================

  isModuleOpen(module: Module, index: number): boolean {
    // The first module, the one holding the current lesson, and any the
    // learner has opened by hand.
    if (this.openModules.has(module.module_id)) return true;
    if (this.openModules.size === 0 && index === 0) return true;
    return module.module_id === this.currentLesson?.module_id;
  }

  toggleModule(moduleId: string): void {
    if (this.openModules.has(moduleId)) {
      this.openModules.delete(moduleId);
    } else {
      this.openModules.add(moduleId);
    }
  }

  restart(): void {
    if (this.videoElement) {
      this.videoElement.currentTime = 0;
    }
  }

  // ==========================================
  // SIDEBAR TABS: PATH / LEARNERS
  // ==========================================

  selectSidebarTab(tab: 'path' | 'learners'): void {
    this.sidebarTab = tab;

    // Loaded lazily and once: opening the player should not fetch the
    // classmates nobody may look at.
    if (tab === 'learners' && !this.classmatesLoaded) {
      this.loadClassmates();
    }
  }

  private loadClassmates(): void {
    if (!this.courseId) return;
    this.playerService.getClassmates(this.courseId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (people) => { this.classmates = people; this.classmatesLoaded = true; },
        error: () => { this.classmates = []; this.classmatesLoaded = true; },
      });
  }

  // ==========================================
  // BELOW-CONTENT TABS: TRANSCRIPT / NOTES / RESOURCES / DISCUSSION
  // ==========================================

  selectBelowTab(tab: BelowTab): void {
    this.activeTab = tab;

    // The forum mounts the first time its tab is opened (it loads its thread
    // on mount, and most visits never look), then stays mounted — hidden, not
    // destroyed — so a draft, reply or filter survives switching tabs.
    if (tab === 'discussion') {
      this.discussMounted = true;
    }
  }

  onDiscussionCount(count: number): void {
    this.discussionCount = count;
  }

  // ==========================================
  // TRANSCRIPT & RESOURCES
  // ==========================================

  /** The transcript of the current lesson, where it has one. */
  get transcript(): string {
    return this.currentLesson?.content_text?.trim() ?? '';
  }

  /**
   * Downloadable resources: every lesson across the course whose content is a
   * file rather than a stream — a PDF, a document, an audio file, a package.
   * Real data, not a separate attachment store the schema does not have.
   */
  get resources(): Array<{
    lessonId: string;
    title: string;
    url: string;
    type: string;
    module: string;
  }> {
    if (!this.courseData) return [];

    const downloadable = new Set(['pdf', 'audio', 'scorm', 'html']);
    const out: Array<{ lessonId: string; title: string; url: string; type: string; module: string }> = [];

    for (const module of this.courseData.modules) {
      for (const lesson of module.lessons) {
        if (downloadable.has(lesson.content_type) && lesson.content_url) {
          out.push({
            lessonId: lesson.lesson_id,
            title: lesson.title,
            url: lesson.content_url,
            type: lesson.content_type,
            module: module.title,
          });
        }
      }
    }

    return out;
  }

  get resourceCount(): number {
    return this.resources.length + this.courseResources.length;
  }

  /** The three-letter badge and its colour class for a resource file type. */
  resourceBadge(type: string): { label: string; cls: string } {
    const map: Record<string, { label: string; cls: string }> = {
      pdf:   { label: 'PDF', cls: 'badge-pdf' },
      audio: { label: 'MP3', cls: 'badge-audio' },
      scorm: { label: 'ZIP', cls: 'badge-zip' },
      html:  { label: 'DOC', cls: 'badge-doc' },
    };
    return map[type] ?? { label: 'FILE', cls: 'badge-doc' };
  }

  downloadResource(url: string): void {
    window.open(url, '_blank', 'noopener');
    this.onDocumentDownload();
  }

  /** The files an author attached to the course, of any type. */
  loadCourseResources(courseId: string): void {
    this.courseResourcesService
      .list(courseId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (resources) => (this.courseResources = resources),
        error: () => undefined,
      });
  }

  downloadCourseResource(resource: CourseResource): void {
    this.courseResourcesService.download(this.courseId, resource);
    this.onDocumentDownload();
  }

  /** The per-lesson meta line shown in the path — "05:00", "Ebook", "Quiz: 5 questions". */
  lessonMeta(lesson: Lesson): string {
    switch (lesson.content_type) {
      case 'video':
      case 'audio':
        return this.formatTime(lesson.duration_minutes * 60);
      case 'pdf':
        return this.i18n.t('course_player.meta_ebook');
      case 'html':
      case 'text':
        return lesson.duration_minutes
          ? this.i18n.t('course_player.meta_reading_minutes', { minutes: lesson.duration_minutes })
          : this.i18n.t('course_player.meta_reading');
      case 'scorm':
        return this.i18n.t('course_player.meta_interactive');
      default:
        return '';
    }
  }

  /** The typed icon for a lesson in the path, matching the design's set. */
  pathLessonIcon(lesson: Lesson): string {
    const icons: Record<string, string> = {
      video: 'bi-play-circle',
      audio: 'bi-music-note-beamed',
      pdf: 'bi-file-earmark-text',
      html: 'bi-book',
      text: 'bi-book',
      scorm: 'bi-box',
    };
    return icons[lesson.content_type] ?? 'bi-file-earmark';
  }

  assessmentMeta(a: Assessment): string {
    if (a.assessment_type === 'assignment') return this.i18n.t('course_player.meta_assignment');
    const n = a.total_questions || 0;
    const label = a.assessment_type === 'exam'
      ? this.i18n.t('course_player.meta_exam')
      : this.i18n.t('course_player.meta_quiz');
    if (!n) return label;
    return n === 1
      ? this.i18n.t('course_player.meta_question_count', { label, count: n })
      : this.i18n.t('course_player.meta_questions_count', { label, count: n });
  }

  pathAssessmentIcon(a: Assessment): string {
    return a.assessment_type === 'assignment' ? 'bi-layout-text-window' : 'bi-question-circle';
  }

  getProgressPercentage(): number {
    return Math.round(this.courseData?.enrollment.progress_percentage || 0);
  }

  // ==========================================
  // ASSESSMENT / QUIZ
  // ==========================================

  selectAssessment(assessment: Assessment): void {
    // Save the package being left, then close any current lesson.
    if (this.currentLesson?.content_type === 'scorm') {
      this.flushScorm(this.currentLesson.lesson_id, false);
    }
    this.currentLesson = null;

    this.scormReady = false;
    this.currentScormUrl = null;
    this.scormFrameLessonId = null;
    this.outlineOpen = false;

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

  // ==========================================
  // KEYBOARD SHORTCUTS
  // ==========================================

  @HostListener('document:keydown', ['$event'])
  handleKeyboard(event: KeyboardEvent): void {
    // Never while somebody is typing — an input, a textarea, a select, or a
    // contenteditable surface such as the forum's rich-text editor, which now
    // sits directly under the video. Space there used to toggle playback and
    // swallow the space; m/f/b/n fired too. Chords belong to the browser.
    if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) {
      return;
    }
    if (isTypingTarget(event.target)) {
      return;
    }

    if (event.key === 'Escape') {
      this.outlineOpen = false;
      this.settingsOpen = false;
      return;
    }

    if (event.key === 'b' || event.key === 'n') {
      // The note modal is gone; both shortcuts open the Notes tab at the
      // current timestamp, which is where a note is now written.
      this.noteTimestamp = Math.floor(this.currentTime);
      this.selectBelowTab('notes');
      return;
    }

    // A lesson-navigation button keeps focus after a click (Chrome/Edge), so a
    // later Space would click it again — skipping a second lesson, or
    // rebuilding a SCORM package mid-session. Space never re-fires those.
    const navTarget = event.key === ' '
      && event.target instanceof Element
      && event.target.closest('.cp-iconbtn, .cp-navcard, .cp-lesson') !== null;

    // The rest drive the video, and only when there is one on screen —
    // otherwise Space and the arrows keep scrolling the page.
    if (this.showQuizView || this.currentLesson?.content_type !== 'video' || !this.videoElement) {
      if (navTarget) event.preventDefault();
      return;
    }

    switch (event.key) {
      case ' ':
        // Space works natively only on the video's own controls (same effect
        // as ours); anywhere else — including a focused nav button — it plays.
        if (!navTarget && event.target instanceof Element
            && event.target.closest('.cp-video button, .cp-video [role="button"], .cp-video a')) {
          return;
        }
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
        this.toggleFullscreen();
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

/** Whether a key event comes from somewhere the learner is typing. */
function isTypingTarget(target: EventTarget | null): boolean {
  if (!(target instanceof Element)) {
    return false;
  }
  if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement) {
    return true;
  }
  if (target instanceof HTMLElement && target.isContentEditable) {
    return true;
  }
  return target.closest('[contenteditable=""], [contenteditable="true"], [role="textbox"]') !== null;
}

