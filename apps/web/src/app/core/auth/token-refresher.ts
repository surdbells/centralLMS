import { Injectable, inject } from '@angular/core';
import { BehaviorSubject, Observable, of } from 'rxjs';
import { catchError, finalize, tap } from 'rxjs/operators';
import { AuthService } from './auth.service';

/**
 * One refresh at a time, however many requests found the token expired.
 *
 * A screen that opens with four parallel calls will have all four fail
 * together the moment the access token runs out. Each retrying on its own
 * means four refreshes, and since refreshing *rotates* the refresh token —
 * deliberately, so a stolen one is dead the moment the real client uses it —
 * the first would succeed and the other three would present a token that has
 * just been revoked. All four would be signed out.
 *
 * So the first caller performs the refresh and the rest wait on the same
 * answer.
 *
 * Separate from {@link AuthService} to keep the interceptor's dependency
 * one-way: the service uses `ApiClient`, and an interceptor that injected the
 * service would close a circle through the very HTTP stack it sits in.
 */
@Injectable({ providedIn: 'root' })
export class TokenRefresher {
  private readonly auth = inject(AuthService);

  /**
   * Null while a refresh is in flight; true or false once it has answered.
   *
   * Callers filter the null out and take the first real answer, so a request
   * that arrives mid-refresh waits rather than starting a second one.
   */
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
