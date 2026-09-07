import { Injectable, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';

/**
 * Runtime translation for the mobile app — the same lightweight approach the
 * web app uses: a flat `{ key: string }` dictionary loaded per language from
 * `assets/i18n/<lang>.json`, a `t(key)` lookup with `{{placeholder}}` support,
 * and a left-to-right layout for every language (Arabic is translated, not
 * mirrored). The choice persists across launches.
 *
 * Templates read it through the impure `translate` pipe; TypeScript reads it
 * through `t()`. Adding a language is a new JSON file plus a `LangCode` case.
 */
export type LangCode = 'en' | 'fr' | 'ar';
type Dict = Record<string, string>;

const STORAGE_KEY = 'central.mobile.lang';

@Injectable({ providedIn: 'root' })
export class I18nService {
  private readonly http = inject(HttpClient);

  /** The active language — a signal so screens can react to a switch. */
  readonly lang = signal<LangCode>('en');
  readonly isRtl = signal(false);

  private dict: Dict = {};

  /** Loaded once at startup (APP_INITIALIZER) with the remembered choice. */
  async init(): Promise<void> {
    let saved: LangCode = 'en';
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (raw === 'en' || raw === 'fr' || raw === 'ar') { saved = raw; }
    } catch { /* storage may be unavailable */ }
    await this.use(saved);
  }

  /** Switch language: load its dictionary, persist, and flip document direction. */
  async use(lang: LangCode): Promise<void> {
    try {
      const dict = await firstValueFrom(this.http.get<Dict>(`assets/i18n/${lang}.json`));
      this.dict = dict ?? {};
      this.lang.set(lang);
      this.isRtl.set(false);
      try { localStorage.setItem(STORAGE_KEY, lang); } catch { /* */ }
      const html = document.documentElement;
      html.setAttribute('lang', lang);
      // Layout stays left-to-right for all languages (Arabic is translated but
      // not mirrored), per product decision.
      html.setAttribute('dir', 'ltr');
    } catch (err) {
      // A missing/broken file must never brick the app — fall back to English.
      // eslint-disable-next-line no-console
      console.error('[i18n] failed to load', lang, err);
      if (lang !== 'en') { await this.use('en'); }
    }
  }

  /** Translate a key, filling `{{placeholders}}`. Falls back to the key itself. */
  t(key: string, params?: Record<string, unknown>): string {
    const raw = this.dict[key] ?? key;
    if (!params) { return raw; }
    return raw.replace(/\{\{\s*(\w+)\s*\}\}/g, (_, m) => (params[m] != null ? String(params[m]) : ''));
  }
}
