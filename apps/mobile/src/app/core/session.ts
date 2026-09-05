import { Injectable, computed, signal } from '@angular/core';

export interface Session {
  readonly token: string;
  readonly refreshToken?: string;
  readonly userId: string;
  readonly tenantId: string;
  readonly email?: string;
  readonly name?: string;
}

const STORAGE_KEY = 'central.mobile.session';

/** Signal-backed session, persisted so a reopen of the app stays signed in. */
@Injectable({ providedIn: 'root' })
export class SessionStore {
  private readonly state = signal<Session | null>(read());

  readonly session = this.state.asReadonly();
  readonly isAuthenticated = computed(() => this.state() !== null);

  token(): string | null {
    return this.state()?.token ?? null;
  }

  set(session: Session): void {
    this.state.set(session);
    persist(session);
  }

  updateToken(token: string, refreshToken?: string): void {
    const current = this.state();
    if (!current) {
      return;
    }
    const next: Session = { ...current, token, refreshToken: refreshToken ?? current.refreshToken };
    this.state.set(next);
    persist(next);
  }

  clear(): void {
    this.state.set(null);
    try {
      localStorage.removeItem(STORAGE_KEY);
    } catch {
      /* storage may be unavailable; the in-memory signal is the truth */
    }
  }
}

function read(): Session | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) {
      return null;
    }
    const parsed = JSON.parse(raw) as Partial<Session>;
    if (typeof parsed.token !== 'string' || typeof parsed.userId !== 'string') {
      return null;
    }
    return {
      token: parsed.token,
      refreshToken: parsed.refreshToken,
      userId: parsed.userId,
      tenantId: typeof parsed.tenantId === 'string' ? parsed.tenantId : '',
      email: parsed.email,
      name: parsed.name,
    };
  } catch {
    return null;
  }
}

function persist(session: Session): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(session));
  } catch {
    /* never let a storage failure break sign-in */
  }
}
