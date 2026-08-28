import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';
import { LiveTrainingService, type LiveTraining } from '../live-training.service';
import { ApiError } from '../../core/api';

/**
 * Setting up a live training programme.
 *
 * This was a modal on the admin screen. A programme has nineteen fields —
 * attendance thresholds, reminder offsets, QR grace periods, minimum virtual
 * duration — and a dialogue is the wrong shape for that: it cannot be linked
 * to, cannot be come back to, and loses everything typed if it is dismissed by
 * a stray click on the backdrop.
 *
 * As a page it has an address, so "edit this programme" is a link somebody can
 * send, and the browser's own back button does what it looks like it does.
 */
@Component({
  selector: 'app-training-form',
  standalone: true,
  imports: [CommonModule, FormsModule, AdmintopComponent],
  templateUrl: './training-form.component.html',
})
export class TrainingFormComponent implements OnInit, OnDestroy {
  private readonly liveTrainingService = inject(LiveTrainingService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly destroy$ = new Subject<void>();

  isEditMode = false;
  loading = false;
  saving = false;
  error: string | null = null;

  /**
   * The defaults a new programme starts with.
   *
   * The same ones the modal used. They are opinions rather than blanks —
   * 80% attendance, reminders a day and an hour before — because a form of
   * nineteen empty fields is one nobody finishes.
   */
  editingTraining: Partial<LiveTraining> = {
    title: '',
    description: '',
    training_type: 'virtual',
    status: 'draft',
    certification_enabled: false,
    min_attendance_percentage: 80,
    reminder_24h: true,
    reminder_1h: true,
    reminder_15m: false,
    reminder_channels: ['email', 'in_app'],
    qr_grace_period_minutes: 15,
    qr_valid_before_start_minutes: 30,
    min_virtual_duration_minutes: 30,
  };

  // The pickers, as the template names them.
  trainingTypes = ['physical', 'virtual', 'hybrid'];
  trainingStatuses = ['draft', 'published', 'ongoing', 'completed', 'cancelled'];

  /** How a reminder can reach somebody. The template lists these as boxes. */
  reminderChannels = ['email', 'in_app', 'sms', 'whatsapp'];

  /** Whether this programme reminds people over that channel. */
  hasReminderChannel(channel: string): boolean {
    return (this.editingTraining.reminder_channels ?? []).includes(channel);
  }

  toggleReminderChannel(channel: string): void {
    const channels = this.editingTraining.reminder_channels ?? [];
    const at = channels.indexOf(channel);

    // A new array rather than a spliced one: Angular compares by
    // reference, and mutating in place leaves the boxes unchanged.
    this.editingTraining.reminder_channels = at > -1
      ? channels.filter((c) => c !== channel)
      : [...channels, channel];
  }

  ngOnInit(): void {
    const id = this.route.snapshot.paramMap.get('trainingId');

    if (id === null || id === '') {
      return;
    }

    this.isEditMode = true;
    this.loading = true;

    this.liveTrainingService
      .getTraining(id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (training) => {
          this.editingTraining = { ...training };
          this.loading = false;
        },
        error: (failure: ApiError) => {
          this.error = failure.message;
          this.loading = false;
        },
      });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  saveTraining(): void {
    if (this.editingTraining.title === undefined || this.editingTraining.title === '') {
      return;
    }

    this.saving = true;
    this.error = null;

    const saved = this.isEditMode
      ? this.liveTrainingService.updateTraining(
        this.editingTraining.training_id!,
        this.editingTraining,
      )
      : this.liveTrainingService.createTraining(this.editingTraining);

    saved.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.saving = false;
        this.goBack();
      },
      error: (failure: ApiError) => {
        // Shown rather than logged. The modal wrote failures to the console
        // and left the form open with no explanation, which reads as the
        // button not working.
        this.error = failure.message;
        this.saving = false;
      },
    });
  }

  goBack(): void {
    void this.router.navigate(['/live-training/admin']);
  }
}
