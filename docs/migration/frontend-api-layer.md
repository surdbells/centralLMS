# The frontend API layer

`GlobalComponent` is gone. What replaced it, and how to migrate a service or a
whole module onto the new API.

## Why it had to change

The old arrangement made a strangler migration impossible. Endpoints were 97
`static` strings, each concatenating a hardcoded production URL:

```ts
public static listCourse = GlobalComponent.baseURL + '/user/courses/list';
```

Moving one module to the new API would have meant editing every constant it
touched, in a file also holding `validateEmail`, `decodeJWT` and base64
helpers — with no way to move one module and leave the rest alone.

Bugs it shipped with, all fixed by construction in the replacement:

| Bug | Effect |
| --- | --- |
| `utility/shared/user` — no leading slash | requested `https://api.centrallms.comutility/shared/user` |
| `utility/shared//mark_notifications` | doubled slash |
| `integration_redirect` = `/user/uploads` | copy-paste; same value as `im_uploads` |
| `updateCourseStatus`, `deleteCourse` declared, never assigned | `undefined` at runtime |
| `baseURL` hardcoded to production | no way to point a build at staging |
| Zoom API key in `environment.development.ts` | live credential in the bundle |

## The shape now

```
src/app/core/
├── api/
│   ├── api-module.ts        ApiModule  — the unit of migration
│   ├── api-backend.ts       ApiBackend — Legacy | V1
│   ├── api-routing.ts       ApiRouter  — the switchboard
│   ├── api-endpoints.ts     Endpoints  — relative paths, grouped by module
│   ├── api-response.ts      envelopes, ApiError
│   ├── api.client.ts        ApiClient  — the only way to call a backend
│   └── interceptors/        auth, request id
├── auth/session.store.ts    signal-backed session
└── utils/jwt.ts             decodeJwt, isJwtExpired
```

Import from `core/api` — never deeper — so internals can be reshaped without a
repo-wide find-and-replace.

## Migrating a module (the strangler flip)

**One line.** In `src/environments/environment.ts`:

```ts
moduleBackends: {
  [ApiModule.Dictionary]: ApiBackend.V1,
}
```

That is the whole change. Every service in that module now points at the new
API, sends a bearer token instead of legacy body credentials, and has its
responses unwrapped from the new envelope instead of the old one. Reverting is
deleting the line.

Anything not listed defaults to `ApiBackend.Legacy`, so a module nobody has
migrated keeps working and a newly added one cannot accidentally hit an
endpoint the API has not built yet.

## Migrating a service

`MediaService` is the worked example. Three changes, and they are the same
three every time:

**1. Name a module, not a URL.**

```ts
// before
private baseUrl = 'https://api.centrallms.com';
this.http.post(`${this.baseUrl}/user/media/versions`, body)

// after
this.api.post(Endpoints.media.module, Endpoints.media.versions, body)
```

**2. Drop the credential parameters.**

```ts
// before — every method carried them, and every caller had to supply them
getVersionHistory(mediaId: number, token: string, userId: number, tenantId: number)

// after — the auth interceptor adds them in the form the backend expects
getVersionHistory(mediaId: number)
```

**3. Return the payload, not the envelope.**

```ts
// before
Observable<{ code: number; status: string; data: { media: MediaData } }>

// after
Observable<MediaData>
```

Callers then need no change when the module migrates, because the envelope
difference never reaches them.

## What the client absorbs

The two backends are different protocols, not just different hosts.

| | Legacy | New API |
| --- | --- | --- |
| Credentials | `token`, `id`, `tenant_id` in the **body** (query string for GET) | `Authorization: Bearer` |
| Success | `{ response_code, status, message, data }` | `{ data, meta? }` |
| Failure | `{ response_code: 401, message }` | `{ error: { code, message, details } }` |
| HTTP status on failure | **always 200** | the real status |
| Pagination | none | `meta` |

That fourth row is the one to remember. `Database::send_response()` never calls
`http_response_code()`, so a legacy 401 arrives as HTTP 200 with the failure
buried in the body. `ApiClient` detects it and raises the same `ApiError` the
new API would, which is why a component's error handling survives migration
untouched.

Legacy status codes are mapped onto the new API's vocabulary
(`401 → unauthenticated`, `422 → validation_failed`, …) so `catch` blocks
branch on one set of codes regardless of backend.

## Handling errors

```ts
this.media.getVersionHistory(id).subscribe({
  next: (history) => { /* … */ },
  error: (e: ApiError) => {
    if (e.isValidation) {
      this.formErrors = e.violations;      // {} for non-validation errors
    } else if (e.isRetryable) {
      this.toast.error(`${e.message} (ref: ${e.requestId})`);
    }
  },
});
```

`CrudService` handled errors with `console.log` and re-threw a string.

## Remaining work

`MediaService` is migrated. Still on the old pattern:

| Service | Ops | Notes |
| --- | --- | --- |
| `learning-path.service.ts` | 70 | largest |
| `assessment.service.ts` | 33 | |
| `grading.service.ts` | 28 | |
| `skills.service.ts` | 25 | |
| `question-bank.service.ts` | 24 | |
| `certificate.service.ts` | 21 | |
| `proctoring.service.ts` | 17 | |
| `attempt.service.ts` | 15 | |
| `learning-path-enrollment.service.ts` | 8 | |
| `crud.service.ts` | — | delete once nothing imports it |

These all follow the same shape — POST to one endpoint with an `action` field —
so they convert mechanically.

`GlobalComponent` still exists for the components that reference it directly;
it should be deleted once they are moved. Its non-API helpers already have
homes: `decodeJWT` → `core/utils/jwt.ts`, and `validateEmail` /
`validateNumber` / base64 belong in `core/utils` next to it.

## Testing

Karma with headless Chrome. `karma.conf.js` was added — the app had none and
relied on CLI defaults that launch a windowed browser, unusable in CI.

```bash
pnpm --filter @central/web test
```

Covered: the switchboard (`api-routing.spec.ts`), envelope normalisation in
both dialects including the legacy 200-with-error case (`api.client.spec.ts`),
both credential schemes (`auth.interceptor.spec.ts`), and the JWT helpers.

The suite is Karma because that is what Angular 19 supports without extra
tooling. Angular 20 replaces it with a Vitest-based runner — worth taking with
the framework upgrade, not before.
