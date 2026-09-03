import { Component, computed, inject } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { AuthService } from '../core/auth.service';
import { SessionStore } from '../core/session.store';

/**
 * The frame every signed-in screen sits in.
 *
 * A fixed rail with the three things the platform is run through — the
 * dashboard, the organisations, the plan catalogue — and, at its foot, who is
 * signed in and the way out. The screens themselves render their own header
 * so each owns its title and actions.
 */
@Component({
  selector: 'app-shell',
  standalone: true,
  imports: [RouterOutlet, RouterLink, RouterLinkActive],
  template: `
    <div class="op-shell">
      <aside class="op-rail">
        <div class="op-rail__brand">
          <span class="op-mark"><i class="bi bi-hexagon-fill"></i></span>
          <span>Central <span style="opacity:.6">Operator</span></span>
        </div>

        <div class="op-rail__eyebrow">Platform</div>
        <nav class="op-nav">
          <a class="op-nav__link" routerLink="/dashboard" routerLinkActive="active">
            <i class="bi bi-speedometer2"></i> Dashboard
          </a>
          <a class="op-nav__link" routerLink="/tenants" routerLinkActive="active">
            <i class="bi bi-building"></i> Organisations
          </a>
          <a class="op-nav__link" routerLink="/plans" routerLinkActive="active">
            <i class="bi bi-tags"></i> Plans
          </a>
        </nav>

        <div class="op-rail__foot">
          <div class="op-who">
            <span class="op-who__avatar">{{ initials() }}</span>
            <div style="min-width:0">
              <div class="op-who__name">{{ name() }}</div>
              <div class="op-who__email">{{ email() }}</div>
            </div>
          </div>
          <button type="button" class="op-btn op-btn--ghost op-btn--sm" style="width:100%;justify-content:center"
                  (click)="signOut()">
            <i class="bi bi-box-arrow-right"></i> Sign out
          </button>
        </div>
      </aside>

      <div class="op-main">
        <router-outlet />
      </div>
    </div>
  `,
})
export class ShellComponent {
  private readonly store = inject(SessionStore);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  readonly name = computed(() => this.store.session()?.fullName || 'Operator');
  readonly email = computed(() => this.store.session()?.email || '');
  readonly initials = computed(() => {
    const full = this.store.session()?.fullName?.trim();
    if (!full) {
      return 'OP';
    }

    const parts = full.split(/\s+/);
    const first = parts[0]?.[0] ?? '';
    const last = parts.length > 1 ? parts[parts.length - 1][0] : '';

    return (first + last).toUpperCase() || 'OP';
  });

  signOut(): void {
    this.auth.signOut().subscribe(() => void this.router.navigate(['/login']));
  }
}
