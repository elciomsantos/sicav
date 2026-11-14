# Reverse proxy baseado na imagem oficial do nginx, fixada por digest.
#
# O "apk upgrade" aplica as correcoes de seguranca publicadas no Alpine depois
# do build oficial da imagem (ex.: libxslt 1.1.43-r3 -> 1.1.43-r4). Sem isso o
# Trivy acusa a versao antiga mesmo com o digest mais recente da tag.
#
# Para atualizar o digest: ./scripts/pin-images.sh --apply
ARG NGINX_IMAGE=nginx:stable-alpine@sha256:901e593c95dc85f5a729f5765516321366ef4ac401821431d408897e95287d0a

FROM ${NGINX_IMAGE}

# hadolint ignore=DL3018
RUN apk upgrade --no-cache
