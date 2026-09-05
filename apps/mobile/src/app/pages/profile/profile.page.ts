import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import {
  IonContent, IonIcon, IonButton, IonList, IonItem, IonLabel, IonNote,
} from '@ionic/angular/standalone';
import { BrandHeaderComponent } from '../../components/brand-header/brand-header.component';
import { LearnerService } from '../../core/learner.service';
import { AuthService } from '../../core/auth.service';
import { SessionStore } from '../../core/session';
import { Profile } from '../../core/models';
import { initialOf } from '../../core/ui';

@Component({
  selector: 'app-profile',
  standalone: true,
  imports: [
    IonContent, IonIcon, IonButton, IonList, IonItem, IonLabel, IonNote,
    BrandHeaderComponent,
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
        <ion-list inset="true">
          @if (jobTitle()) {
            <ion-item>
              <ion-icon name="briefcase-outline" slot="start" color="primary"></ion-icon>
              <ion-label>Role</ion-label>
              <ion-note slot="end">{{ jobTitle() }}</ion-note>
            </ion-item>
          }
          @if (department()) {
            <ion-item>
              <ion-icon name="business-outline" slot="start" color="primary"></ion-icon>
              <ion-label>Department</ion-label>
              <ion-note slot="end">{{ department() }}</ion-note>
            </ion-item>
          }
        </ion-list>
      }

      <ion-list inset="true">
        <ion-item button="true" (click)="go('/tabs/progress')">
          <ion-icon name="ribbon-outline" slot="start" color="primary"></ion-icon>
          <ion-label>My progress &amp; certificates</ion-label>
        </ion-item>
        <ion-item button="true" (click)="go('/tabs/courses')">
          <ion-icon name="library-outline" slot="start" color="primary"></ion-icon>
          <ion-label>My courses</ion-label>
        </ion-item>
      </ion-list>

      <div class="signout">
        <ion-button expand="block" fill="clear" color="danger" (click)="logout()">
          <ion-icon name="log-out-outline" slot="start"></ion-icon>
          Sign out
        </ion-button>
        <p class="ver muted">Central LMS · learner app</p>
      </div>
    </ion-content>
  `,
  styles: [`
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
