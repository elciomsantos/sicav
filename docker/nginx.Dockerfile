# Reverse proxy baseado na imagem oficial do nginx, fixada por digest.
#
# O "apk upgrade" aplica as correcoes de seguranca publicadas no Alpine depois
# do build oficial da imagem (ex.: libxslt 1.1.43-r3 -> 1.1.43-r4). Sem isso o
# Trivy acusa a versao antiga mesmo com o digest mais recente da tag.
#
# Para atualizar o digest: ./scripts/pin-images.sh --apply
ARG NGINX_IMAGE=nginx:stable-alpine@sha256:3bc3836dc0be3b811f238210360bf8e7ca8a2d0effdae46160bf1f3578710051

FROM ${NGINX_IMAGE}

# hadolint ignore=DL3018
RUN apk upgrade --no-cache
