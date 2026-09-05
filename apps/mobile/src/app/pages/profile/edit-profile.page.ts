import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import {
  IonContent, IonHeader, IonToolbar, IonButtons, IonBackButton, IonTitle,
} from '@ionic/angular/standalone';
import { LearnerService } from '../../core/learner.service';
import { SessionStore } from '../../core/session';
import { ToastService } from '../../components/toast/toast.service';
import { FieldComponent } from '../../components/field/field.component';
import { ButtonComponent } from '../../components/button/button.component';
import { Profile } from '../../core/models';

@Component({
  selector: 'app-edit-profile',
  standalone: true,
  imports: [
    IonContent, IonHeader, IonToolbar, IonButtons, IonBackButton, IonTitle,
    FormsModule, FieldComponent, ButtonComponent,
  ],
  template: `
    <ion-header [translucent]="true">
      <ion-toolbar>
        <ion-buttons slot="start"><ion-back-button defaultHref="/tabs/profile"></ion-back-button></ion-buttons>
        <ion-title>Personal Information</ion-title>
      </ion-toolbar>
    </ion-header>

    <ion-content [fullscreen]="true">
      <div class="wrap">
        <div class="avatar-row">
          <span class="av">{{ initial() }}</span>
          <div class="who">
            <p class="nm">{{ shownName() }}</p>
            <p class="em">{{ email() }}</p>
          </div>
        </div>

        <form (ngSubmit)="save()">
          <app-field label="First name" icon="person-outline" [required]="true"
                     autocomplete="given-name" enterkeyhint="next"
                     [(ngModel)]="firstName" name="firstName"
                     [error]="submitted() && !firstName().trim() ? 'Enter your first name' : ''"></app-field>

          <app-field label="Last name" icon="person-outline" [required]="true"
                     autocomplete="family-name" enterkeyhint="next"
                     [(ngModel)]="lastName" name="lastName"
                     [error]="submitted() && !lastName().trim() ? 'Enter your last name' : ''"></app-field>

          <app-field label="Phone" type="tel" icon="call-outline" [clearable]="true"
                     autocomplete="tel" inputmode="tel" enterkeyhint="done"
                     hint="Optional"
                     [(ngModel)]="phone" name="phone"></app-field>

          <div class="actions">
            <app-button type="submit" size="lg" block [loading]="saving()" [disabled]="loading()">Save changes</app-button>
          </div>
        </form>
      </div>
    </ion-content>
  `,
  styles: [`
    .wrap { padding: 18px 16px calc(28px + env(safe-area-inset-bottom)); max-width: 560px; margin: 0 auto; }
    .avatar-row { display: flex; align-items: center; gap: 14px; margin-bottom: 22px; }
    .av { width: 58px; height: 58px; border-radius: var(--app-r); display: grid; place-items: center; color: #fff;
      font-size: 1.45rem; font-family: var(--app-font-display); font-weight: var(--app-display-weight); letter-spacing: var(--app-display-tracking);
      background: linear-gradient(135deg, var(--ion-color-primary), var(--ion-color-primary-shade)); }
    .who { min-width: 0; }
    .nm { margin: 0; font-size: 1.05rem; font-weight: 650; letter-spacing: -.01em; }
    .em { margin: 2px 0 0; font-size: .84rem; color: var(--app-muted); }
    form { display: grid; gap: 15px; }
    .actions { margin-top: 10px; }
  `],
})
export class EditProfilePage implements OnInit {
  private readonly learner = inject(LearnerService);
  private readonly session = inject(SessionStore);
  private readonly toast = inject(ToastService);
  private readonly router = inject(Router);

  readonly firstName = signal('');
  readonly lastName = signal('');
  readonly phone = signal('');

  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly submitted = signal(false);

  readonly email = computed(() => this.session.session()?.email || '');
  readonly shownName = computed(() =>
    `${this.firstName()} ${this.lastName()}`.trim() || this.session.session()?.name || 'Learner',
  );
  readonly initial = computed(() => (this.shownName().trim()[0] || 'L').toUpperCase());

  ngOnInit(): void {
    this.learner.profile().subscribe({
      next: (p: Profile) => {
        this.firstName.set((p.firstName as string) || '');
        this.lastName.set((p.lastName as string) || '');
        this.phone.set((p.phone as string) || '');
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  save(): void {
    this.submitted.set(true);
    const first = this.firstName().trim();
    const last = this.lastName().trim();
    if (!first || !last) {
      return;
    }

    this.saving.set(true);
    this.learner
      .updateProfile({ firstName: first, lastName: last, phone: this.phone().trim() || null })
      .subscribe({
        next: (p) => {
          this.saving.set(false);
          const current = this.session.session();
          const fullName = (p.fullName as string) || `${first} ${last}`.trim();
          if (current) {
            this.session.set({ ...current, name: fullName });
          }
          this.toast.success('Profile updated', { title: 'Saved' });
          void this.router.navigateByUrl('/tabs/profile');
        },
        error: () => {
          this.saving.set(false);
          this.toast.error('We couldn’t save your changes. Please try again.', { title: 'Update failed' });
        },
      });
  }
}
