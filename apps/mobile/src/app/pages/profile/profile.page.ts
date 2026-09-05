import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { IonContent } from '@ionic/angular/standalone';
import { BrandHeaderComponent } from '../../components/brand-header/brand-header.component';
import { LearnerService } from '../../core/learner.service';
import { AuthService } from '../../core/auth.service';
import { SessionStore } from '../../core/session';
import { Profile } from '../../core/models';
import { initialOf } from '../../core/ui';
import { ListComponent, ItemComponent } from '../../components/list/list.component';
import { ButtonComponent } from '../../components/button/button.component';

@Component({
  selector: 'app-profile',
  standalone: true,
  imports: [
    IonContent, BrandHeaderComponent, ListComponent, ItemComponent, ButtonComponent,
  ],
  template: `
    <ion-content [fullscreen]="true">
      <app-brand-header title="Profile"></app-brand-header>

      <div class="head">
        <div class="avatar">{{ initial(name()) }}</div>
        <h1>{{ name() }}</h1>
        <p class="email">{{ email() }}</p>
      </div>

      @if (jobTitle() || department()) {
        <div class="pad">
          <app-list>
            @if (jobTitle()) { <app-item icon="briefcase-outline" [note]="jobTitle()">Role</app-item> }
            @if (department()) { <app-item icon="business-outline" [note]="department()">Department</app-item> }
          </app-list>
        </div>
      }

      <div class="pad">
        <app-list>
          <app-item icon="ribbon-outline" button (tap)="go('/tabs/progress')">My progress &amp; certificates</app-item>
          <app-item icon="library-outline" button (tap)="go('/tabs/courses')">My courses</app-item>
        </app-list>
      </div>

      <div class="signout">
        <app-button variant="ghost" block icon="log-out-outline" style="--btn-fg: var(--ion-color-danger)" (click)="logout()">Sign out</app-button>
        <p class="ver muted">Central LMS · learner app</p>
      </div>
    </ion-content>
  `,
  styles: [`
    .pad { padding: 6px 16px; }
    .head { text-align: center; padding: 8px 16px 16px; }
    .avatar { width: 84px; height: 84px; border-radius: var(--app-r-lg); margin: 0 auto 12px; display: grid; place-items: center; color: #fff; font-size: 2.2rem; font-family: var(--app-font-display); font-weight: var(--app-display-weight); letter-spacing: var(--app-display-tracking); background: linear-gradient(135deg, var(--ion-color-primary), var(--ion-color-primary-shade)); }
    .head h1 { margin: 0; font-size: 1.3rem; font-family: var(--app-font-display); font-weight: var(--app-display-weight); letter-spacing: var(--app-display-tracking); }
    .head .email { margin: 4px 0 0; color: var(--ion-color-medium); font-size: .9rem; }
    .signout { padding: 12px 16px calc(24px + env(safe-area-inset-bottom)); }
    .ver { text-align: center; margin-top: 8px; font-size: .76rem; }
  `],
})
export class ProfilePage implements OnInit {
  private readonly learner = inject(LearnerService);
  private readonly auth = inject(AuthService);
  private readonly session = inject(SessionStore);
  private readonly router = inject(Router);

  private readonly profile = signal<Profile | null>(null);

  readonly name = computed(() => this.session.session()?.name || this.profile()?.fullName || 'Learner');
  readonly email = computed(() => this.session.session()?.email || this.profile()?.email || '');
  readonly jobTitle = computed(() => (this.profile()?.jobTitle as string) || '');
  readonly department = computed(() => (this.profile()?.department as string) || '');

  readonly initial = initialOf;

  ngOnInit(): void {
    this.learner.profile().subscribe({
      next: (p) => this.profile.set(p),
      error: () => this.profile.set(null),
    });
  }

  go(url: string): void {
    void this.router.navigateByUrl(url);
  }

  logout(): void {
    this.auth.logout();
    void this.router.navigateByUrl('/login', { replaceUrl: true });
  }
}
