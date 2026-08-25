<?php

/**
 * Drop-in replacement for the body of Database::verify_user().
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 * The original was:
 *
 *     public function verify_user($token, $user_id): float {
 *         $sql = "SELECT token FROM users where id = '$user_id'";
 *         ...
 *         similar_text($token, $user_token, $percent);
 *         //return $percent;
 *         return 100;
 *     }
 *
 * It returned 100 unconditionally. Every one of the 248 call sites checks
 * `if ($token_check != 100)`, so that branch was unreachable and the entire
 * API accepted any request carrying arbitrary non-empty `token`, `id` and
 * `tenant_id` values — including cross-tenant reads and writes.
 *
 * Two further problems, either of which would be serious on its own:
 *
 *   - `$user_id` was interpolated straight into SQL. Injectable.
 *   - Even with `return $percent` restored, `similar_text()` is a *fuzzy*
 *     similarity measure. A partially-matching token would have passed.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS DOES
 * ---------------------------------------------------------------------------
 * Accepts either credential, so the bypass closes with no session loss:
 *
 *   1. A JWT issued by the new Slim API — verified signature, issuer,
 *      audience, expiry, and `typ=access`, with `sub` matching the caller's id.
 *   2. The legacy opaque token in `users.token` — compared in constant time
 *      with a prepared statement. This is what the original was *meant* to do.
 *
 * Once every client signs in through the new API, delete branch 2.
 *
 * Written for PHP 7.4 so it runs unchanged on whatever the host is on.
 *
 * @see docs/migration/identity.md
 */

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// `config/Database.php` does not load Composer's autoloader — most legacy
// endpoints require it themselves, but this class is constructed from inside
// Database.php, which does not. Without this, the JWT branch fatals with
// "Class Firebase\JWT\JWT not found" on the first token it sees.
if (!class_exists(JWT::class, false)) {
    foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../vendor/autoload.php'] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;
            break;
        }
    }
}

class CentralTokenVerifier
{
    /** Returned to satisfy the existing `!= 100` checks. */
    const PASS = 100.0;
    const FAIL = 0.0;

    /**
     * Optional: only the legacy-token branch touches the database, so a
     * deployment past that stage — or a test — can pass null.
     *
     * @var mysqli|null
     */
    private $conn;

    /** @var string */
    private $jwtSecret;

    /** @var string */
    private $issuer;

    /** @var string */
    private $audience;

    /**
     * When true, failures are logged but still allowed through.
     *
     * For a short measurement window only — it leaves the bypass open. Off by
     * default, deliberately: the safe default for an auth check is to enforce.
     */
    private $auditOnly;

    /**
     * @param mysqli|null $conn
     */
    public function __construct($conn = null)
    {
        $this->conn = $conn;
        $this->jwtSecret = (string) getenv('JWT_SECRET');
        $this->issuer = getenv('JWT_ISSUER') ?: 'central-lms';
        $this->audience = getenv('JWT_AUDIENCE') ?: 'central-clients';
        $this->auditOnly = filter_var(getenv('LEGACY_AUTH_AUDIT_ONLY'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param string|null $token
     * @param string|int|null $userId
     *
     * @return float 100.0 when the caller is authenticated, 0.0 otherwise
     */
    public function verify($token, $userId)
    {
        $token = is_string($token) ? trim($token) : '';
        $userId = is_scalar($userId) ? (string) $userId : '';

        if ($token === '' || $userId === '') {
            return $this->reject('missing_credentials', $userId);
        }

        if ($this->looksLikeJwt($token)) {
            return $this->verifyJwt($token, $userId);
        }

        return $this->verifyLegacyToken($token, $userId);
    }

    /**
     * Three base64url segments separated by dots.
     *
     * Legacy tokens are 40 hex characters and contain no dots, so the two
     * formats cannot be confused.
     *
     * @param string $token
     * @return bool
     */
    private function looksLikeJwt($token)
    {
        return substr_count($token, '.') === 2;
    }

    /**
     * @param string $token
     * @param string $userId
     * @return float
     */
    private function verifyJwt($token, $userId)
    {
        if (strlen($this->jwtSecret) < 32) {
            // Misconfiguration must not silently fall back to accepting
            // everything, which is exactly how the original behaved.
            return $this->reject('jwt_secret_not_configured', $userId);
        }

        try {
            JWT::$leeway = 10;
            $claims = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
        } catch (\Throwable $e) {
            return $this->reject('jwt_invalid: ' . $e->getMessage(), $userId);
        }

        if (!isset($claims->typ) || $claims->typ !== 'access') {
            // A refresh token must never work as a bearer credential.
            return $this->reject('jwt_wrong_type', $userId);
        }

        if (!isset($claims->iss) || $claims->iss !== $this->issuer) {
            return $this->reject('jwt_bad_issuer', $userId);
        }

        if (!isset($claims->aud) || $claims->aud !== $this->audience) {
            return $this->reject('jwt_bad_audience', $userId);
        }

        // The token proves who the caller is; the request says who they claim
        // to be. If those disagree, the caller is trying to act as someone
        // else — which is precisely what the old code allowed.
        if (!isset($claims->sub) || !hash_equals((string) $claims->sub, $userId)) {
            return $this->reject('jwt_subject_mismatch', $userId);
        }

        return self::PASS;
    }

    /**
     * The check the original was supposed to perform.
     *
     * @param string $token
     * @param string $userId
     * @return float
     */
    private function verifyLegacyToken($token, $userId)
    {
        $stored = $this->storedTokenFor($userId);

        if ($stored === null || $stored === '') {
            return $this->reject('no_stored_token', $userId);
        }

        // Constant-time, and an exact match — not similar_text().
        if (!hash_equals($stored, $token)) {
            return $this->reject('legacy_token_mismatch', $userId);
        }

        return self::PASS;
    }

    /**
     * Reads `users.token` for the given id.
     *
     * Prepared, so the id can no longer be injected — the original
     * interpolated it straight into the SQL string.
     *
     * `protected` so tests can substitute a stored token without a database.
     *
     * @param string $userId
     * @return string|null
     */
    protected function storedTokenFor($userId)
    {
        if ($this->conn === null) {
            return null;
        }

        $stmt = mysqli_prepare($this->conn, 'SELECT token FROM users WHERE id = ? LIMIT 1');

        if ($stmt === false) {
            return null;
        }

        $stored = null;
        mysqli_stmt_bind_param($stmt, 's', $userId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $stored);
        $found = mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        return $found && is_string($stored) ? $stored : null;
    }

    /**
     * @param string $reason
     * @param string $userId
     * @return float
     */
    private function reject($reason, $userId)
    {
        error_log(sprintf(
            '[central-auth] rejected user_id=%s reason=%s ip=%s path=%s%s',
            $userId === '' ? '-' : $userId,
            $reason,
            isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '-',
            isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '-',
            $this->auditOnly ? ' [AUDIT-ONLY: allowed through]' : ''
        ));

        return $this->auditOnly ? self::PASS : self::FAIL;
    }
}
