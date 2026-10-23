import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiClient, Endpoints } from '../core/api';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

/** The kinds of value a custom field can hold. One form control each. */
export type CustomFieldType = 'text' | 'number' | 'date' | 'dropdown' | 'boolean';

/** A value a person can hold for a custom field. */
export type CustomFieldValue = string | number | boolean;

/**
 * One tenant-defined profile field — the definition, not an answer.
 *
 * `key` is the stable machine name a person's value map is keyed by; `label`
 * is what people see and can be renamed freely.
 */
export interface CustomFieldDefinition {
  id: string;
  key: string;
  label: string;
  type: CustomFieldType;
  /** Present only for a dropdown. */
  options?: string[];
  required: boolean;
  sortOrder: number;
  isActive: boolean;
  createdAt?: string | null;
  updatedAt?: string | null;
}

export interface CustomFieldInput {
  label: string;
  type: CustomFieldType;
  options?: string[];
  required?: boolean;
  sortOrder?: number;
  isActive?: boolean;
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * A tenant's custom profile fields.
 *
 * Reading the definitions needs no permission — a profile form has to know
 * what fields to render — so {@link list} works for any signed-in user and
 * returns only the active fields for a non-administrator. Creating, editing
 * and removing them rides on `user.manage`, enforced by the API.
 */
@Injectable({ providedIn: 'root' })
export class CustomFieldsService {
  private readonly api = inject(ApiClient);

  /** Every field an administrator may manage, or the active ones for others. */
  list(): Observable<CustomFieldDefinition[]> {
    return this.api.get<CustomFieldDefinition[]>(Endpoints.customFields.list);
  }

  create(input: CustomFieldInput): Observable<CustomFieldDefinition> {
    return this.api.post<CustomFieldDefinition>(Endpoints.customFields.create, input);
  }

  update(id: string, input: CustomFieldInput): Observable<CustomFieldDefinition> {
    return this.api.put<CustomFieldDefinition>(Endpoints.customFields.update(id), input);
  }

  delete(id: string): Observable<void> {
    return this.api.delete<void>(Endpoints.customFields.delete(id));
  }
}
