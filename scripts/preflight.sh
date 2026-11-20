#!/usr/bin/env bash
#
# Preflight de deploy do SICAV.
#
# Recusa configuracoes inseguras (ambiente de dev, senhas placeholder/fracas)
# e garante que os diretorios de dados estao em um drive montado — sem isso o
# Docker cria as pastas no disco raiz e os dados vao parar no lugar errado.
#
# Uso:
#   ./scripts/preflight.sh            # modo producao (rigoroso)
#   ./scripts/preflight.sh --dev      # apenas para testes locais
#   SICAV_ENV_FILE=/caminho/.env ./scripts/preflight.sh
#
# Requer: docker compose, findmnt (util-linux).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# shellcheck source=scripts/lib/env-loader.sh
source "$ROOT/scripts/lib/env-loader.sh"

DEV_MODE=0
case "${1:-}" in
    '') ;;
    --dev) DEV_MODE=1 ;;
    -h|--help)
        echo "uso: $0 [--dev]"
        exit 0
        ;;
    *)
        echo "ERRO: argumento desconhecido: $1" >&2
        echo "uso: $0 [--dev]" >&2
        exit 1
        ;;
esac

ENV_FILE="${SICAV_ENV_FILE:-$ROOT/.env}"
if [[ ! -r "$ENV_FILE" ]]; then
    echo "ERRO: arquivo de ambiente nao encontrado/legivel: $ENV_FILE" >&2
    echo "      Copie .env.example.docker para .env e preencha os valores." >&2
    exit 1
fi
_sicav_load_env "$ENV_FILE"

fail() {
    echo "ERRO: $*" >&2
    exit 1
}
warn() {
    echo "AVISO: $*" >&2
}

echo "--> Preflight do deploy (arquivo: $ENV_FILE)"

# ---------------------------------------------------------------------------
# 1. Ambiente e credenciais
# ---------------------------------------------------------------------------
if [[ "$DEV_MODE" -eq 1 ]]; then
    warn "modo --dev: checagens de producao e guarda de mount desativadas."
    warn "         NAO usar este modo em um servidor de producao."
else
    APP_ENV="${APP_ENV:-production}"
    [[ "$APP_ENV" == "production" ]] || \
        fail "APP_ENV deve ser 'production' no deploy (atual: '$APP_ENV'). Use .env.example.docker como base."

    PLACEHOLDERS='troque-esta-senha|troque-esta-senha-root|visitorpassword|changeme|password|secret|admin|123456'
    for var in VISITORPASS_DB_PASS MYSQL_ROOT_PASSWORD; do
        value="${!var:-}"
        [[ -n "$value" ]] || fail "$var nao definida no .env."
        if printf '%s' "$value" | grep -Eqi "^(${PLACEHOLDERS})$"; then
            fail "$var ainda esta com valor placeholder/fraco — gere uma senha longa e aleatoria."
        fi
        if [[ "${#value}" -lt 12 ]]; then
            fail "$var tem ${#value} caracteres; o minimo e 12."
        fi
    done

    if [[ "${VISITORPASS_DB_PASS:-}" == "${MYSQL_ROOT_PASSWORD:-}" ]]; then
        fail "VISITORPASS_DB_PASS e MYSQL_ROOT_PASSWORD nao podem ser iguais."
    fi

    NGINX_PORT="${NGINX_PORT:-8000}"
    [[ "$NGINX_PORT" =~ ^[0-9]+$ ]] && (( NGINX_PORT >= 1 && NGINX_PORT <= 65535 )) || \
        fail "NGINX_PORT invalida: '$NGINX_PORT'."
fi

# ---------------------------------------------------------------------------
# 2. Guarda do drive de dados
# ---------------------------------------------------------------------------
check_data_dir() {
    local label="$1" dir="$2" expected_uid="$3" mountpoint owner

    [[ -d "$dir" ]] || fail "$label ($dir) nao existe. Monte o drive antes de subir a stack; nao crie a pasta manualmente no disco raiz."

    mountpoint="$(findmnt -T "$dir" -n -o TARGET 2>/dev/null || true)"
    [[ -n "$mountpoint" ]] || fail "Nao foi possivel determinar o ponto de montagem de $dir."
    [[ "$mountpoint" != "/" ]] || \
        fail "$label ($dir) esta no filesystem raiz (montado em '/'). O drive dedicado nao foi montado — aborte o deploy."

    owner="$(stat -c '%u:%g' "$dir" 2>/dev/null || echo '?')"
    if [[ "$owner" != "$expected_uid:$expected_uid" ]]; then
        warn "$label ($dir) pertence a $owner, esperado $expected_uid:$expected_uid."
        echo "     Corrija com: sudo chown -R $expected_uid:$expected_uid $dir"
    fi

    echo "    $label: $dir (mount: $mountpoint, owner: $owner)"
}

if [[ "$DEV_MODE" -eq 1 ]]; then
    echo "--> Guarda de mount: pulada (--dev)"
else
    echo "--> Verificando drive de dados..."
    command -v findmnt >/dev/null || fail "findmnt (util-linux) nao encontrado."
    check_data_dir "banco"  "${DB_DATA_DIR:-/mnt/visitantes/mysql}" 999
    check_data_dir "fotos"  "${FOTOS_DIR:-/mnt/visitantes/fotos}" 1000
fi

# ---------------------------------------------------------------------------
# 3. Compose valida
# ---------------------------------------------------------------------------
echo "--> Validando docker-compose.yml..."
command -v docker >/dev/null || fail "docker nao encontrado no PATH."
docker compose -f "$ROOT/docker-compose.yml" config --quiet || fail "docker compose config falhou — corrija o .env e tente novamente."

echo "OK: preflight concluido."
