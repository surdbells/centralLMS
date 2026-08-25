// course-player/services/analytics.service.ts

import { Injectable, OnDestroy } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Subject, BehaviorSubject, fromEvent, merge } from 'rxjs';
import { takeUntil, debounceTime, buffer, filter } from 'rxjs/operators';
import {GlobalComponent} from '../../global-component';

/**
 * Analytics and Data Capture Service
 * Tracks user interactions, engagement, and learning patterns
 */

export interface AnalyticsEvent {
  event_type: string;
  event_category: string;
  event_data: Record<string, any>;
  timestamp: number;
}

export interface EngagementMetrics {
  timeOnPage: number;
  scrollDepth: number;
  clicks: number;
  keystrokes: number;
  focusTime: number;
  idleTime: number;
}

export interface VideoAnalytics {
  totalWatchTime: number;
  uniqueWatchTime: number;
  pauseCount: number;
  seekCount: number;
  replayCount: number;
  averageSpeed: number;
  completionRate: number;
  dropOffPoints: number[];
}

@Injectable({
  providedIn: 'root'
})
export class AnalyticsService implements OnDestroy {
  private apiUrl = GlobalComponent.coursePlayer;
  private destroy$ = new Subject<void>();

  // Event queue for batching
  private eventQueue: AnalyticsEvent[] = [];
  private eventSubject = new Subject<AnalyticsEvent>();

  // Context
  private enrollmentId = 0;
  private lessonId = 0;
  private courseId = 0;
  private sessionId = 0;

  // Engagement tracking
  private pageStartTime = 0;
  private lastActivityTime = 0;
  private totalFocusTime = 0;
  private focusStartTime = 0;
  private isDocumentVisible = true;
  private scrollMaxDepth = 0;
  private clickCount = 0;
  private keystrokeCount = 0;

  // Video tracking
  private videoMetrics: VideoAnalytics = this.initVideoMetrics();
  private watchedSegments: Set<number> = new Set();
  private speedHistory: number[] = [];

  // Heartbeat
  private heartbeatInterval: any;

  // Status
  private isTracking = false;
  private isTrackingSubject = new BehaviorSubject<boolean>(false);
  isTracking$ = this.isTrackingSubject.asObservable();

  constructor(private http: HttpClient) {
    // Batch events and send every 10 seconds
    this.eventSubject.pipe(
      buffer(this.eventSubject.pipe(debounceTime(10000))),
      filter(events => events.length > 0),
      takeUntil(this.destroy$)
    ).subscribe(events => this.sendEvents(events));
  }

  ngOnDestroy(): void {
    this.stopTracking();
    this.destroy$.next();
    this.destroy$.complete();
  }

  /**
   * Start tracking for a lesson
   */
  startTracking(enrollmentId: number, lessonId: number, courseId: number, sessionId: number): void {
    this.enrollmentId = enrollmentId;
    this.lessonId = lessonId;
    this.courseId = courseId;
    this.sessionId = sessionId;

    this.pageStartTime = Date.now();
    this.lastActivityTime = Date.now();
    this.focusStartTime = Date.now();

    this.setupEventListeners();
    this.startHeartbeat();

    this.isTracking = true;
    this.isTrackingSubject.next(true);

    this.trackEvent('lesson_start', 'navigation', {
      lesson_id: lessonId,
      course_id: courseId
    });
  }

  /**
   * Stop tracking
   */
  stopTracking(): void {
    if (!this.isTracking) return;

    // Calculate final metrics
    this.updateFocusTime();

    // Track lesson end
    this.trackEvent('lesson_end', 'navigation', {
      lesson_id: this.lessonId,
      engagement: this.getEngagementMetrics(),
      video: this.videoMetrics
    });

    // Flush remaining events
    this.flushEvents();

    // Cleanup
    this.stopHeartbeat();
    this.resetMetrics();

    this.isTracking = false;
    this.isTrackingSubject.next(false);
  }

