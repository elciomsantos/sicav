#!/usr/bin/env bash
#
# Backup completo do SICAV: dump consistente do MySQL + fotos dos visitantes.
#
# - flock evita execucoes concorrentes
# - mysqldump com arquivo de opcoes 600 (senha nunca em argv / ps)
# - artefatos com sha256, opcionalmente criptografados com GPG (PII)
# - retencao local e copia opcional para outro host via rsync
#
# Uso:
#   ./scripts/backup.sh                       # modo producao (GPG obrigatorio)
#   ./scripts/backup.sh --output /tmp/bk --no-encrypt   # teste local
#
# Variaveis (via .env ou ambiente):
#   BACKUP_DIR            destino local (default: /mnt/visitantes/backups)
#   BACKUP_GPG_RECIPIENT  destinatario GPG (email ou fingerprint) — obrigatorio
#   BACKUP_REMOTE         destino rsync remoto (ex.: backup@host:/srv/sicav)
#   BACKUP_KEEP_DAYS      dias de retencao local (default: 7)
#   DB_DATA_DIR, FOTOS_DIR, VISITORPASS_DB_* , VISITORPASS_APP_VERSION

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# shellcheck source=scripts/lib/env-loader.sh
source "$ROOT/scripts/lib/env-loader.sh"

ENV_FILE="${SICAV_ENV_FILE:-$ROOT/.env}"
[[ -r "$ENV_FILE" ]] || { echo "ERRO: .env nao legivel: $ENV_FILE" >&2; exit 1; }
_sicav_load_env "$ENV_FILE"

fail() { echo "ERRO: $*" >&2; exit 1; }
warn() { echo "AVISO: $*" >&2; }

ENCRYPT=1
OUTPUT_GIVEN=0
BACKUP_DIR="${BACKUP_DIR:-/mnt/visitantes/backups}"

while [ "$#" -gt 0 ]; do
    case "$1" in
        --no-encrypt) ENCRYPT=0; shift ;;
        --output)
            [ "$#" -ge 2 ] || fail "--output requer um diretorio."
            BACKUP_DIR="$2"; OUTPUT_GIVEN=1; shift 2 ;;
        -h|--help)
            sed -n '2,25p' "$0"; exit 0 ;;
        *) fail "argumento desconhecido: $1" ;;
    esac
done

for cmd in docker tar sha256sum mktemp flock gzip findmnt; do
    command -v "$cmd" >/dev/null || fail "$cmd nao encontrado no PATH."
done

# Trava: nao deixar dois backups (ou restore) competirem pelo mesmo destino.
LOCK_FILE="${TMPDIR:-/tmp}/sicav-backup.lock"
exec 9>"$LOCK_FILE"
flock -n 9 || fail "ja existe um backup em execucao (lock: $LOCK_FILE)."

# O destino padrao tem que estar no drive montado. Um --output explicito e
# considerado teste.local e fica fora dessa regra.
if [[ "$OUTPUT_GIVEN" -eq 0 ]]; then
    mountpoint="$(findmnt -T "$BACKUP_DIR" -n -o TARGET 2>/dev/null || true)"
    [[ "$mountpoint" != "/" ]] || \
        fail "BACKUP_DIR ($BACKUP_DIR) esta no filesystem raiz. Monte o drive antes de rodar o backup."
fi

if [[ "$ENCRYPT" -eq 1 ]]; then
    command -v gpg >/dev/null || fail "gpg nao encontrado (use --no-encrypt apenas em testes)."
    [[ -n "${BACKUP_GPG_RECIPIENT:-}" ]] || \
        fail "BACKUP_GPG_RECIPIENT nao definido. Configure a chave ou use --no-encrypt em testes."
fi
if [[ -n "${BACKUP_REMOTE:-}" && "$ENCRYPT" -eq 0 ]]; then
    fail "BACKUP_REMOTE definido mas a criptografia foi desativada — recusando enviar PII sem cifrar."
fi

DB_NAME="${VISITORPASS_DB_NAME:-visitorpass}"
DB_USER="${VISITORPASS_DB_USER:-vpuser}"
DB_PASS="${VISITORPASS_DB_PASS:-}"
FOTOS_DIR="${FOTOS_DIR:-/mnt/visitantes/fotos}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-7}"
TS="$(date -u +%Y%m%dT%H%M%SZ)"

[[ -n "$DB_PASS" ]] || fail "VISITORPASS_DB_PASS nao definida."
[[ -d "$FOTOS_DIR" ]] || fail "FOTOS_DIR nao existe: $FOTOS_DIR"

WORK="$(mktemp -d "${TMPDIR:-/tmp}/sicav-backup.XXXXXX")"
REMOTE_CNF="/tmp/sicav-backup-client.cnf"

cleanup() {
    rm -rf "$WORK"
    docker compose -f "$ROOT/docker-compose.yml" exec -T db \
        rm -f "$REMOTE_CNF" >/dev/null 2>&1 || true
}
trap cleanup EXIT

