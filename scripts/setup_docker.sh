#!/usr/bin/env bash
#
# Setup automatico do sistema em Docker:
#   1. Cria .env a partir do exemplo se nao existir
#   2. Build das imagens
#   3. Sobe os containers (app + nginx + db)
#   4. Espera o banco ficar saudavel
#   5. Verifica que os usuarios padrao (admin/supervisor) foram criados
#
# Uso:
#   ./scripts/setup_docker.sh            # setup completo
#   ./scripts/setup_docker.sh --build    # forca rebuild das imagens
#   ./scripts/setup_docker.sh --reset    # remove containers (dados no RAID 6 preservados)
#

set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE=(docker compose)
BUILD_FLAGS=()
UP_FLAGS=()
RESET=0

for arg in "$@"; do
    case "$arg" in
        --build) BUILD_FLAGS+=(--no-cache); UP_FLAGS+=(--build) ;;
        --reset) RESET=1 ;;
        *) echo "Argumento desconhecido: $arg" >&2; exit 1 ;;
    esac
done

if [ ! -f .env ]; then
    echo "--> Criando .env a partir de .env.example.docker..."
    cp .env.example.docker .env
else
    echo "--> .env ja existe, mantendo."
fi

if [ "$RESET" = "1" ]; then
    echo "--> Reset: removendo containers e volumes nomeados (dados em /srv preservados)..."
    "${COMPOSE[@]}" down -v --remove-orphans
fi

echo "--> Build das imagens..."
"${COMPOSE[@]}" build "${BUILD_FLAGS[@]}"

echo "--> Subindo containers..."
"${COMPOSE[@]}" up -d "${UP_FLAGS[@]}"

echo "--> Aguardando banco de dados ficar saudavel..."
for i in $(seq 1 60); do
    status=$("${COMPOSE[@]}" ps db --format '{{.Status}}' 2>/dev/null || true)
    if echo "$status" | grep -qi healthy; then
        echo "    Banco de dados: healthy"
        break
    fi
    if [ "$i" = "60" ]; then
        echo "ERRO: banco de dados nao ficou saudavel em 60 tentativas." >&2
        "${COMPOSE[@]}" logs db --tail 50 >&2
        exit 1
    fi
    sleep 2
done

echo "--> Aguardando app ficar saudavel..."
for i in $(seq 1 60); do
    status=$("${COMPOSE[@]}" ps app --format '{{.Status}}' 2>/dev/null || true)
    if echo "$status" | grep -qi "Up"; then
        echo "    App: up"
        break
    fi
    if [ "$i" = "60" ]; then
        echo "ERRO: app nao subiu em 60 tentativas." >&2
        "${COMPOSE[@]}" logs app --tail 50 >&2
        exit 1
    fi
    sleep 2
done

echo "--> Verificando usuarios padrao..."
USERS=$("${COMPOSE[@]}" exec -T db mysql -u"${VISITORPASS_DB_USER:-vpuser}" -p"${VISITORPASS_DB_PASS:-visitorpassword}" "${VISITORPASS_DB_NAME:-visitorpass}" -N -e \
    "SELECT usuario, nivel_acesso FROM usuarios ORDER BY id;" 2>/dev/null || true)

echo "$USERS"

if ! echo "$USERS" | grep -q admin; then
    echo "ERRO: usuario admin nao encontrado no banco." >&2
    echo "Confira os logs da migracao:" >&2
    "${COMPOSE[@]}" logs app --tail 30 >&2
    exit 1
fi

NGINX_PORT=$(grep -E '^NGINX_PORT=' .env | cut -d= -f2 || true)
NGINX_PORT=${NGINX_PORT:-8000}

echo
echo "======================================================"
echo " Setup concluido!"
echo " Aplicacao: http://<IP-do-servidor>:${NGINX_PORT}  (via container nginx)"
echo " Usuarios padrao (alterar no primeiro login):"
echo "   admin      / Admin@2026Temp!      (nivel admin)"
echo "   supervisor / Supervisor@2026Temp! (nivel supervisor)"
echo "======================================================"
