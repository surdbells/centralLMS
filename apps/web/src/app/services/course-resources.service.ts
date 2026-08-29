import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, filter, map } from 'rxjs';
import { ApiClient, Endpoints } from '../core/api';

/** One downloadable file attached to a course. */
export interface CourseResource {
  id: string;
  courseId: string;
  title: string;
  originalFilename: string;
  mimeType: string;
  sizeBytes: number;
  /** The file extension, for the badge (pdf, xlsx, zip…). */
  kind: string;
  position: number;
  createdAt: string | null;
}

/**
 * The material that sits alongside a course — a deck, a checklist, a standard —
 * of any file type. Added by whoever builds the course, offered to the learner
 * from the player's Resources tab.
 *
 * Downloads go through {@link HttpClient} as a blob rather than a plain link so
 * the auth interceptor can attach the token — the file endpoint is protected,
 * and an anchor cannot carry a bearer header.
 */
@Injectable({ providedIn: 'root' })
export class CourseResourcesService {
  private readonly api = inject(ApiClient);
  private readonly http = inject(HttpClient);

  list(courseId: string): Observable<CourseResource[]> {
    return this.api.get<CourseResource[]>(Endpoints.courses.resources(courseId));
  }

  upload(courseId: string, file: File, title: string): Observable<CourseResource> {
    const form = new FormData();
    form.append('file', file);
    form.append('title', title || file.name);
    return this.api.upload<CourseResource>(Endpoints.courses.resources(courseId), form).pipe(
      filter((p) => p.state === 'done'),
      map((p) => p.result as CourseResource),
    );
  }

  remove(courseId: string, resourceId: string): Observable<void> {
    return this.api
      .delete<void>(Endpoints.courses.resourceDelete(courseId, resourceId))
      .pipe(map(() => undefined));
  }

  /** Fetches the file with auth and triggers a browser download. */
  download(courseId: string, resource: CourseResource): void {
    this.http
      .get(this.api.urlFor(Endpoints.courses.resourceFile(courseId, resource.id)), {
        responseType: 'blob',
      })
      .subscribe({
        next: (blob) => {
          const url = URL.createObjectURL(blob);
          const link = document.createElement('a');
          link.href = url;
          link.download = resource.originalFilename;
          link.click();
          URL.revokeObjectURL(url);
        },
      });
  }
}