mkdir -p "$BACKUP_DIR"

echo "--> [1/6] Dump do banco '$DB_NAME'..."
# Escapa o valor para o formato de arquivo de opcoes do MySQL.
cnf_pass="$(printf '%s' "$DB_PASS" | sed 's/\\/\\\\/g; s/"/\\"/g')"
umask 077
printf '[client]\nuser="%s"\npassword="%s"\n' "$DB_USER" "$cnf_pass" > "$WORK/client.cnf"
chmod 600 "$WORK/client.cnf"
docker compose -f "$ROOT/docker-compose.yml" cp "$WORK/client.cnf" "db:$REMOTE_CNF"
docker compose -f "$ROOT/docker-compose.yml" exec -T db sh -c '
    set -u
    chmod 600 "$1"
    # --no-tablespaces evita o erro de PROCESS privilege com usuarios sem
    # esse privilegio (MySQL 8.x); tablespaces nao sao necessarios no restore.
    mysqldump --defaults-extra-file="$1" \
        --single-transaction --routines --triggers --events \
        --set-gtid-purged=OFF --no-tablespaces --hex-blob "$2"
    status=$?
    rm -f "$1"
    exit $status
' _ "$REMOTE_CNF" "$DB_NAME" > "$WORK/db.sql"
[[ -s "$WORK/db.sql" ]] || fail "dump do banco ficou vazio."
rm -f "$WORK/client.cnf"

echo "--> [2/6] Arquivando fotos de $FOTOS_DIR..."
tar --numeric-owner -czf "$WORK/fotos.tar.gz" -C "$FOTOS_DIR" .

echo "--> [3/6] Gerando manifesto..."
cat > "$WORK/MANIFEST.txt" <<EOF
SICAV - backup completo
gerado_em:   $(date -u '+%Y-%m-%dT%H%M:%SZ')
versao_app:  ${VISITORPASS_APP_VERSION:-desconhecida}
banco:       $DB_NAME
chave:       $TS
conteudo:    db.sql (dump --single-transaction) + fotos.tar.gz
EOF

ARTIFACTS=(db.sql fotos.tar.gz MANIFEST.txt)

if [[ "$ENCRYPT" -eq 1 ]]; then
    echo "--> [4/6] Criptografando com GPG (recipient: $BACKUP_GPG_RECIPIENT)..."
    FILES=()
    for f in "${ARTIFACTS[@]}"; do
        gpg --batch --yes --trust-model always \
            --encrypt --recipient "$BACKUP_GPG_RECIPIENT" \
            --output "$WORK/$f.gpg" "$WORK/$f"
        if command -v shred >/dev/null; then
            shred -u "$WORK/$f"
        else
            rm -f "$WORK/$f"
        fi
        FILES+=("$f.gpg")
    done
else
    warn "backup SEM CRIPTOGRAFIA — nao enviar para fora do host."
    FILES=("${ARTIFACTS[@]}")
fi

echo "--> [5/6] Checksums e arquivo final..."
(
    cd "$WORK"
    sha256sum "${FILES[@]}" > SHA256SUMS
)

ARCHIVE="sicav-backup-$TS.tar.gz"
ARCHIVE_PATH="$BACKUP_DIR/$ARCHIVE"
tar -czf "$ARCHIVE_PATH" -C "$WORK" "${FILES[@]}" SHA256SUMS
gzip -t "$ARCHIVE_PATH" || fail "arquivo final falhou no teste de integridade gzip."
(cd "$BACKUP_DIR" && sha256sum "$ARCHIVE" > "$ARCHIVE.sha256" && sha256sum -c "$ARCHIVE.sha256")

echo "--> [6/6] Retencao (manter $KEEP_DAYS dias) e envio remoto..."
find "$BACKUP_DIR" -maxdepth 1 -type f -name 'sicav-backup-*.tar.gz*' \
    -mtime "+$KEEP_DAYS" -print -delete

if [[ -n "${BACKUP_REMOTE:-}" ]]; then
    command -v rsync >/dev/null || fail "rsync nao encontrado para BACKUP_REMOTE."
    rsync -av --partial "$ARCHIVE_PATH" "$ARCHIVE_PATH.sha256" "$BACKUP_REMOTE/"
    echo "    Copia remota enviada para: $BACKUP_REMOTE"
fi

echo
echo "OK: backup concluido."
echo "    Arquivo: $ARCHIVE_PATH"
echo "    Tamanho: $(du -h "$ARCHIVE_PATH" | cut -f1)"
echo "    sha256:  $(cut -d' ' -f1 "$ARCHIVE_PATH.sha256")"
echo
echo "Restore (em host de teste):"
echo "    tar -xzf $ARCHIVE -C /tmp/sicav-restore"
echo "    # descripto: gpg --decrypt db.sql.gpg > db.sql  (se criptografado)"
