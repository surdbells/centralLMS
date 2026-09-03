import { Injectable, computed, signal } from '@angular/core';

/**
 * The operator's session.
 *
 * A separate deployment from the tenant application, so its own key — a person
 * signed into the console is not signed into any organisation, and the two
 * should never be mistaken for one another. Only the bearer token and who it
 * belongs to are kept; the operator is a super admin by the time a session
 * exists here, checked by the API on the first call and on every one after.
 */
export interface OperatorSession {
  readonly token: string;
  readonly refreshToken?: string;
  readonly userId: string;
  readonly email: string;
  readonly fullName: string;
}

const STORAGE_KEY = 'central.operator';

@Injectable({ providedIn: 'root' })
export class SessionStore {
  private readonly state = signal<OperatorSession | null>(readStored());

  readonly session = this.state.asReadonly();
  readonly isAuthenticated = computed(() => this.state() !== null);
  readonly operator = computed(() => this.state());

  set(session: OperatorSession): void {
    this.state.set(session);
    this.persist(session);
  }

  updateToken(token: string, refreshToken?: string): void {
    const current = this.state();
    if (current === null) {
      return;
    }

    const next: OperatorSession = {
      ...current,
      token,
      refreshToken: refreshToken ?? current.refreshToken,
    };
    this.state.set(next);
    this.persist(next);
  }

  clear(): void {
    this.state.set(null);
    try {
      localStorage.removeItem(STORAGE_KEY);
    } catch {
      // Storage can be unavailable (private mode). The in-memory signal is the
      // truth; losing the persisted copy is acceptable.
    }
  }

  private persist(session: OperatorSession): void {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(session));
    } catch {
      // As above — never let a storage failure break a sign-in.
    }
  }
}

function readStored(): OperatorSession | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) {
      return null;
    }

    const parsed = JSON.parse(raw) as Partial<OperatorSession>;

    // A hand-edited or half-written entry must not look valid and then fail on
    // every request.
    if (typeof parsed.token !== 'string' || typeof parsed.userId !== 'string') {
      return null;
    }

    return {
      token: parsed.token,
      refreshToken: parsed.refreshToken,
      userId: parsed.userId,
      email: typeof parsed.email === 'string' ? parsed.email : '',
      fullName: typeof parsed.fullName === 'string' ? parsed.fullName : '',
    };
  } catch {
    return null;
  }
}
