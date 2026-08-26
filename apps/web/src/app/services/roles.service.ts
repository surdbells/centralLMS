import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiClient, Endpoints } from '../core/api';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface Role {
  id: string;
  slug: string;
  name: string;
  description: string | null;
  /** Built-ins are defined in code and are the same in every organisation. */
  isSystem: boolean;
  editable: boolean;
  permissions: string[];
  /** How many people in this organisation hold it. Counted, never stored. */
  holders: number;
}

export interface PermissionOption {
  value: string;
  label: string;
  description: string;
}

export interface PermissionGroup {
  module: string;
  label: string;
  permissions: PermissionOption[];
}

export interface RoleInput {
  name: string;
  description?: string | null;
  /** The whole set. Saving replaces what the role may do. */
  permissions: string[];
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * Roles, and what each of them may do.
 *
 * There is no `assignRole` here, and that is the point: a person's roles are
 * set as a set through {@link UsersService.update}. The legacy had
 * `assign-role`, `remove-role` and the user form all writing `user_roles`, and
 * the three disagreed about whether somebody could be left with no role at
 * all — so the answer depended on which screen you used.
 *
 * The permission list comes from the server, not from a copy kept here. The
 * legacy shipped a `permissions` table of 61 rows that the authorisation code
 * did not read: a box could be ticked and mean nothing.
 */
@Injectable({ providedIn: 'root' })
export class RolesService {
  private readonly api = inject(ApiClient);

  /** The built-ins plus this organisation's own, with holder counts. */
  list(): Observable<Role[]> {
    return this.api.get<Role[]>(Endpoints.roles.list);
  }

  show(id: string): Observable<Role> {
    return this.api.get<Role>(Endpoints.roles.show(id));
  }

  /** Everything a role may grant, grouped by module for the editor. */
  permissions(): Observable<PermissionGroup[]> {
    return this.api.get<PermissionGroup[]>(Endpoints.roles.permissions);
  }

  create(input: RoleInput): Observable<Role> {
    return this.api.post<Role>(Endpoints.roles.create, input);
  }

  /**
   * The permissions replace, they do not add.
   *
   * Omit a field to leave it as it was; send `permissions` and the role ends
   * up with exactly those.
   */
  update(id: string, input: Partial<RoleInput>): Observable<Role> {
    return this.api.put<Role>(Endpoints.roles.update(id), input);
  }

  /** Refused while anybody still holds it, and for the built-ins. */
  delete(id: string): Observable<void> {
    return this.api.delete<void>(Endpoints.roles.delete(id));
  }
}
