import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { ApiClient, Endpoints } from '../core/api';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export type SettingValue = string | number | boolean;

export type SettingCategory = 'general' | 'branding' | 'gamification' | 'security';

/**
 * One setting, as the catalogue describes it.
 *
 * `honouredBy` is the useful field: it says where the setting actually takes
 * effect. The legacy declared five `password_*` settings that nothing read,
 * which is worse than not offering them.
 */
/** How the endpoint describes a category. */
export interface SettingCategoryInfo {
  category: SettingCategory;
  label: string;
  description: string;
}

export interface SettingDefinition {
  key: string;
  category: SettingCategory;
  /** The six the API declares — see SettingType on the API side. */
  type: 'text' | 'integer' | 'boolean' | 'colour' | 'url' | 'choice';
  label: string;
  help: string | null;
  default: SettingValue;
  choices: string[] | null;
  honouredBy: string | null;
  /** Whether this organisation has set it, as opposed to taking the default. */
  overridden: boolean;
  value: SettingValue;
}

/** Branding and the password rules, readable before anybody has signed in. */
export interface PublicSettings {
  [key: string]: SettingValue;
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * The organisation's settings.
 *
 * Reading needs `settings.manage`, except {@link publicSettings}, which is
 * what the sign-in screen reads to know the logo and the password rules.
 */
@Injectable({ providedIn: 'root' })
export class SettingsService {
  private readonly api = inject(ApiClient);

  /** Every declared setting with its resolved value. */
  show(): Observable<Record<string, SettingValue>> {
    return this.api.get<Record<string, SettingValue>>(Endpoints.settings.show);
  }

  /**
   * Every setting with its label, help and where it is honoured.
   *
   * The endpoint answers `{categories, settings}` — the categories are for
   * a screen that wants to describe its own tabs, and this one builds them
   * from the settings it actually got. Unwrapped here so no caller has to
   * know the shape.
   */
  catalogue(): Observable<SettingDefinition[]> {
    return this.api
      .get<{ categories: SettingCategoryInfo[]; settings: SettingDefinition[] }>(
        Endpoints.settings.catalogue,
      )
      .pipe(map((result) => result.settings));
  }

  /**
   * Saves some of them.
   *
   * Only the keys sent are touched, so a screen showing one setting does not
   * write back the twenty it never displayed.
   */
  update(values: Record<string, SettingValue>): Observable<Record<string, SettingValue>> {
    return this.api.put<Record<string, SettingValue>>(Endpoints.settings.update, values);
  }

  /** Puts a whole category back to its defaults. */
  reset(category: SettingCategory): Observable<Record<string, SettingValue>> {
    return this.api.post<Record<string, SettingValue>>(Endpoints.settings.reset(category), {});
  }

  publicSettings(): Observable<PublicSettings> {
    return this.api.get<PublicSettings>(Endpoints.settings.public);
  }
}
