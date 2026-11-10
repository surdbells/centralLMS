import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { ApiClient, Endpoints } from '../core/api';

export interface CourseReview {
  id: string;
  courseId: string;
  courseTitle: string | null;
  userId: string;
  authorName: string | null;
  rating: number;
  title: string | null;
  reviewText: string | null;
  contentRating: number | null;
  difficultyRating: number | null;
  practicalValueRating: number | null;
  isPublished: boolean;
  moderationStatus: string;
  createdAt: string | null;
  updatedAt: string | null;
}

export interface CourseRatingSummary {
  count: number;
  average: number;
  distribution: Record<number, number>;
  contentAverage: number | null;
  difficultyAverage: number | null;
  practicalAverage: number | null;
}

export interface CourseReviewInput {
  rating: number;
  title?: string | null;
  reviewText?: string | null;
  contentRating?: number | null;
  difficultyRating?: number | null;
  practicalValueRating?: number | null;
}

/**
 * Learner evaluations of a course (the 5-star rating). The caller is always the
 * subject — there is no user id in any of these.
 */
@Injectable({ providedIn: 'root' })
export class CourseReviewsService {
  private readonly api = inject(ApiClient);

  /** Every course the caller has rated. */
  mine(): Observable<CourseReview[]> {
    return this.api.get<CourseReview[]>(Endpoints.me.reviews);
  }

  /** Leave or change the caller's rating for a course. */
  submit(
    courseId: string,
    input: CourseReviewInput,
  ): Observable<{ review: CourseReview; summary: CourseRatingSummary }> {
    return this.api.put<{ review: CourseReview; summary: CourseRatingSummary }>(
      Endpoints.courses.review(courseId),
      input,
    );
  }

  /** Remove the caller's own rating. */
  remove(courseId: string): Observable<void> {
    return this.api.delete<void>(Endpoints.courses.review(courseId)).pipe(map(() => undefined));
  }

  /** The published reviews and rating summary for a course. */
  forCourse(
    courseId: string,
  ): Observable<{ summary: CourseRatingSummary; items: CourseReview[]; total: number }> {
    return this.api.get<{ summary: CourseRatingSummary; items: CourseReview[]; total: number }>(
      Endpoints.courses.reviews(courseId),
    );
  }
}
