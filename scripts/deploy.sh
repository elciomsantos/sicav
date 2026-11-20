#!/usr/bin/env bash
#
# Deploy de producao: preflight -> build/up -> espera healthchecks.
# Nunca pula o preflight — ele e o que impede subir a stack com senha
# placeholder ou com o drive de dados fora do ar.
#
# Uso:
#   ./scripts/deploy.sh            # producao
#   ./scripts/deploy.sh --dev      # testes locais (preflight relaxado)

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo "--> Preflight..."
"$ROOT/scripts/preflight.sh" "${1:-}"

echo "--> Build e subida da stack..."
docker compose -f "$ROOT/docker-compose.yml" up -d --build --remove-orphans

echo "--> Aguardando app e nginx ficarem healthy..."
for i in $(seq 1 90); do
    statuses="$(docker compose -f "$ROOT/docker-compose.yml" ps app nginx --format '{{.Service}} {{.Status}}' 2>/dev/null || true)"
    if [[ "$(grep -c 'healthy' <<< "$statuses")" -ge 2 ]]; then
        echo "$statuses"
        echo "OK: deploy concluido."
        exit 0
    fi
    if [[ "$i" -eq 90 ]]; then
        echo "ERRO: app/nginx nao ficaram healthy em 180s." >&2
        echo "$statuses" >&2
        docker compose -f "$ROOT/docker-compose.yml" logs app nginx --tail 50 >&2
        exit 1
    fi
    sleep 2
done
