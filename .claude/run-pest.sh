#!/usr/bin/env bash
# Usage: .claude/run-pest.sh <path>...   Runs Pest on the shared test container and prints a failure summary.
cd "$(dirname "$0")/.." || exit 1
if ! docker ps --format "{{.Names}}" | grep -q "^ff-apifix-pg$"; then docker start ff-apifix-pg >/dev/null 2>&1; sleep 10; fi
PORT=$(docker port ff-apifix-pg 5432/tcp | head -1 | sed 's/.*://')
PW=$(docker inspect ff-apifix-pg --format '{{range .Config.Env}}{{println .}}{{end}}' | grep '^POSTGRES_PASSWORD=' | cut -d= -f2-)
OUT=".claude/pest-last.txt"
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=$PORT DB_USERNAME=verify DB_PASSWORD="$PW" DB_DATABASE=${PEST_DB:-ff_mod_apifix} \
  php -d memory_limit=4G vendor/pestphp/pest/bin/pest --no-logging --no-coverage "$@" 2>&1 | cat > "$OUT"
node -e '
const t=require("fs").readFileSync(process.argv[1],"utf8");const i=t.indexOf("{\"tool\"");
if(i<0){console.log(t.slice(0,800));process.exit();}
const j=JSON.parse(t.slice(i));
console.log(j.result,"tests",j.tests,"passed",j.passed,"failed",j.failed||0);
for(const f of j.failures||j.error_details||[])console.log("-",String(f.test).replace(/.*__pest_evaluable_/,"").slice(0,100),"|",String(f.message).replace(/\n/g," ").slice(0,300));
' "$OUT"
