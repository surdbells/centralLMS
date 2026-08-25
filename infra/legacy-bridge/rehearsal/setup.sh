#!/usr/bin/env bash
#
# Rehearses the Phase 0 bridge against the real legacy code and a real MySQL,
# so the production deploy is not the first time it runs.
#
#   ./setup.sh
#
# Builds a throwaway copy of legacy/backend with the database credentials
# rewritten to point at a local container. Production is never contacted.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
WORK="$HERE/.work"

echo "Preparing an isolated copy of the legacy backend…"
rm -rf "$WORK"
mkdir -p "$WORK"
cp -r "$REPO/legacy/backend" "$WORK/backend"
cp "$REPO/legacy/db/central_lxp.sql" "$WORK/schema.sql"

DB="$WORK/backend/config/Database.php"

# --- safety: the copy must not be able to reach production -------------------
sed -i.bak -E \
  -e "s|private string \\\$host[[:space:]]*=[[:space:]]*'[^']*';|private string \\\$host  = 'rehearsal-mysql:3306';|" \
  -e "s|private string \\\$user[[:space:]]*=[[:space:]]*'[^']*';|private string \\\$user  = 'root';|" \
  -e "s|private string \\\$password[[:space:]]*=[[:space:]]*\"[^\"]*\";|private string \\\$password   = \"rehearsal\";|" \
  "$DB"
rm -f "$DB.bak"

if grep -qE '138\.197|ZmBWpxDN' "$DB"; then
  echo "ABORT: production credentials still present in the rehearsal copy." >&2
  exit 1
fi
echo "  credentials rewritten; production is unreachable from this run."

# --- apply the bridge exactly as the README instructs ------------------------
cp "$HERE/../CentralTokenVerifier.php" "$WORK/backend/config/"

php -r '
$f = $argv[1];
$s = file_get_contents($f);
$start = strpos($s, "public function verify_user(");
$end   = strpos($s, "public function send_response(");
if ($start === false || $end === false) { fwrite(STDERR, "verify_user/send_response markers not found\n"); exit(1); }
$new = "public function verify_user(\$token, \$user_id): float{\n"
     . "        static \$verifier = null;\n"
     . "        if (\$verifier === null) {\n"
     . "            require_once __DIR__ . \x27/CentralTokenVerifier.php\x27;\n"
     . "            \$verifier = new CentralTokenVerifier(\$this->getConnection());\n"
     . "        }\n"
     . "        return \$verifier->verify(\$token, \$user_id);\n"
     . "    }\n    ";
file_put_contents($f, substr($s, 0, $start) . $new . substr($s, $end));
' "$DB"
php -l "$DB" >/dev/null
echo "  bridge applied to the copy."

cp "$HERE/docker-compose.yml" "$HERE/php.Dockerfile" "$WORK/"

echo "Starting containers (first run pulls mysql:5.7 and php:8.2)…"
(cd "$WORK" && docker compose up -d --build)

echo "Waiting for the schema import…"
for _ in $(seq 1 60); do
  if docker exec rehearsal-mysql mysqladmin ping -prehearsal >/dev/null 2>&1; then break; fi
  sleep 5
done

docker exec rehearsal-mysql mysql -uroot -prehearsal central_lxp \
  -e "INSERT INTO users (id, tenant_id, token, first_name, last_name, email, password, is_active, is_admin)
      VALUES (42, 1, 'a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0', 'Ada', 'Lovelace',
              'ada@rehearsal.test', 'x', 1, 1)
      ON DUPLICATE KEY UPDATE token = VALUES(token);" 2>/dev/null

cat <<'DONE'

Ready. Run the checks with:

  ./test.sh

Tear down with:

  (cd .work && docker compose down -v)
DONE
