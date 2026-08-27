import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TuiLoader } from '@taiga-ui/core';
import { HotToastService } from '@ngneat/hot-toast';
import { SsoService } from '../../services/sso.service';
import { SessionStore } from '../../core/auth/session.store';
import { ApiError } from '../../core/api';
import { environment } from '../../../environments/environment';

/**
 * Where Google and Microsoft send people back to.
 *
 * One address for the whole platform, registered once with each provider.
 * Which organisation and which directory a sign-in belongs to is carried by
 * the `state`, not by this URL, so adding an organisation needs no change at
 * Google.
 *
 * Nothing is decided here. The code and the state go to the API, which does
 * the exchange, verifies what comes back against the provider's own signing
 * keys, and answers with a session or a refusal. A browser cannot be trusted
 * to check a signature it also chose the key for.
 */
@Component({
  selector: 'app-sso-callback',
  standalone: true,
  imports: [CommonModule, TuiLoader, RouterLink],
  template: `
    <main class="d-flex align-items-center justify-content-center vh-100 bg-body-tertiary">
      <div class="text-center p-4">
        <ng-container *ngIf="!failed">
          <tui-loader />
          <p class="lead text-body-secondary mt-3">Signing you in…</p>
        </ng-container>

        <ng-container *ngIf="failed">
          <h1 class="fs-3">Could not sign you in</h1>
          <p class="lead text-body-secondary">{{ message }}</p>
          <a class="btn abayti_secondary_background text-white btn-secondary" routerLink="/login">
            Back to sign in
          </a>
        </ng-container>
      </div>
    </main>
  `,
})
export class SsoCallbackComponent implements OnInit {
  private readonly sso = inject(SsoService);
  private readonly session = inject(SessionStore);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly toast = inject(HotToastService);

  failed = false;
  message = '';

  ngOnInit(): void {
    const query = this.route.snapshot.queryParamMap;

    // The provider refused, or the person changed their mind at its screen.
    // `error_description` is the provider's own words and is not shown: it
    // names client ids and redirect URIs, which is configuration rather than
    // anything the person in front of the screen can act on.
    if (query.get('error') !== null) {
      this.refuse(
        query.get('error') === 'access_denied'
          ? 'That sign-in was cancelled.'
          : 'Your organisation’s directory refused that sign-in.',
      );

      return;
    }

    const code = query.get('code');
    const state = query.get('state');

    if (code === null || state === null) {
      this.refuse('That sign-in did not complete. Try again.');

      return;
    }

    this.sso.complete(code, state).subscribe({
      next: (signedIn) => {
        this.session.set(signedIn.session);
        this.toast.success(`Welcome back, ${signedIn.fullName}.`);
        void this.router.navigate(['/account']);
      },
      error: (failure: ApiError) => this.refuse(failure.message),
    });
  }

  private refuse(message: string): void {
    this.failed = true;
    this.message = message;
  }

  /** Kept for the template's benefit on deployments that brand this screen. */
  readonly publicSiteUrl = environment.publicSiteUrl;
}
