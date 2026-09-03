import { HttpErrorResponse } from '@angular/common/http';

/**
 * Reading the API's error envelope.
 *
 * Failures come back as `{error: {code, message, details?}}` with a 4xx; a
 * validation failure carries `details` as a map of field to message. These two
 * helpers pull those out so a form can show the message against the field, and
 * fall back to something sayable when the shape is anything else (a network
 * error, a 500 with no body).
 */

interface ApiError {
  readonly error?: {
    readonly code?: string;
    readonly message?: string;
    readonly details?: Record<string, unknown>;
  };
}

function body(failure: unknown): ApiError['error'] | null {
  if (failure instanceof HttpErrorResponse && failure.error && typeof failure.error === 'object') {
    const wrapped = failure.error as ApiError;

    return wrapped.error ?? null;
  }

  return null;
}

/** Field-level validation messages, `{}` when there are none. */
export function extractFieldErrors(failure: unknown): Record<string, string> {
  const details = body(failure)?.details;
  if (!details || typeof details !== 'object') {
    return {};
  }

  const out: Record<string, string> = {};
  for (const [field, message] of Object.entries(details)) {
    if (typeof message === 'string') {
      out[field] = message;
    } else if (Array.isArray(message) && typeof message[0] === 'string') {
      out[field] = message[0];
    }
  }

  return out;
}

/** The API's own message, or the given fallback when there isn't one. */
export function firstErrorMessage(failure: unknown, fallback: string): string {
  const message = body(failure)?.message;
  if (typeof message === 'string' && message.trim() !== '') {
    return message;
  }

  // A first field message is more use than the fallback, if there is one.
  const fields = extractFieldErrors(failure);
  const first = Object.values(fields)[0];

  return first ?? fallback;
}
