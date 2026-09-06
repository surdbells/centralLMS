import { Component, ElementRef, OnInit, computed, inject, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import {
  IonContent, IonHeader, IonToolbar, IonButtons, IonBackButton, IonTitle, IonIcon, IonSpinner,
} from '@ionic/angular/standalone';
import { LearnerService } from '../../core/learner.service';
import { SessionStore } from '../../core/session';
import { ApiError } from '../../core/api';
import { ToastService } from '../../components/toast/toast.service';
import { FieldComponent } from '../../components/field/field.component';
import { ButtonComponent } from '../../components/button/button.component';
import { AvatarComponent } from '../../components/avatar/avatar.component';
import { Profile } from '../../core/models';

const ACCEPTED = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
const MAX_BYTES = 2 * 1024 * 1024;

@Component({
  selector: 'app-edit-profile',
  standalone: true,
  imports: [
    IonContent, IonHeader, IonToolbar, IonButtons, IonBackButton, IonTitle, IonIcon, IonSpinner,
    FormsModule, FieldComponent, ButtonComponent, AvatarComponent,
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
          <button type="button" class="av-btn" (click)="pickFile()" [disabled]="uploading()" aria-label="Change profile picture">
            <app-avatar [url]="avatarUrl()" [name]="shownName()" [size]="78"></app-avatar>
            <span class="av-cam" [class.busy]="uploading()">
              @if (uploading()) {
                <ion-spinner name="crescent"></ion-spinner>
              } @else {
                <ion-icon name="camera-outline" aria-hidden="true"></ion-icon>
              }
            </span>
          </button>
          <div class="who">
            <p class="nm">{{ shownName() }}</p>
            <p class="em">{{ email() }}</p>
            <div class="av-actions">
              <button type="button" class="lnk" (click)="pickFile()" [disabled]="uploading()">Change photo</button>
              @if (avatarUrl()) {
                <button type="button" class="lnk danger" (click)="removePhoto()" [disabled]="uploading()">Remove</button>
              }
            </div>
          </div>
        </div>

        <input #file type="file" accept="image/jpeg,image/png,image/gif,image/webp" hidden (change)="onFile($event)" />

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
    .avatar-row { display: flex; align-items: center; gap: 16px; margin-bottom: 22px; }
    .av-btn { position: relative; width: 78px; height: 78px; flex: none; padding: 0; border: 0; background: transparent; cursor: pointer; }
    .av-btn:disabled { cursor: default; }
    .av-cam { position: absolute; right: -2px; bottom: -2px; width: 28px; height: 28px; border-radius: 50%;
      display: grid; place-items: center; background: var(--ion-color-primary); color: #fff;
      border: 2px solid var(--app-surface); box-shadow: var(--app-shadow); }
    .av-cam ion-icon { font-size: 15px; }
    .av-cam ion-spinner { width: 15px; height: 15px; --color: #fff; }
    .who { min-width: 0; }
    .nm { margin: 0; font-size: 1.05rem; font-weight: 650; letter-spacing: -.01em; }
    .em { margin: 2px 0 0; font-size: .84rem; color: var(--app-muted); }
    .av-actions { display: flex; gap: 14px; margin-top: 8px; }
    .lnk { border: 0; background: transparent; padding: 0; font-size: .84rem; font-weight: 600; cursor: pointer; color: var(--ion-color-primary); }
    .lnk.danger { color: var(--ion-color-danger); }
    .lnk:disabled { opacity: .5; cursor: default; }
    form { display: grid; gap: 15px; }
    .actions { margin-top: 10px; }
  `],
})
export class EditProfilePage implements OnInit {
  private readonly learner = inject(LearnerService);
  private readonly session = inject(SessionStore);
  private readonly toast = inject(ToastService);
  private readonly router = inject(Router);

  private readonly fileInput = viewChild<ElementRef<HTMLInputElement>>('file');

  readonly firstName = signal('');
  readonly lastName = signal('');
  readonly phone = signal('');
  readonly avatarUrl = signal<string | null>(null);

  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly uploading = signal(false);
  readonly submitted = signal(false);

  readonly email = computed(() => this.session.session()?.email || '');
  readonly shownName = computed(() =>
    `${this.firstName()} ${this.lastName()}`.trim() || this.session.session()?.name || 'Learner',
  );

  ngOnInit(): void {
    this.learner.profile().subscribe({
      next: (p: Profile) => {
        this.firstName.set((p.firstName as string) || '');
        this.lastName.set((p.lastName as string) || '');
        this.phone.set((p.phone as string) || '');
        this.avatarUrl.set((p.avatarUrl as string) || null);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  pickFile(): void {
    if (!this.uploading()) {
      this.fileInput()?.nativeElement.click();
    }
  }

  onFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = ''; // let the same file be chosen again after a cancel
    if (!file) {
      return;
    }
    if (!ACCEPTED.includes(file.type)) {
      this.toast.error('Choose a JPEG, PNG, GIF or WebP image.', { title: 'Unsupported file' });
      return;
    }
    if (file.size > MAX_BYTES) {
      this.toast.error('Keep the image under 2 MB.', { title: 'Too large' });
      return;
    }

    this.uploading.set(true);
    this.learner.uploadAvatar(file).subscribe({
      next: (p) => {
        this.uploading.set(false);
        this.avatarUrl.set((p.avatarUrl as string) || null);
        this.toast.success('Profile picture updated.', { title: 'Saved' });
      },
      error: (err: unknown) => {
        this.uploading.set(false);
        const msg = err instanceof ApiError ? err.fieldError('file') || err.message : 'Upload failed.';
        this.toast.error(msg, { title: 'Upload failed' });
      },
    });
  }

  removePhoto(): void {
    this.uploading.set(true);
    this.learner.removeAvatar().subscribe({
      next: () => {
        this.uploading.set(false);
        this.avatarUrl.set(null);
        this.toast.info('Profile picture removed.');
      },
      error: () => {
        this.uploading.set(false);
        this.toast.error('We couldn’t remove the picture. Please try again.');
      },
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
