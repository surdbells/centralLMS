#!/usr/bin/env bash
#
# Exercises the bridge inside the rehearsal stack. Run ./setup.sh first.
#
# Requests are issued from *inside* the PHP container: the built-in server
# behind Docker's port mapping on Windows does not resolve sub-paths reliably,
# and the point here is the application logic, not the network hop.

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
ENDPOINT="http://127.0.0.1:8090/user/courses/list.php"
LEGACY_TOKEN="a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0"

pass=0
fail=0

# Issues one request and prints "<status> <message>".
call() {
  docker exec rehearsal-php php -r '
    $c = stream_context_create(["http" => [
        "method" => "POST",
        "header" => "Content-Type: application/json",
        "content" => $argv[1],
        "ignore_errors" => true,
    ]]);
    $body = @file_get_contents($argv[2], false, $c);
    $code = "000";
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match("~^HTTP/\S+ (\d+)~", $h, $m)) { $code = $m[1]; }
    }
    echo $code;
  ' "$1" "$ENDPOINT" 2>/dev/null
}

check() {
  local label="$1" expected="$2" payload="$3"
  local actual
  actual="$(call "$payload")"

  if [[ "$actual" == "$expected" ]]; then
    printf '  \033[32mPASS\033[0m  %-42s %s\n' "$label" "$actual"
    pass=$((pass + 1))
  else
    printf '  \033[31mFAIL\033[0m  %-42s expected %s, got %s\n' "$label" "$expected" "$actual"
    fail=$((fail + 1))
  fi
}

echo
echo "Bypass attempts (must all be rejected):"
check "garbage token"            "401" '{"token":"nonsense","id":"42","tenant_id":"1"}'
check "wrong token, real user"   "401" '{"token":"b1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0","id":"42","tenant_id":"1"}'
check "near-miss token"          "401" "{\"token\":\"${LEGACY_TOKEN%??}ff\",\"id\":\"42\",\"tenant_id\":\"1\"}"
check "SQL injection in id"      "401" '{"token":"x","id":"42 OR 1=1","tenant_id":"1"}'

echo
echo "Existing sessions (must keep working):"
check "valid users.token"        "200" "{\"token\":\"$LEGACY_TOKEN\",\"id\":\"42\",\"tenant_id\":\"1\"}"

echo
echo "Bridge to the new API:"
TOKENS="$(cd "$REPO/apps/api" && php -r '
  require "vendor/autoload.php";
  $svc = new Central\Api\Infrastructure\Security\JwtTokenService([
      "secret" => "rehearsal-shared-secret-long-enough-for-hs256",
      "algorithm" => "HS256", "issuer" => "central-lms", "audience" => "central-clients",
      "access_ttl" => 900, "refresh_ttl" => 2592000, "leeway" => 10,
  ], new Central\Api\Support\SystemClock());
  $p = $svc->issue("42", "ada@rehearsal.test", ["tenant_admin"], "1");
  echo $p->accessToken, "|", $p->refreshToken;
')"
ACCESS="${TOKENS%%|*}"
REFRESH="${TOKENS##*|}"

check "new-API JWT, matching id"  "200" "{\"token\":\"$ACCESS\",\"id\":\"42\",\"tenant_id\":\"1\"}"
check "new-API JWT, wrong id"     "401" "{\"token\":\"$ACCESS\",\"id\":\"99\",\"tenant_id\":\"1\"}"
check "refresh token as bearer"   "401" "{\"token\":\"$REFRESH\",\"id\":\"42\",\"tenant_id\":\"1\"}"

echo
echo "Audit trail:"
docker logs rehearsal-php 2>&1 | grep 'central-auth' | tail -5 | sed 's/^/  /'

echo
if [[ $fail -eq 0 ]]; then
  printf '\033[32mAll %s checks passed.\033[0m Safe to deploy.\n' "$pass"
  exit 0
fi

printf '\033[31m%s check(s) failed.\033[0m Do not deploy.\n' "$fail"
exit 1
