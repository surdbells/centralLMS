import { Pipe, PipeTransform, inject } from '@angular/core';
import { I18nService } from './i18n.service';

/**
 * `{{ 'some.key' | translate }}` in a template. Impure so it re-evaluates when
 * the language switches (the service swaps the dictionary underneath it).
 * Import it into a standalone component's `imports` to use it.
 */
@Pipe({ name: 'translate', standalone: true, pure: false })
export class TranslatePipe implements PipeTransform {
  private readonly i18n = inject(I18nService);
  transform(key: string, params?: Record<string, unknown>): string {
    return this.i18n.t(key, params);
  }
}
