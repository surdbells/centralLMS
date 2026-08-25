import { decodeJwt, isJwtExpired } from './jwt';

/**
 * Builds an unsigned token with the given payload — enough for a decoder.
 *
 * The payload is UTF-8 encoded before base64, which is what a real issuer
 * does. Passing the string straight to `btoa` would encode it as Latin-1 and
 * silently mangle any non-ASCII claim.
 */
function tokenWith(payload: Record<string, unknown>): string {
  const encode = (value: object): string => {
    const utf8 = new TextEncoder().encode(JSON.stringify(value));
    const binary = Array.from(utf8, (byte) => String.fromCharCode(byte)).join('');

    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  };

  return `${encode({ alg: 'HS256', typ: 'JWT' })}.${encode(payload)}.signature`;
}

describe('decodeJwt', () => {
  it('reads the claims', () => {
    const claims = decodeJwt(tokenWith({ sub: 'user-1', tid: 'tenant-9', roles: ['instructor'] }));

    expect(claims?.sub).toBe('user-1');
    expect(claims?.tid).toBe('tenant-9');
    expect(claims?.roles).toEqual(['instructor']);
  });

  it('handles base64url payloads that need padding', () => {
    // Payload lengths not divisible by 4 broke naive atob() calls.
    const claims = decodeJwt(tokenWith({ sub: 'abc' }));

    expect(claims?.sub).toBe('abc');
  });

  it('decodes non-ASCII claims', () => {
    const claims = decodeJwt(tokenWith({ email: 'aïsha@example.com', name: 'Ìbàdàn' }));

    expect(claims?.['name']).toBe('Ìbàdàn');
  });

  it('returns null for malformed input rather than throwing', () => {
    // The original threw, so one bad token in localStorage took down whatever
    // component happened to read it.
    for (const bad of ['', 'not-a-jwt', 'a.b', 'a.b.c.d', 'a..c', 'a.!!!.c']) {
      expect(decodeJwt(bad))
        .withContext(`input: ${bad}`)
        .toBeNull();
    }
  });
});

describe('isJwtExpired', () => {
  const now = () => Math.floor(Date.now() / 1000);

  it('is false for a token valid well into the future', () => {
    expect(isJwtExpired(tokenWith({ exp: now() + 3600 }))).toBeFalse();
  });

  it('is true once the expiry has passed', () => {
    expect(isJwtExpired(tokenWith({ exp: now() - 60 }))).toBeTrue();
  });

  it('treats a token inside the skew window as expired', () => {
    // Refresh slightly early rather than let a call start with a token that
    // dies mid-flight.
    expect(isJwtExpired(tokenWith({ exp: now() + 5 }), 10)).toBeTrue();
  });

  it('treats a token with no expiry as expired', () => {
    // A credential that never expires is not something to assume by accident.
    expect(isJwtExpired(tokenWith({ sub: 'user-1' }))).toBeTrue();
  });

  it('treats undecodable input as expired', () => {
    expect(isJwtExpired('garbage')).toBeTrue();
  });
});
