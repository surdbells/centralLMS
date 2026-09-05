import { Injectable, inject } from '@angular/core';
import { BehaviorSubject, Observable, of } from 'rxjs';
import { catchError, finalize, tap } from 'rxjs/operators';
import { AuthService } from './auth.service';

/**
 * One refresh at a time, however many requests found the token expired.
 *
 * A screen that opens with several parallel calls has them all fail together
 * the moment the access token runs out. Each retrying on its own means several
 * refreshes — and since refreshing *rotates* the refresh token (so a stolen one
 * dies the instant the real client uses it), the first would succeed and the
 * rest would present a token that was just revoked, signing everyone out. So the
 * first caller performs the refresh and the rest wait on the same answer.
 *
 * Kept separate from AuthService so the interceptor's dependency stays one-way:
 * the service uses ApiClient, and an interceptor injecting the service would
 * close a circle through the very HTTP stack it sits in.
 */
@Injectable({ providedIn: 'root' })
export class TokenRefresher {
  private readonly auth = inject(AuthService);

  /** Null while a refresh is in flight; true/false once it has answered. */
  private readonly outcome$ = new BehaviorSubject<boolean | null>(true);
  private refreshing = false;

  refresh(): Observable<boolean | null> {
    if (this.refreshing) {
      return this.outcome$.asObservable();
    }

    this.refreshing = true;
    this.outcome$.next(null);

    this.auth
      .refresh()
      .pipe(
        catchError(() => of(false)),
        tap((succeeded) => this.outcome$.next(succeeded)),
        finalize(() => (this.refreshing = false)),
      )
      .subscribe();

    return this.outcome$.asObservable();
  }
}
