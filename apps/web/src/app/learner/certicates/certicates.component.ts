import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { TuiIcon, TuiLoader } from '@taiga-ui/core';
import { HotToastService } from '@ngneat/hot-toast';
import { Subject, takeUntil } from 'rxjs';
import { AsideComponent } from '../../partials/aside/aside.component';
import { SideComponent } from '../../partials/side/side.component';
import { TopComponent } from '../../partials/top/top.component';
import { CertificatesService, type Certificate } from '../../services/certificates.service';
import { ApiError } from '../../core/api';
import { TranslatePipe } from '../../translate.pipe';

/**
 * The certificates somebody has earned.
 *
 * It used to show "No Certificates Earned Yet" and nothing else — not because
 * the person had none, but because the screen never asked. There was no
 * request in it at all: it decoded `atob(sessionStorage.SESSION)`, copied the
 * id and token into a `get_single` object, and then never used them. Everybody
 * saw the empty state, including anybody who had just earned one.
 *
 * The empty state is kept, because it is a good one. It is now shown when the
 * answer is empty rather than instead of asking.
 */
@Component({
  selector: 'app-certicates',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    AsideComponent,
    SideComponent,
    TopComponent,
    TuiIcon,
    TuiLoader,
    TranslatePipe,
  ],
  templateUrl: './certicates.component.html',
  styleUrl: './certicates.component.css',
})
export class CerticatesComponent implements OnInit, OnDestroy {
  private readonly certificates = inject(CertificatesService);
  private readonly router = inject(Router);
  private readonly toast = inject(HotToastService);
  private readonly destroy$ = new Subject<void>();

  ui_controls = {
    is_loading: false,
  };

  earned: Certificate[] = [];
  error: string | null = null;

  ngOnInit(): void {
    this.load();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  load(): void {
    this.ui_controls.is_loading = true;
    this.error = null;

    this.certificates
      .mine()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (earned) => {
          this.earned = earned;
          this.ui_controls.is_loading = false;
        },
        error: (failure: ApiError) => {
          // The spinner stops either way, and the reason is on screen. An
          // unexplained empty list is what this screen used to be.
          this.ui_controls.is_loading = false;
          this.error = failure.message;
        },
      });
  }

  /**
   * Opens one for printing.
   *
   * The server returns it laid out as HTML rather than as a PDF — building a
   * PDF costs seconds of CPU, and doing that on a web request is how a
   * reporting endpoint takes a server down. "Save as PDF" lives in the
   * browser's own print dialogue.
   */
  download(certificate: Certificate): void {
    this.certificates
      .openForPrinting(certificate.id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        error: (failure: ApiError) => this.error_notification(failure.message),
      });
  }

  /** Whether a certificate is still worth anything, in the template's words. */
  statusClass(certificate: Certificate): string {
    if (certificate.status === 'revoked') {
      return 'bg-danger-subtle text-danger';
    }

    if (certificate.status === 'expired') {
      return 'bg-warning-subtle text-warning';
    }

    return 'bg-success-subtle text-success';
  }

  goBack(): void {
    void this.router.navigate(['/account']);
  }

  to_dashboard(): void {
    void this.router.navigate(['/learning']);
  }

  error_notification(message: string): void {
    this.toast.error(message);
  }

  success_notification(message: string): void {
    this.toast.success(message);
  }
}
