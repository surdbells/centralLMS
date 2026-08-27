import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { ApiClient, Endpoints } from '../core/api';

/**
 * What a registration link says about itself.
 *
 * Deliberately thin. Somebody following a link should be told whose front
 * door they are at, and nothing else about the organisation is theirs to see
 * until they have an account in it.
 */
export interface RegistrationOpening {
  tenantId: string;
  organisation: string;
  /** Off unless the organisation has turned it on. */
  isOpen: boolean;
  /** Empty means any address may register. */
  domains: string[];
}

export interface RegistrationInput {
  firstName: string;
  lastName: string;
  email: string;
  phone?: string;
}

/**
 * Registering yourself, in a named organisation.
 *
 * The organisation is part of the address because an account has to belong to
 * one. The legacy posted to a single `authentication/register` that asked for
 * no organisation and created users belonging to none.
 *
 * No password is sent. Registering asks for a link, and the password is chosen
 * through it — the same path somebody invited by an administrator walks.
 */
@Injectable({ providedIn: 'root' })
export class RegistrationService {
  private readonly api = inject(ApiClient);

  opening(tenantId: string): Observable<RegistrationOpening> {
    return this.api.get<RegistrationOpening>(Endpoints.registration.show(tenantId));
  }

  /**
   * Registers, and asks for the link that finishes it.
   *
   * Completes the same way for an address that already has an account here:
   * the server answers 204 either way on purpose, because "that email is
   * taken" is a way to ask an organisation who works there.
   */
  register(tenantId: string, input: RegistrationInput): Observable<void> {
    return this.api
      .post<unknown>(Endpoints.registration.register(tenantId), input)
      .pipe(map(() => undefined));
  }
}
