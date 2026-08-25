#!/usr/bin/env bash
#
# Post-deploy check for the Phase 0 bridge.
#
# Run this against the legacy API immediately after deploying
# CentralTokenVerifier.php and reloading PHP-FPM.
#
#   ./verify.sh https://api.centrallms.com
#
# Optionally pass a real user id and token to confirm live sessions still work:
#
#   ./verify.sh https://api.centrallms.com 42 <that-user's-users.token>
#
# Exit code 0 means the bypass is closed. Non-zero means stop and roll back.

set -uo pipefail

BASE="${1:-}"
USER_ID="${2:-}"
USER_TOKEN="${3:-}"

if [[ -z "$BASE" ]]; then
  echo "usage: $0 <base-url> [user-id] [users.token]" >&2
  exit 64
fi

# A GET-shaped endpoint that requires auth. Reads only.
ENDPOINT="$BASE/user/courses/list"

pass=0
fail=0

check() {
  local label="$1" expected="$2" actual="$3"
  if [[ "$actual" == "$expected" ]]; then
    printf '  \033[32mPASS\033[0m  %-46s (got %s)\n' "$label" "$actual"
    pass=$((pass + 1))
  else
    printf '  \033[31mFAIL\033[0m  %-46s (expected %s, got %s)\n' "$label" "$expected" "$actual"
    fail=$((fail + 1))
  fi
}

post_status() {
  curl -s -o /dev/null -w '%{http_code}' -X POST "$ENDPOINT" \
    -H 'Content-Type: application/json' -d "$1" --max-time 20
}

echo
echo "Verifying $BASE"
echo

# --- the bypass ------------------------------------------------------------
# Before the fix every one of these returned 200 with real data.
echo "Bypass attempts (all must be rejected):"
check "garbage token"        "401" "$(post_status '{"token":"nonsense","id":"1","tenant_id":"1"}')"
check "empty token"          "400" "$(post_status '{"token":"","id":"1","tenant_id":"1"}')"
check "another user's id"    "401" "$(post_status '{"token":"nonsense","id":"99999","tenant_id":"1"}')"
check "SQL injection in id"  "401" "$(post_status '{"token":"x","id":"1 OR 1=1","tenant_id":"1"}')"

# --- existing sessions -----------------------------------------------------
echo
if [[ -n "$USER_ID" && -n "$USER_TOKEN" ]]; then
  echo "Existing session (must still work):"
  check "valid users.token" "200" \
    "$(post_status "{\"token\":\"$USER_TOKEN\",\"id\":\"$USER_ID\",\"tenant_id\":\"1\"}")"
else
  echo "Existing session: skipped (no user id/token supplied)."
  echo "  Re-run with a real pair to confirm nobody was signed out:"
  echo "    $0 $BASE <user-id> <users.token>"
fi

echo
if [[ $fail -eq 0 ]]; then
  printf '\033[32m%s checks passed.\033[0m The bypass is closed.\n' "$pass"
  echo
  echo "Next: watch the error log for a few minutes."
  echo "  grep central-auth /www/wwwlogs/php-fpm.log | tail -50"
  echo
  echo "A burst of no_stored_token means users whose users.token is empty —"
  echo "they will need to sign in again. Everything else should be near zero."
  exit 0
fi

printf '\033[31m%s check(s) failed.\033[0m\n' "$fail"
echo
echo "If bypass attempts still return 200, the new code is not live. Most likely:"
echo "  - PHP-FPM was not reloaded (OPcache runs with validate_timestamps=0)"
echo "  - verify_user() still has its old body"
echo "  - LEGACY_AUTH_AUDIT_ONLY is set to true"
echo
echo "If a valid session now fails, check that JWT_SECRET, JWT_ISSUER and"
echo "JWT_AUDIENCE match the new API exactly."
exit 1
