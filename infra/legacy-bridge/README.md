# Phase 0 bridge — closing the legacy auth bypass

A drop-in replacement for `Database::verify_user()` in the **live** legacy
backend. It does two things at once:

1. **Closes the authentication bypass.** The current implementation returns
   `100` unconditionally, so every one of the 248 `if ($token_check != 100)`
   guards is unreachable and the API accepts any request carrying arbitrary
   `token`, `id` and `tenant_id` values — across tenants.
2. **Bridges the two backends.** It accepts JWTs issued by the new Slim API, so
   a module can be migrated without the legacy side rejecting the same caller.

> This file is **not** part of `legacy/`, which is reference-only and never
> deployed. It is an operations artifact for the live PHP deployment.

## What it accepts

| Credential | Verified how |
| --- | --- |
| JWT from the new API | HS256 signature, `iss`, `aud`, `exp`, `typ=access`, and `sub` matching the caller's id |
| Existing `users.token` | Prepared statement, `hash_equals` — the check the original was meant to do |

Keeping the second branch is what makes this deployable **without signing
anyone out**: `Auth::host_seed()` already writes a fresh 40-hex-character token
on every login, so live sessions keep working. Delete that branch once all
clients sign in through the new API.

## Install

**1. Ensure the JWT settings match the new API exactly.** Same secret, issuer
and audience, or every cross-backend token is rejected:

```bash
JWT_SECRET=<identical to the Slim API>
JWT_ISSUER=central-lms
JWT_AUDIENCE=central-clients
```

Set them wherever the host provides environment variables to PHP-FPM. If none
is available, `getenv()` calls can be replaced with constants in the file — but
**never commit the secret**.

**2. Copy `CentralTokenVerifier.php`** next to `config/Database.php`.

**3. Replace the body of `verify_user()`** in `config/Database.php`:

```php
require_once __DIR__ . '/CentralTokenVerifier.php';

public function verify_user($token, $user_id): float {
    static $verifier = null;
    if ($verifier === null) {
        $verifier = new CentralTokenVerifier($this->getConnection());
    }
    return $verifier->verify($token, $user_id);
}
```

The signature and the `100` contract are unchanged, so **all 248 call sites keep
working untouched**. No other file needs editing.

**4. Reload PHP-FPM.** OPcache runs with `validate_timestamps=0`, so a file
change alone does nothing:

```bash
sudo systemctl reload php-fpm
```

**5. Confirm it is live.** A request with a nonsense token must now fail:

```bash
curl -s -X POST https://api.centrallms.com/user/courses/list \
  -H 'Content-Type: application/json' \
  -d '{"token":"nonsense","id":"1","tenant_id":"1"}'
```

Before: a normal `200` with data. After: `401 Unauthorized`.

## Rollout

Enforcing is the default and is the correct setting. If you would rather
measure first, run one deploy with:

```bash
LEGACY_AUTH_AUDIT_ONLY=true
```

Failures are then logged but still allowed through, so `error_log` shows
exactly who would have been rejected. **This leaves the bypass open** — treat
it as a few hours, not a few weeks, then remove the variable.

Rejections are logged as:

```
[central-auth] rejected user_id=42 reason=legacy_token_mismatch ip=1.2.3.4 path=/user/courses/list
```

Reasons: `missing_credentials`, `no_stored_token`, `legacy_token_mismatch`,
`jwt_invalid`, `jwt_wrong_type`, `jwt_bad_issuer`, `jwt_bad_audience`,
`jwt_subject_mismatch`, `jwt_secret_not_configured`.

A burst of `no_stored_token` means users whose `users.token` is empty — they
will need to sign in again. Everything else should be near zero.

## Rollback

Restore the previous `verify_user()` body and reload PHP-FPM. Doing so
re-opens the bypass, so treat it as an emergency measure only.

## Tested

**Unit** — `apps/api/tests/Unit/Bridge/CentralTokenVerifierTest.php`, 13 tests
run against the **real** `JwtTokenService`, so the two implementations cannot
drift apart on signing. Covers acceptance, cross-user rejection, refresh-token
rejection, forged signature, expiry, wrong audience, missing secret,
legacy-token match and near-miss, and audit mode.

**Rehearsed against the real legacy code** — `rehearsal/`, a throwaway
MySQL 5.7 + PHP stack running an isolated copy of `legacy/backend` with the
production credentials rewritten out:

```bash
cd infra/legacy-bridge/rehearsal && ./setup.sh && ./test.sh
```

The bypass reproduced exactly as described. Before applying the bridge, a
request with `token: "nonsense"` returned:

```json
{"response_code": 200, "status": "success", "message": "Courses retrieved successfully", …}
```

After:

| Request | Before | After |
| --- | --- | --- |
| garbage token | 200 | **401** |
| wrong token, real user | 200 | **401** |
| near-miss token | 200 | **401** |
| SQL injection in `id` | 200 | **401** |
| valid `users.token` | 200 | **200** — nobody signed out |
| new-API JWT, matching `sub` | — | **200** |
| new-API JWT, wrong `id` | — | **401** |
| refresh token as bearer | — | **401** |

### Two things the rehearsal caught

1. **`Database.php` does not load Composer's autoloader.** Most legacy
   endpoints require it themselves, but this class is constructed from inside
   `Database.php`, which does not — so the first JWT would have fataled with
   *Class Firebase\JWT\JWT not found*. The verifier now loads
   `vendor/autoload.php` itself if the class is absent.
2. **The host runs PHP 8.x, not 7.4.** The database dump header says
   PHP 7.4.33, but that is phpMyAdmin's runtime — `Database.php` uses `match()`,
   which is PHP 8.0+. The bridge is written to 7.4 syntax anyway, so it runs on
   either.

## After this lands

`ApiModule.Auth` in the frontend can flip to `ApiBackend.V1`: the browser then
signs in against the new API and the legacy endpoints accept the resulting JWT.
That is the first real strangler cutover.

Written for PHP 7.4 so it runs unchanged on whatever the host is on.
`firebase/php-jwt ^6.11` is already in the legacy `composer.json`, so no new
dependency is needed.
