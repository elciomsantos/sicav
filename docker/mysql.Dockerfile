# Imagem oficial fixada por digest; atualiza pacotes do Oracle Linux no build.
ARG MYSQL_IMAGE=mysql:8.4@sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902

FROM ${MYSQL_IMAGE}

RUN microdnf upgrade --refresh --assumeyes && microdnf clean all