  /**
   * Track a custom event
   */
  trackEvent(type: string, category: string, data: Record<string, any> = {}): void {
    if (!this.isTracking) return;

    const event: AnalyticsEvent = {
      event_type: type,
      event_category: category,
      event_data: {
        ...data,
        enrollment_id: this.enrollmentId,
        lesson_id: this.lessonId,
        course_id: this.courseId,
        session_id: this.sessionId
      },
      timestamp: Date.now()
    };

    this.eventQueue.push(event);
    this.eventSubject.next(event);
  }

  // ==========================================
  // VIDEO TRACKING
  // ==========================================

  trackVideoPlay(position: number): void {
    this.trackEvent('video_play', 'video', { position });
  }

  trackVideoPause(position: number): void {
    this.videoMetrics.pauseCount++;
    this.trackEvent('video_pause', 'video', { position });
  }

  trackVideoSeek(from: number, to: number): void {
    this.videoMetrics.seekCount++;

    // Detect replay (seeking backward)
    if (to < from) {
      this.videoMetrics.replayCount++;
    }

    this.trackEvent('video_seek', 'video', { from, to });
  }

  trackVideoProgress(position: number, duration: number, watchedPercent: number): void {
    // Track watched segments (5-second chunks)
    const segment = Math.floor(position / 5);
    this.watchedSegments.add(segment);

    // Calculate unique watch time
    this.videoMetrics.uniqueWatchTime = this.watchedSegments.size * 5;
    this.videoMetrics.completionRate = watchedPercent;

    // Check for drop-off points (rewatched segments)
    // This is simplified - a real implementation would be more sophisticated
  }

  trackVideoSpeedChange(speed: number): void {
    this.speedHistory.push(speed);
    this.videoMetrics.averageSpeed =
      this.speedHistory.reduce((a, b) => a + b, 0) / this.speedHistory.length;

    this.trackEvent('video_speed_change', 'video', { speed });
  }

  trackVideoComplete(duration: number): void {
    this.videoMetrics.totalWatchTime = duration;
    this.trackEvent('video_complete', 'video', {
      duration,
      metrics: this.videoMetrics
    });
  }

  trackVideoError(error: string, position: number): void {
    this.trackEvent('video_error', 'video', { error, position });
  }

  // ==========================================
  // INTERACTION TRACKING
  // ==========================================

  trackQuizStart(assessmentId: number): void {
    this.trackEvent('quiz_start', 'assessment', { assessment_id: assessmentId });
  }

  trackQuizAnswer(questionId: number, answer: any, timeSpent: number): void {
    this.trackEvent('quiz_answer', 'assessment', {
      question_id: questionId,
      answer,
      time_spent: timeSpent
    });
  }

  trackQuizComplete(assessmentId: number, score: number, passed: boolean): void {
    this.trackEvent('quiz_complete', 'assessment', {
      assessment_id: assessmentId,
      score,
      passed
    });
  }

  trackNoteCreated(noteId: number, hasTimestamp: boolean): void {
    this.trackEvent('note_created', 'interaction', { note_id: noteId, has_timestamp: hasTimestamp });
  }

  trackBookmarkCreated(bookmarkId: number, position: number): void {
    this.trackEvent('bookmark_created', 'interaction', { bookmark_id: bookmarkId, position });
  }

  trackResourceDownload(resourceType: string, resourceId: number): void {
    this.trackEvent('resource_download', 'interaction', { resource_type: resourceType, resource_id: resourceId });
  }

  trackLinkClick(url: string, context: string): void {
    this.trackEvent('link_click', 'interaction', { url, context });
  }

  // ==========================================
  // ENGAGEMENT METRICS
  // ==========================================

  getEngagementMetrics(): EngagementMetrics {
    this.updateFocusTime();

    const now = Date.now();
    const totalTime = now - this.pageStartTime;
    const idleTime = totalTime - this.totalFocusTime;

    return {
      timeOnPage: Math.floor(totalTime / 1000),
      scrollDepth: this.scrollMaxDepth,
      clicks: this.clickCount,
      keystrokes: this.keystrokeCount,
      focusTime: Math.floor(this.totalFocusTime / 1000),
      idleTime: Math.floor(idleTime / 1000)
    };
  }

  getVideoMetrics(): VideoAnalytics {
    return { ...this.videoMetrics };
  }

  // ==========================================
  // PRIVATE METHODS
  // ==========================================

