#!/usr/bin/env bash
# Smoke test for Brazil Locations Service API
# Usage: ./scripts/smoke.sh [base_url]
set -u

BASE="${1:-http://127.0.0.1:8130}"
FALHAS=0

check() {
  local nome="$1" url="$2" esperado="$3"
  local codigo
  codigo=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$url")
  if [ "$codigo" = "$esperado" ]; then
    printf '  ok   %-42s %s\n' "$nome" "$codigo"
  else
    printf '  FAIL %-42s esperado %s, veio %s\n' "$nome" "$esperado" "$codigo"
    FALHAS=$((FALHAS+1))
  fi
}

echo "Testando $BASE"
check "health"                       "$BASE/health" 200
check "states"                       "$BASE/v1/locations/states" 200
check "regions"                      "$BASE/v1/locations/regions" 200
check "countries"                    "$BASE/v1/locations/countries" 200
check "cities de MT"                 "$BASE/v1/locations/states/MT/cities?limit=3" 200
check "busca por prefixo"            "$BASE/v1/locations/cities?search=flor" 200
check "busca com acento ignorado"    "$BASE/v1/locations/cities?search=agua" 200
check "cidade por codigo IBGE"       "$BASE/v1/locations/cities/1100015" 200
check "UF inexistente -> 404"        "$BASE/v1/locations/states/ZZ/cities" 404
check "termo curto -> 422"           "$BASE/v1/locations/cities?search=a" 422
check "codigo inexistente -> 404"    "$BASE/v1/locations/cities/9999999" 404
check "docs"                         "$BASE/docs" 200
check "openapi"                      "$BASE/openapi.json" 200
check "llms.txt"                     "$BASE/llms.txt" 200

echo
if [ "$FALHAS" -eq 0 ]; then
  echo "Tudo passou."
else
  echo "$FALHAS falha(s)."
  exit 1
fi
