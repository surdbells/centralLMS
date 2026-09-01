import { Injectable, inject } from '@angular/core';
import { Observable, map, tap } from 'rxjs';
import { ApiClient, Endpoints, type Paged } from '../core/api';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export type CertificateStatus = 'active' | 'revoked' | 'expired' | 'superseded';
export type CertificateSource = 'course' | 'training' | 'assessment' | 'manual';
export type Orientation = 'landscape' | 'portrait';
export type PageSize = 'a4' | 'letter';

export interface Certificate {
  id: string;
  number: string;
  verificationCode: string;
  source: CertificateSource;
  sourceLabel: string;
  courseId: string | null;
  trainingId: string | null;
  assessmentId: string | null;
  userId: string;
  learnerName: string | null;
  learnerEmail: string | null;
  title: string;
  score: number | null;
  percentage: number | null;
  grade: string | null;
  /** Worked out from the dates at read time, not a stored column that drifts. */
  status: CertificateStatus;
  statusLabel: string;
  isValid: boolean;
  pdfUrl: string | null;
  issuedAt: string | null;
  validFrom: string | null;
  validUntil: string | null;
  daysRemaining: number | null;
  revokedAt: string | null;
  revocationReason: string | null;
  downloadCount: number;
  verificationCount: number;
  customData: Record<string, unknown>;
}

/**
 * A certificate laid out and ready to print.
 *
 * HTML rather than a PDF, and deliberately: building one costs seconds of CPU,
 * and doing that on a web request is how a report endpoint takes a server down.
 * The browser turns this into a document through its own print dialogue, which
 * is also where "Save as PDF" lives.
 */
export interface RenderedCertificate {
  certificateId: string;
  orientation: Orientation;
  pageSize: PageSize;
  styles: string;
  html: string;
}

export interface CertificateFilters {
  search?: string;
  userId?: string;
  courseId?: string;
  status?: CertificateStatus;
  page?: number;
  perPage?: number;
}

/**
 * What a public verification returns.
 *
 * Deliberately thinner than a {@link Certificate}: a stranger checking a
 * certificate is told it is genuine and who it names, but not the score or the
 * holder's email. A miss comes back with the same shape and `isValid: false`
 * rather than an error, so the caller reads `isValid`, not an HTTP status.
 */
export interface CertificateVerification {
  found: boolean;
  isValid: boolean;
  message: string;
  number: string | null;
  learnerName: string | null;
  title: string | null;
  source: CertificateSource | null;
  status: CertificateStatus | null;
  statusLabel: string | null;
  issuedAt: string | null;
  validUntil: string | null;
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * The certificate register.
 *
 * A learner sees their own; issuing, revoking and reinstating need
 * `certificate.issue`. The legacy took `user_id` from the request body on
 * every one of these and checked nothing against it.
 */
@Injectable({ providedIn: 'root' })
export class CertificatesService {
  private readonly api = inject(ApiClient);

  list(filters: CertificateFilters = {}): Observable<Paged<Certificate>> {
    return this.api.get<Paged<Certificate>>(Endpoints.certificates.list, { ...filters });
  }

  /**
   * The signed-in person's own certificates.
   *
   * A separate endpoint from `list`, which needs `certificate.manage` —
   * a learner has no business reading the whole tenant's certificates,
   * and filtering the full list by user id on the client would mean
   * fetching them all to hide most of them.
   */
  mine(): Observable<Certificate[]> {
    return this.api
      .getPaged<Certificate>(Endpoints.me.certificates, { perPage: 100 })
      .pipe(map((page) => page.items));
  }

  show(id: string): Observable<Certificate> {
    return this.api.get<Certificate>(Endpoints.certificates.show(id));
  }

  render(id: string): Observable<RenderedCertificate> {
    return this.api.get<RenderedCertificate>(Endpoints.certificates.render(id));
  }

  /**
   * Opens the certificate in a window of its own and offers it for printing.
   *
   * The dashboard's download button was an empty method with the real thing
   * commented out beneath it, so it did nothing at all.
   *
   * The window is opened before the request goes out, because a browser only
   * treats `window.open` as wanted when it happens during the click that asked
   * for it — open it in the subscribe callback and the popup blocker eats it.
   */
  openForPrinting(id: string): Observable<RenderedCertificate> {
    const window_ = window.open('', '_blank');

    if (window_ !== null) {
      window_.document.write(this.holdingPage());
      window_.document.close();
    }

    return this.render(id).pipe(
      tap((rendered) => {
        if (window_ === null) {
          // Blocked. Saying so beats a button that looks broken.
          alert('Allow pop-ups for this site to open your certificate.');

          return;
        }

        window_.document.open();
        window_.document.write(this.page(rendered));
        window_.document.close();
      }),
    );
  }

  /**
   * What the new window shows while the certificate is being fetched.
   *
   * Something has to be there: an empty tab that fills in a moment later reads
   * as a broken link.
   */
  private holdingPage(): string {
    return `<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Certificate</title></head>
<body style="font:16px system-ui,sans-serif;display:grid;place-items:center;height:100vh;margin:0">
  <p>Preparing your certificate…</p>
</body>
</html>`;
  }

  /**
   * The certificate as a printable page.
   *
   * `@page` carries the size and orientation the template was designed at, so
   * the print dialogue opens on the right paper rather than on whatever the
   * browser last used and cropping the border off.
   */
  private page(rendered: RenderedCertificate): string {
    return `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Certificate</title>
  <style>
    @page { size: ${rendered.pageSize} ${rendered.orientation}; margin: 0; }
    html, body { margin: 0; padding: 0; }
    @media screen {
      body { background: #525659; display: grid; place-items: center; min-height: 100vh; padding: 24px; }
      .sheet { background: #fff; box-shadow: 0 2px 16px rgba(0,0,0,.4); }
    }
    @media print {
      body { background: none; padding: 0; }
      .sheet { box-shadow: none; }
    }
${rendered.styles}
  </style>
</head>
<body>
  <div class="sheet">${rendered.html}</div>
  <script>
    // After the layout has settled, so the dialogue previews the finished
    // page rather than a half-styled one.
    window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });
  </script>
</body>
</html>`;
  }

  /**
   * Confirms a certificate is genuine from its long hash. Unauthenticated: a
   * stranger following a QR code or verification link checks it.
   */
  verify(hash: string): Observable<Certificate> {
    return this.api.get<Certificate>(Endpoints.certificates.verify(hash));
  }

  /**
   * Confirms a certificate from its short verification code.
   *
   * The typed code is not the hash the link carries, so it goes to the code
   * endpoint rather than {@link verify}, which only recognises a hash.
   */
  verifyByCode(code: string): Observable<CertificateVerification> {
    return this.api.post<CertificateVerification>(Endpoints.certificates.verifyByCode, { code });
  }

  issue(input: {
    source: CertificateSource;
    sourceId: string;
    userId: string;
    templateId?: string;
  }): Observable<Certificate> {
    return this.api.post<Certificate>(Endpoints.certificates.issue, input);
  }

  revoke(id: string, reason: string): Observable<Certificate> {
    return this.api.post<Certificate>(Endpoints.certificates.revoke(id), { reason });
  }

  reinstate(id: string): Observable<Certificate> {
    return this.api.post<Certificate>(Endpoints.certificates.reinstate(id), {});
  }

  /** Who did what to it, and when. */
  history(id: string): Observable<Array<{ action: string; at: string; actorName: string | null }>> {
    return this.api
      .get<Array<{ action: string; at: string; actorName: string | null }>>(
        Endpoints.certificates.history(id),
      )
      .pipe(map((entries) => entries ?? []));
  }
}
