import { Injectable, computed, signal } from '@angular/core';

/**
 * The credentials the two backends need.
 *
 * Legacy wants `token`, `id` and `tenant_id` in every request *body*; the new
 * API wants a bearer token. Both are held here so the auth interceptor can
 * satisfy whichever backend a module currently points at, and callers never
 * have to think about it.
 */
export interface Session {
  /** Bearer token for the new API, or the opaque legacy token. */
  readonly token: string;
  readonly refreshToken?: string;
  readonly userId: string;
  readonly tenantId: string;
  readonly email?: string;
  readonly permissions?: readonly string[];
}

const STORAGE_KEY = 'central.session';

/**
 * Signal-backed session state.
 *
 * Replaces the previous arrangement, where each service read credentials out
 * of `localStorage` itself and pasted them into request bodies by hand.
 */
@Injectable({ providedIn: 'root' })
export class SessionStore {
  // Where the session is persisted. localStorage survives a browser restart
  // ("keep me signed in"); sessionStorage is cleared when the tab/window closes.
  private store: Storage = pickStore();
  private readonly state = signal<Session | null>(readStoredSession());

  readonly session = this.state.asReadonly();
  readonly isAuthenticated = computed(() => this.state() !== null);
  readonly userId = computed(() => this.state()?.userId ?? null);
  readonly tenantId = computed(() => this.state()?.tenantId ?? null);
  readonly permissions = computed(() => this.state()?.permissions ?? []);

  /**
   * Whether the caller holds a permission.
   *
   * Advisory only — it drives what the UI offers. The API is the authority and
   * re-checks every request.
   */
  readonly can = computed(() => {
    const held = new Set(this.state()?.permissions ?? []);

    return (permission: string): boolean => held.has(permission);
  });

  set(session: Session, remember = true): void {
    // Honour the "keep me signed in" choice: localStorage persists across
    // restarts, sessionStorage lasts only for the window. Clear the other store
    // so a stale copy can't resurrect an old session.
    this.store = remember ? localStorage : sessionStorage;
    try { (remember ? sessionStorage : localStorage).removeItem(STORAGE_KEY); } catch { /* */ }
    this.state.set(session);
    this.persist(session);
  }

  /** Replaces the access token after a refresh, leaving identity untouched. */
  updateToken(token: string, refreshToken?: string): void {
    const current = this.state();

    if (current === null) {
      return;
    }

    const next: Session = { ...current, token, refreshToken: refreshToken ?? current.refreshToken };
    this.state.set(next);
    this.persist(next);
  }

  clear(): void {
    this.state.set(null);

    // Remove from both stores — we don't track which one held it here.
    try { localStorage.removeItem(STORAGE_KEY); } catch { /* */ }
    try { sessionStorage.removeItem(STORAGE_KEY); } catch { /* */ }
  }

  private persist(session: Session): void {
    try {
      this.store.setItem(STORAGE_KEY, JSON.stringify(session));
    } catch {
      // Storage can be unavailable (private mode, blocked cookies). Losing the
      // persisted copy is acceptable; the in-memory signal is the truth.
    }
  }
}

/** The store that currently holds a session (localStorage wins), else localStorage. */
function pickStore(): Storage {
  try {
    if (sessionStorage.getItem(STORAGE_KEY) && !localStorage.getItem(STORAGE_KEY)) {
      return sessionStorage;
    }
  } catch { /* */ }
  return localStorage;
}

function readStoredSession(): Session | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY) ?? sessionStorage.getItem(STORAGE_KEY);

    if (!raw) {
      return null;
    }

    const parsed = JSON.parse(raw) as Partial<Session>;

    // A partially-written or hand-edited entry must not produce a session that
    // looks valid and then fails on every request.
    if (typeof parsed.token !== 'string' || typeof parsed.userId !== 'string') {
      return null;
    }

    return {
      token: parsed.token,
      refreshToken: parsed.refreshToken,
      userId: parsed.userId,
      tenantId: typeof parsed.tenantId === 'string' ? parsed.tenantId : '',
      email: parsed.email,
      permissions: Array.isArray(parsed.permissions) ? parsed.permissions : [],
    };
  } catch {
    return null;
  }
}
