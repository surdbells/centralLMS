import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { TopComponent } from '../../partials/top/top.component';
import { TuiIcon, TuiLoader } from '@taiga-ui/core';
import { Router } from '@angular/router';
import { HotToastService } from '@ngneat/hot-toast';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject, takeUntil } from 'rxjs';
import { ApiError } from '../../core/api';
import { MyProfileService } from '../../services/my-profile.service';
import type { User } from '../../services/users.service';

/**
 * Your own name, telephone number and picture.
 *
 * The legacy pair behind this screen — `get-profile` and `update-profile` —
 * both took a `user_id` from the request body and neither checked it against
 * the token, so this form could read and rewrite anybody's profile by sending
 * a different number. The replacement takes no id at all: `/me/profile` acts
 * on whoever the token says is asking, and there is nothing to tamper with.
 *
 * **The avatar was a data URI in a text column.** `select_avatar` read the
 * chosen file with `FileReader.readAsDataURL` and posted the base64 string as
 * the profile's `avatar` field, so a two-megabyte photograph became a
 * three-megabyte string inside a JSON body, stored in the row and sent back
 * with every read of it. It is an upload now, and the picture is served from
 * the media route like any other file.
 *
 * The property names the template binds to — `user_single.first_name` and the
 * rest — are unchanged. The API speaks camelCase; the translation is in
 * `adapt`, at the edge, so the markup did not have to move.
 */
@Component({
  selector: 'app-user-profile',
  standalone: true,
  imports: [
    TopComponent,
    CommonModule,
    FormsModule,
    TuiIcon,
    TuiLoader,
  ],
  templateUrl: './user-profile.component.html',
  styleUrl: './user-profile.component.css',
})
export class UserProfileComponent implements OnInit, OnDestroy {
  private readonly router = inject(Router);
  private readonly toast = inject(HotToastService);
  private readonly profile = inject(MyProfileService);

  private readonly destroy$ = new Subject<void>();

  ui_controls = {
    is_loading: false,
  };

  /** What the form shows, in the names the template already uses. */
  user_single = {
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    avatar: '',
  };

  ngOnInit(): void {
    this.get_data();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  goBack(): void {
    void this.router.navigate(['/account']);
  }

  error_notification(message: string): void {
    this.toast.error(message);
  }

  success_notification(message: string): void {
    this.toast.success(message);
  }

  get_data(): void {
    this.ui_controls.is_loading = true;

    this.profile
      .show()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (user) => {
          this.user_single = adapt(user);
          this.ui_controls.is_loading = false;
        },
        error: (error: ApiError) => {
          this.ui_controls.is_loading = false;
          this.error_notification(error.message);
        },
      });
  }

  update_changes(): void {
    this.ui_controls.is_loading = true;

    this.profile
      .update({
        firstName: this.user_single.first_name,
        lastName: this.user_single.last_name,
        // The email address is not sent. Changing who an account belongs to
        // is an administrator's act, and the legacy let this form do it.
        phone: this.user_single.phone === '' ? null : this.user_single.phone,
      })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (user) => {
          this.user_single = adapt(user);
          this.ui_controls.is_loading = false;
          this.success_notification('Your profile has been saved.');
        },
        error: (error: ApiError) => {
          this.ui_controls.is_loading = false;
          this.error_notification(error.message);
        },
      });
  }

  /**
   * Uploads the chosen picture straight away.
   *
   * Rather than holding it as base64 until somebody presses Save: a picture
   * is either chosen or it is not, and the old arrangement meant a photograph
   * travelling twice — once into the form's state and again into the body of
   * the profile update.
   */
  select_avatar(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (file === undefined) {
      return;
    }

    this.ui_controls.is_loading = true;

    this.profile
      .uploadAvatar(file)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (user) => {
          this.user_single = adapt(user);
          this.ui_controls.is_loading = false;
          this.success_notification('Your picture has been updated.');
        },
        error: (error: ApiError) => {
          this.ui_controls.is_loading = false;
          this.error_notification(error.message);
        },
      });
  }
}

/** The API's record, in the shape the template binds to. */
function adapt(user: User): {
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  avatar: string;
} {
  return {
    first_name: user.firstName,
    last_name: user.lastName,
    email: user.email,
    phone: user.phone ?? '',
    avatar: user.avatarUrl ?? '',
  };
}
