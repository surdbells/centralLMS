/**
 * Minimal JWT payload reader.
 *
 * Extracted from `GlobalComponent`, which mixed API endpoint constants with
 * unrelated helpers in one class.
 *
 * **Decoding is not verification.** The payload is base64, not encrypted, and
 * anyone can forge one. Use this only to read expiry or display claims — never
 * to decide what a user may do. The API verifies the signature and re-resolves
 * permissions on every request.
 */
export interface JwtClaims {
  sub?: string;
  email?: string;
  tid?: string;
  roles?: string[];
  exp?: number;
  iat?: number;
  jti?: string;
  [claim: string]: unknown;
}

/**
 * Returns the claims, or `null` for anything that is not a well-formed JWT.
 *
 * The original threw on malformed input, so one bad token in storage took down
 * whatever component read it.
 */
export function decodeJwt(token: string): JwtClaims | null {
  const parts = token.split('.');

  if (parts.length !== 3 || parts[1] === '') {
    return null;
  }

  try {
    const base64 = parts[1].replace(/-/g, '+').replace(/_/g, '/');
    const padded = base64.padEnd(base64.length + ((4 - (base64.length % 4)) % 4), '=');

    const json = decodeURIComponent(
      atob(padded)
        .split('')
        .map((c) => '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2))
        .join(''),
    );

    const claims = JSON.parse(json) as unknown;

    return typeof claims === 'object' && claims !== null ? (claims as JwtClaims) : null;
  } catch {
    return null;
  }
}

/**
 * Whether the token is expired, with a small skew allowance so a clock a few
 * seconds fast does not sign the user out early.
 *
 * A token with no `exp` is treated as expired: a credential that never expires
 * is not something to assume by accident.
 */
export function isJwtExpired(token: string, skewSeconds = 10): boolean {
  const claims = decodeJwt(token);

  if (claims?.exp === undefined || typeof claims.exp !== 'number') {
    return true;
  }

  return claims.exp - skewSeconds <= Math.floor(Date.now() / 1000);
}
