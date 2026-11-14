#!/bin/bash
# Atualiza os digests (sha256) fixados no Dockerfile, no docker-compose.yml e no
# docker-compose.dev.yml.
#
# Por que fixar digest: a tag pode ser movida silenciosamente pelo registro e
# o "docker compose up" puxaria outra imagem sem qualquer aviso. Com o digest,
# o build e a stack sempre usam exatamente o artefato testado, e o scanner de
# vulnerabilidades do CI (Trivy) avalia sempre a mesma imagem.
#
# Uso:
#   ./scripts/pin-images.sh          # mostra o que mudaria, sem alterar
#   ./scripts/pin-images.sh --apply  # aplica nos arquivos
#
# Requer docker com buildx ("docker buildx imagetools").

set -eu

APPLY=0
case "${1:-}" in
    '') ;;
    --apply) APPLY=1 ;;
    -h|--help)
        echo "uso: $0 [--apply]"
        exit 0
        ;;
    *)
        echo "uso: $0 [--apply]" >&2
        exit 1
        ;;
esac

command -v docker >/dev/null || { echo "erro: docker nao encontrado no PATH" >&2; exit 1; }

# imagem -> arquivo onde ela e referenciada
IMAGENS=(
    "php:8.5-apache Dockerfile"
    "composer:2 Dockerfile"
    "nginx:stable-alpine docker-compose.yml"
    "mysql:8.4 docker-compose.yml"
    "phpmyadmin:5.2 docker-compose.dev.yml"
)

changed=0
for entrada in "${IMAGENS[@]}"; do
    read -r image file <<< "$entrada"
    escaped=${image//./\\.}

    digest=$(docker buildx imagetools inspect "$image" --format '{{json .Manifest.Digest}}' | tr -d '"')
    if [ -z "$digest" ]; then
        echo "AVISO: nao foi possivel obter o digest de $image" >&2
        continue
    fi

    previous=$(grep -oE "${escaped}@sha256:[0-9a-f]{64}" "$file" | head -1 || true)

    # Aceita imagem com ou sem digest e reescreve sempre com o digest atual.
    tmp=$(mktemp)
    sed -E \
        "s|^([[:space:]]*(image:[[:space:]]*)?${escaped})[[:space:]]*(@sha256:[0-9a-f]{64})?|\1@${digest}|g" \
        "$file" > "$tmp"

    if diff -q "$file" "$tmp" >/dev/null; then
        echo "ok        $image = ${digest}"
        rm -f "$tmp"
        continue
    fi

    changed=$((changed + 1))
    echo "atualiza  $file: ${previous:-<sem digest>} -> ${digest}"
    if [ "$APPLY" -eq 1 ]; then
        cat "$tmp" > "$file"
        echo "         aplicado"
    else
        echo "         previa:"
        diff -u "$file" "$tmp" | sed 's/^/         /' || true
    fi
    rm -f "$tmp"
done

if [ "$changed" -gt 0 ] && [ "$APPLY" -eq 0 ]; then
    echo
    echo "$changed imagem(ns) desatualizada(s); rode novamente com --apply."
fi