  private setupEventListeners(): void {
    // Visibility change
    fromEvent(document, 'visibilitychange')
      .pipe(takeUntil(this.destroy$))
      .subscribe(() => {
        if (document.hidden) {
          this.updateFocusTime();
          this.isDocumentVisible = false;
        } else {
          this.focusStartTime = Date.now();
          this.isDocumentVisible = true;
        }
      });

    // Scroll tracking
    fromEvent(window, 'scroll')
      .pipe(
        debounceTime(100),
        takeUntil(this.destroy$)
      )
      .subscribe(() => {
        const scrollTop = window.scrollY;
        const docHeight = document.documentElement.scrollHeight - window.innerHeight;
        const scrollPercent = Math.round((scrollTop / docHeight) * 100);

        if (scrollPercent > this.scrollMaxDepth) {
          this.scrollMaxDepth = scrollPercent;
        }

        this.lastActivityTime = Date.now();
      });

    // Click tracking
    fromEvent(document, 'click')
      .pipe(takeUntil(this.destroy$))
      .subscribe((event: Event) => {
        this.clickCount++;
        this.lastActivityTime = Date.now();

        // Track specific element clicks
        const target = event.target as HTMLElement;
        if (target.tagName === 'A') {
          const href = target.getAttribute('href');
          if (href) {
            this.trackLinkClick(href, 'content');
          }
        }
      });

    // Keystroke tracking
    fromEvent(document, 'keydown')
      .pipe(takeUntil(this.destroy$))
      .subscribe(() => {
        this.keystrokeCount++;
        this.lastActivityTime = Date.now();
      });

    // Before unload - save data
    fromEvent(window, 'beforeunload')
      .pipe(takeUntil(this.destroy$))
      .subscribe(() => {
        this.stopTracking();
      });
  }

  private startHeartbeat(): void {
    // Send heartbeat every 30 seconds
    this.heartbeatInterval = setInterval(() => {
      this.trackEvent('heartbeat', 'system', {
        engagement: this.getEngagementMetrics()
      });
    }, 30000);
  }

  private stopHeartbeat(): void {
    if (this.heartbeatInterval) {
      clearInterval(this.heartbeatInterval);
      this.heartbeatInterval = null;
    }
  }

  private updateFocusTime(): void {
    if (this.isDocumentVisible && this.focusStartTime > 0) {
      this.totalFocusTime += Date.now() - this.focusStartTime;
      this.focusStartTime = Date.now();
    }
  }

  private sendEvents(events: AnalyticsEvent[]): void {
    if (events.length === 0) return;

    const auth = this.getAuthParams();

    // Log interactions in batch
    events.forEach(event => {
      this.http.post(`${this.apiUrl}?action=log-interaction`, {
        ...auth,
        enrollment_id: this.enrollmentId,
        lesson_id: this.lessonId,
        interaction_type: event.event_type,
        interaction_data: event.event_data
      }).subscribe({
        error: (err) => console.error('Failed to log event:', err)
      });
    });
  }

  private flushEvents(): void {
    if (this.eventQueue.length > 0) {
      this.sendEvents(this.eventQueue);
      this.eventQueue = [];
    }
  }

  private resetMetrics(): void {
    this.pageStartTime = 0;
    this.lastActivityTime = 0;
    this.totalFocusTime = 0;
    this.focusStartTime = 0;
    this.scrollMaxDepth = 0;
    this.clickCount = 0;
    this.keystrokeCount = 0;
    this.videoMetrics = this.initVideoMetrics();
    this.watchedSegments.clear();
    this.speedHistory = [];
  }

  private initVideoMetrics(): VideoAnalytics {
    return {
      totalWatchTime: 0,
      uniqueWatchTime: 0,
      pauseCount: 0,
      seekCount: 0,
      replayCount: 0,
      averageSpeed: 1,
      completionRate: 0,
      dropOffPoints: []
    };
  }

  private getAuthParams(): { token: string; user_id: string; tenant_id: string } {
    return {
      token: localStorage.getItem('token') || '',
      user_id: localStorage.getItem('user_id') || '',
      tenant_id: localStorage.getItem('tenant_id') || ''
    };
  }
}
