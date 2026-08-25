/**
 * Safe reader for the base64 session blob in `sessionStorage`.
 *
 * 57 components did this in `ngOnInit`:
 *
 * ```ts
 * this.session_data = sessionStorage.getItem('SESSION');
 * this.user_session = JSON.parse(atob(this.session_data));
 * ```
 *
 * With no session present, `atob(null)` decodes the string `"null"` to
 * mojibake and `JSON.parse` throws — inside a lifecycle hook, so the component
 * never renders. Any user with cleared or corrupted session storage got a
 * blank page, and a hand-edited value could take out the same screens.
 *
 * This returns `null` instead of throwing. It is a mitigation, not the
 * destination: these components should move to `SessionStore`, which holds the
 * same data as a signal and validates it once on load.
 */
export function decodeSessionBlob<T = Record<string, unknown>>(raw: string | null | undefined): T | null {
  if (raw === null || raw === undefined || raw === '') {
    return null;
  }

  try {
    const binary = atob(raw);

    // The blob is UTF-8 bytes; decoding as Latin-1 would mangle any non-ASCII
    // name or email it contains.
    const bytes = Uint8Array.from(binary, (char) => char.charCodeAt(0));
    const json = new TextDecoder().decode(bytes);

    const parsed = JSON.parse(json) as unknown;

    return typeof parsed === 'object' && parsed !== null ? (parsed as T) : null;
  } catch {
    // Absent, truncated, or not base64 at all.
    return null;
  }
}
