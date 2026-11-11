// course-player/components/course-discussions/course-discussions.component.ts

import { Component, OnDestroy, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, RouterModule } from '@angular/router';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { ApiClient, Endpoints } from '../../../core/api';
import { TranslatePipe } from '../../../translate.pipe';
import { CourseDiscussionComponent } from '../course-discussion/course-discussion.component';

/**
 * The course forum as a page in its own right, for when someone wants to read
 * and take part in the discussion without being inside the lesson player.
 *
 * It is a thin frame — a header that names the course and links back to the
 * player — around the shared CourseDiscussionComponent, which does all the
 * work. The forum itself is enrolment-gated on the server, so this page is
 * safe to reach by URL.
 */
@Component({
  selector: 'app-course-discussions',
  standalone: true,
  imports: [CommonModule, RouterModule, TranslatePipe, CourseDiscussionComponent],
  templateUrl: './course-discussions.component.html',
  styleUrl: './course-discussions.component.scss',
})
export class CourseDiscussionsComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();

  courseId = '';
  courseTitle = '';

  constructor(
    private route: ActivatedRoute,
    private api: ApiClient,
  ) {}

  ngOnInit(): void {
    this.courseId = String(this.route.snapshot.paramMap.get('courseId') ?? '');
    if (this.courseId) {
      this.loadCourseName();
    }
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  /** The course title for the header — best-effort; the forum works without it. */
  private loadCourseName(): void {
    this.api.get<{ title?: string }>(Endpoints.courses.show(this.courseId))
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (course) => { this.courseTitle = course?.title ?? ''; },
        error: () => { /* leave the header generic */ },
      });
  }
}
