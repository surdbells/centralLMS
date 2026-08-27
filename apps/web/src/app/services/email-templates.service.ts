import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { ApiClient, Endpoints } from '../core/api';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface Wording {
  title: string;
  body: string;
}

/**
 * One kind of message, and what this organisation has made of it.
 *
 * `default` is the platform's wording, shown beside the editor so an author
 * can see what they are replacing. `usesDefault` is true until they replace it.
 */
export interface EmailTemplate {
  type: string;
  label: string;
  usesDefault: boolean;
  subject: string | null;
  body: string | null;
  enabled: boolean;
  updatedAt?: string | null;
  default: Wording;
  /** Only on the single-template read: what this would actually send. */
  preview?: Wording;
}

export interface EmailTemplateList {
  templates: EmailTemplate[];
  /** What a template may refer to, so the editor can offer them. */
  placeholders: string[];
}

export interface EmailTemplateInput {
  subject?: string;
  body?: string;
  enabled?: boolean;
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * What an organisation's own mail says.
 *
 * The platform default is one set of English sentences for every tenant. A
 * company with its own tone, its own signature or its own language overrides
 * the ones it cares about; the rest keep the default, so nothing has to be
 * copied into every organisation and then kept in step.
 *
 * Only the kinds of message that actually go out by email are offered.
 */
@Injectable({ providedIn: 'root' })
export class EmailTemplatesService {
  private readonly api = inject(ApiClient);

  list(): Observable<EmailTemplateList> {
    return this.api.get<EmailTemplateList>(Endpoints.emailTemplates.list);
  }

  /** One, with a preview of what it would send. */
  show(type: string): Observable<EmailTemplate> {
    return this.api.get<EmailTemplate>(Endpoints.emailTemplates.show(type));
  }

  save(type: string, input: EmailTemplateInput): Observable<EmailTemplate> {
    return this.api.put<EmailTemplate>(Endpoints.emailTemplates.save(type), input);
  }

  /** Removes the override, so the platform wording applies again. */
  reset(type: string): Observable<void> {
    return this.api.delete<void>(Endpoints.emailTemplates.delete(type)).pipe(map(() => undefined));
  }
}
