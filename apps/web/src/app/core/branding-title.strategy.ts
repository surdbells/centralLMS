import { Injectable, inject } from '@angular/core';
import { RouterStateSnapshot, TitleStrategy } from '@angular/router';
import { BrandingService } from './branding.service';

/**
 * Composes the browser-tab title from the route's own title and the live
 * organisation name, instead of a hardcoded product name.
 *
 * The default strategy writes the route `title` straight to `document.title`.
 * Here the route title is handed to {@see BrandingService}, which combines it
 * with the organisation name — "Courses · Midwestern Learning" — and
 * re-composes it if the brand loads after the page has already shown. A route
 * with no title leaves just the organisation name.
 */
@Injectable()
export class BrandingTitleStrategy extends TitleStrategy {
  private readonly branding = inject(BrandingService);

  override updateTitle(snapshot: RouterStateSnapshot): void {
    this.branding.pageTitle.set(this.buildTitle(snapshot) ?? null);
  }
}
