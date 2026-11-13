#!/usr/bin/env bash
#
# Cria e publica a tag de versao da release que esta em producao.
# Execute na branch main, depois do deploy e da verificacao pos-deploy.
#
# Uso:
#   ./scripts/release.sh            # incrementa o patch (v1.0.0 -> v1.0.1)
#   ./scripts/release.sh minor      # incrementa a minor (v1.0.0 -> v1.1.0)
#   ./scripts/release.sh major      # incrementa a major (v1.0.0 -> v2.0.0)
#   ./scripts/release.sh 1.2.3      # versao explicita (tag v1.2.3)
#

set -euo pipefail

cd "$(dirname "$0")/.."

usage() {
    cat <<'EOF'
Uso: scripts/release.sh [patch|minor|major|X.Y.Z]

Sem argumento, incrementa o patch da ultima tag (ex.: v1.0.0 -> v1.0.1).
Tambem aceita uma versao explicita, com ou sem o prefixo "v".
EOF
}

if [ "$#" -gt 1 ]; then
    usage >&2
    exit 1
fi

bump="${1:-patch}"

branch="$(git rev-parse --abbrev-ref HEAD)"
if [ "$branch" != "main" ]; then
    echo "ERRO: releases so podem ser criados a partir da branch main (atual: $branch)." >&2
    exit 1
fi

if [ -n "$(git status --porcelain)" ]; then
    echo "ERRO: existem alteracoes locais nao commitadas." >&2
    echo "      Faca commit ou descarte antes de criar a tag." >&2
    exit 1
fi

git fetch --tags --prune origin

if [ "$(git rev-parse HEAD)" != "$(git rev-parse origin/main)" ]; then
    echo "ERRO: a main local nao esta igual a origin/main." >&2
    echo "      Execute: git pull --ff-only origin main" >&2
    exit 1
fi

latest="$(git describe --tags --abbrev=0 --match 'v[0-9]*' 2>/dev/null || true)"

if printf '%s' "$bump" | grep -Eq '^v?[0-9]+\.[0-9]+\.[0-9]+$'; then
    version="${bump#v}"
else
    case "$bump" in
        patch|minor|major) ;;
        *)
            echo "ERRO: argumento invalido: $bump" >&2
            usage >&2
            exit 1
            ;;
    esac

    if [ -z "$latest" ]; then
        version="1.0.0"
    else
        base="${latest#v}"
        IFS=. read -r major minor patch <<< "$base"
        case "$bump" in
            major)
                major=$((major + 1))
                minor=0
                patch=0
                ;;
            minor)
                minor=$((minor + 1))
                patch=0
                ;;
            patch)
                patch=$((patch + 1))
                ;;
        esac
        version="$major.$minor.$patch"
    fi
fi

tag="v$version"

if git rev-parse -q --verify "refs/tags/$tag" >/dev/null; then
    echo "ERRO: a tag $tag ja existe." >&2
    exit 1
fi

echo "--> Criando tag $tag em $(git rev-parse --short HEAD) (branch main)..."
git tag -a "$tag" -m "Release $tag"

echo "--> Publicando tag no origin..."
git push origin "$tag"

echo "OK: release $tag publicada."
