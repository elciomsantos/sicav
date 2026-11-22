<div align="center">
  <img src="/public/imagens/corporativo.png" alt="Logo" width="400">

  <h1>Sistema de Gerenciamento e Controle de Visitantes</h1>
</div>

O SICAV é um sistema desenvolvido para modernizar, organizar e aumentar a segurança no processo de gerenciamento e controle de visitantes em instituições, empresas e órgãos públicos.

A plataforma permite realizar o cadastro e a identificação de visitantes, registrar entradas e saídas, controlar o fluxo de pessoas e manter um histórico detalhado das visitas realizadas. Com uma interface intuitiva e recursos voltados à segurança da informação, o SICAV proporciona maior eficiência administrativa, rastreabilidade e confiabilidade no controle de acesso às dependências institucionais.

Sistema web para controle de entrada e saída de visitantes com geração de
crachás, relatórios e auditoria.

**Objetivo:** proporcionar um ambiente mais seguro, organizado e eficiente por meio da gestão informatizada de visitantes.

**Slogan:** Segurança, organização e controle de acesso.

## Sumário

- [Funcionalidades](#funcionalidades)
- [Arquitetura da stack Docker](#arquitetura-da-stack-docker)
- [Deploy com Docker](#deploy-com-docker)
  - [Desenvolvimento](#desenvolvimento)
  - [Produção (Ubuntu Server 26.04 LTS)](#produção-ubuntu-server-2604-lts)
  - [Primeiro acesso](#primeiro-acesso)
  - [Backup e restauração](#backup-e-restauração)
  - [Atualização da stack](#atualização-da-stack)
  - [Solução de problemas](#solução-de-problemas)
- [Fluxo de branches e releases](#fluxo-de-branches-e-releases)
- [Instalação local (sem Docker)](#instalação-local-sem-docker)
- [Variáveis de ambiente](#variáveis-de-ambiente)
- [Qualidade e CI](#qualidade-e-ci)
- [Histórico de segurança](#histórico-de-segurança)
- [Documentação](#documentação)
- [Estrutura do projeto](#estrutura-do-projeto)
- [Licença](#licença)

## Funcionalidades

### Gestão de visitantes

- Cadastro e identificação de visitantes, com foto via webcam e geração de crachá personalizado para impressão
- Registro de entrada e saída com data/hora
- Controle e acompanhamento de acessos, com consulta ao histórico de visitas
- Relatórios gerenciais por período, com filtros e exportação em Excel, CSV e PDF
- Auditoria de cadastros, edições e exclusões de visitantes, com exportação em CSV e JSON
- Armazenamento organizado das informações e rastreabilidade das movimentações

### Segurança e administração

- Níveis de acesso: admin, supervisor e user
- Administração de usuários, auditoria e desbloqueio de logins
- Sessão única por usuário, com timeout por inatividade e expiração absoluta
- Bloqueio automático após tentativas de login inválidas e rate limiting por rota
- Proteção CSRF, sanitização XSS, CSP com nonce e HSTS em HTTPS

## Arquitetura da stack Docker

```
navegador ──HTTP──▶ nginx ──▶ app (Apache + PHP 8.5) ──▶ db (MySQL 8.4)
                     :8000     rede frontend (interna)   rede backend
                     única porta                           (internal: true)
                     publicada
```

- **nginx** (`nginx:stable-alpine`, fixado por digest): reverse proxy e
  **única porta publicada** no host (`NGINX_PORT`, padrão 8000). Config em
  `deploy/nginx/default.conf`. Gera um `X-Request-ID` por requisição e o
  encaminha ao app; o log de acesso começa com esse ID.
- **app** (imagem construída do `Dockerfile`): Apache + PHP 8.5, roda como
  UID 1000 e não publica portas — acessado apenas pelo nginx na rede
  `frontend`. Container endurecido: `cap_drop: ALL`, `read_only` com tmpfs,
  `no-new-privileges` e limites de memória/CPU/pids. O `mod_remoteip`
  converte o `X-Forwarded-For` do nginx no IP real do cliente em
  `REMOTE_ADDR` (rate limiting e auditoria usam esse valor); a conf dos
  proxies internos é gerada por `docker/entrypoint.sh` no boot.
- **db** (imagem derivada de `mysql:8.4`, fixada por digest): o build atualiza
  os pacotes do Oracle Linux; o serviço fica apenas na rede `backend` marcada
  `internal: true` — sem porta no host e sem saída para a internet. Acesso
  administrativo apenas via `docker compose exec db mysql -u root -p`.
- **Dados persistentes**: bind mounts no drive dedicado `/mnt/visitantes`
  (`mysql/` para o banco, `fotos/` para as fotos). Não há volumes nomeados
  do Docker — backup é dump do banco + cópia dessas pastas.
- **Schema e migrações**: no boot do `app`, `docker/entrypoint.sh` executa
  `docker/migrate.php` (idempotente) — cria/atualiza o schema e, na primeira
  execução, os usuários padrão.

## Deploy com Docker

### Desenvolvimento

```bash
# Copie o arquivo de exemplo
cp .env.example.docker .env

# Inicie os containers (sem phpMyAdmin)
docker compose up -d

# Para incluir o phpMyAdmin (somente desenvolvimento):
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d

# Acesse:
# - App (via nginx): http://localhost:8000
# - phpMyAdmin (apenas via override de dev): http://localhost:8081
```

#### Setup automático (apenas desenvolvimento)

```bash
./scripts/setup_docker.sh            # cria .env, builda, sobe e valida
./scripts/setup_docker.sh --build    # força rebuild das imagens
./scripts/setup_docker.sh --reset    # recria containers (dados preservados)
```

O script gera o `.env` a partir de `.env.example.docker` forçando
`APP_ENV=development` e mantendo senhas de exemplo a trocar — **não use em
produção** (no servidor o exemplo vem com `APP_ENV=production` e o preflight
recusa placeholders). No servidor, siga o runbook abaixo com segredos fortes.

### Produção (Ubuntu Server 26.04 LTS)

#### 1. Instalar o Docker Engine (repositório oficial)

O Docker oferece suporte oficial ao Ubuntu 26.04 LTS. Não use o pacote `docker.io`
da distribuição (nem o snap), que podem conflitar com o repositório oficial:

```bash
# Remove pacotes conflitantes, se existirem
sudo apt remove $(dpkg --get-selections docker.io docker-compose docker-compose-v2 docker-doc docker-buildx podman-docker containerd runc | cut -f1) 2>/dev/null || true

# Adiciona a chave GPG e o repositório oficial do Docker
sudo apt update
sudo apt install -y ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
sudo tee /etc/apt/sources.list.d/docker.sources > /dev/null <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: $(. /etc/os-release && echo "${UBUNTU_CODENAME:-$VERSION_CODENAME}")
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF
sudo apt update

# Instala Docker Engine + plugin do Compose
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
```

#### 2. Montar o drive dedicado em /mnt/visitantes de forma persistente

O banco de dados e as fotos ficam em um drive separado, montado em
`/mnt/visitantes` via `/etc/fstab`, para continuar montado após reinicializações:

```bash
# Descubra o UUID do drive (ex.: /dev/sdb1)
sudo blkid /dev/sdb1

# Crie o ponto de montagem e adicione a entrada no /etc/fstab:
# UUID=<uuid-do-drive>  /mnt/visitantes  ext4  defaults,nofail  0  2
sudo mkdir -p /mnt/visitantes
sudo mount -a
findmnt /mnt/visitantes   # confirme que está montado
```

A opção `nofail` evita que o servidor trave no boot caso o drive não esteja
presente.

#### 3. Implantar o sistema em /opt

```bash
# Implante o projeto em /opt/sistema-visitantes
sudo mkdir -p /opt/sistema-visitantes
# (copie o projeto para /opt/sistema-visitantes)

# Crie as pastas de dados no drive dedicado (montado em /mnt/visitantes)
sudo mkdir -p /mnt/visitantes/mysql /mnt/visitantes/fotos
# MySQL 8.4 roda como UID 999 e o app como UID 1000:
sudo chown 999:999 /mnt/visitantes/mysql
sudo chown 1000:1000 /mnt/visitantes/fotos
# Ubuntu usa AppArmor (não SELinux): os bind mounts funcionam sem os sufixos :z/:Z.

# Configure variáveis seguras
vim .env
# Use senhas fortes para: VISITORPASS_DB_PASS, MYSQL_ROOT_PASSWORD
# Porta externa do sistema (troque se houver mais de um sistema no servidor):
#   NGINX_PORT=8000
# Prefixo de containers/redes (troque para cada sistema/instância):
#   COMPOSE_PROJECT_NAME=visitantes
# (Opcional: DB_DATA_DIR/FOTOS_DIR no .env mudam onde ficam os dados,
#  se o drive dedicado estiver montado em outro ponto.)

# Preflight: recusa senha placeholder, APP_ENV != production e drive desmontado
./scripts/preflight.sh

# Build e inicie (nginx e a unica porta publicada; app e MySQL nao)
./scripts/deploy.sh
```

Os dados persistentes (banco e fotos) ficam no drive dedicado em
`/mnt/visitantes` (padrão do `docker-compose.yml`).

> **Guarda de mount:** se o drive não estiver montado, o Docker cria
> `/mnt/visitantes` como pasta comum no disco do sistema e os dados caem no
> lugar errado. O `preflight.sh` aborta nesse caso. Para o boot automático,
> instale as unidades versionadas — o `AssertPathIsMountPoint` falha o serviço
> se o drive não estiver montado:
>
> ```bash
> sudo cp deploy/visitantes*.service deploy/visitantes-backup.timer /etc/systemd/system/
> sudo systemctl daemon-reload
> sudo systemctl enable --now visitantes.service visitantes-backup.timer
> systemctl status visitantes.service visitantes-backup.timer
> ```

#### 4. Acesso e firewall

O nginx roda como container do serviço `nginx` e é a única porta publicada no
host. Ele encaminha para o container `app` pela rede interna `frontend`:

```bash
# Libera a porta externa no firewall do host (padrão 8000)
sudo ufw allow 8000/tcp
sudo ufw enable    # se o firewall ainda não estiver ativo

# Confira os containers
docker compose ps
```

Acesse em `http://10.10.10.208:8000`.

> **HTTP sem TLS:** a stack serve HTTP puro, adequado para rede interna
> isolada. Cookies de sessão só recebem a flag `Secure` quando o acesso é
> via HTTPS — em HTTP ficam sem a flag. Mantenha a porta restrita à rede
> interna (firewall) e, se precisar expor o sistema fora da rede, coloque um
> reverse proxy com TLS à frente do nginx.

> Para hospedar **outro sistema no mesmo servidor**, use outro `NGINX_PORT`
> (ex.: 8001) e outro `COMPOSE_PROJECT_NAME` (ex.: `outro-sistema`) no `.env` do
> outro projeto. Cada stack tem seu próprio nginx e não depende do nginx do host.
> Não há `container_name` fixo: os containers/redes recebem o prefixo do projeto.
> Evite portas já em uso (`sudo ss -ltnp`).

> O nginx repassa `Host`, `X-Real-IP`, `X-Forwarded-For`, `X-Forwarded-Proto`
> e `X-Request-ID` ao PHP. O `X-Forwarded-For` só vira IP real do cliente
> porque o Apache declara as redes Docker como proxies internos
> (`RemoteIPInternalProxy`, conf em `/etc/visitantes/remoteip/remoteip.conf`
> dentro do container). O log de acesso do nginx começa com o `request_id`,
> o mesmo valor usado pelo evento de auditoria do PHP — dá para cruzar
> `docker compose logs nginx` com o `AUDIT` de `docker compose logs app`.

#### 5. Verificação pós-deploy

```bash
# Os três containers devem estar Up (db, app e nginx: healthy)
docker compose ps

# O app deve responder com redirect para o login (302)
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8000/

# Migração concluída no primeiro boot (procure no log do app)
docker compose logs app | grep -i migration
```

Em caso de problema, veja [Solução de problemas](#solução-de-problemas).

### Primeiro acesso

Na primeira execução, `docker/migrate.php` (rodado automaticamente no boot do
app) cria o schema e o usuário padrão com **senha temporária** — o login força
a troca de senha no primeiro acesso:

| Ambiente | Usuário | Senha inicial | Nível |
|----------|---------|---------------|-------|
| Produção (`APP_ENV=production`) | `admin` | `Admin@2026Temp!` | admin |
| Desenvolvimento (`APP_ENV=development`) | `admin` | `Admin@2026Temp!` | admin |
| Desenvolvimento (`APP_ENV=development`) | `supervisor` | `Supervisor@2026Temp!` | supervisor |

Em **produção apenas o `admin` é criado**. O supervisor (e os demais usuários)
deve ser criado depois pelo próprio admin em **Administração → Listar Usuários**.
O `supervisor` padrão só existe em desenvolvimento, para facilitar os testes
de níveis de acesso.

**Altere a senha imediatamente no primeiro login** — o sistema redireciona
para a tela de troca de senha até que a troca seja feita.

#### Alternativa: criar seu usuário e desativar o padrão

Se preferir não usar credenciais padrão versionadas, crie seu usuário direto
no banco e desative o padrão:

```bash
# Gere o hash Argon2id da sua senha
docker compose exec app php -r 'echo password_hash("SuaSenhaForte!", PASSWORD_ARGON2ID), PHP_EOL;'

# Abra o MySQL (o banco não publica porta no host)
docker compose exec db mysql -u root -p visitorpass
```

```sql
INSERT INTO usuarios (nome, usuario, senha, nivel_acesso, ativo, senha_temporaria)
VALUES ('Seu Nome', 'seu.usuario', '<hash-gerado>', 'admin', 1, 0);

-- Desativa o usuário padrão (mantido no banco para histórico/auditoria).
-- Em desenvolvimento, o supervisor padrão também existe.
UPDATE usuarios SET ativo = 0 WHERE usuario IN ('admin', 'supervisor');
```

### Backup e restauração

O banco fica em `/mnt/visitantes/mysql` e as fotos em
`/mnt/visitantes/fotos` (drive dedicado). Guarde os backups **fora** desse
drive (outro disco/servidor) — perder o drive não pode custar o banco **e** as
fotos.

`scripts/backup.sh` faz dump consistente (`--single-transaction`), empacota as
fotos, gera `SHA256SUMS`, criptografa com GPG (há CPF/telefone/e-mail/fotos no
conteúdo) e aplica retenção. A senha do banco trafega por arquivo de opções
`600`, nunca em argumento de linha de comando.

```bash
# 1. Importe a chave pública do destinatário no keyring de quem roda o timer
gpg --import chave-publica-backup.asc

# 2. Configure no .env: BACKUP_GPG_RECIPIENT, BACKUP_DIR e (recomendado)
#    BACKUP_REMOTE para outro host. BACKUP_KEEP_DAYS define a retenção.

# 3. Teste manualmente
./scripts/backup.sh

# Agendamento (já instalado com as unidades da seção anterior):
systemctl status visitantes-backup.timer
systemctl list-timers visitantes-backup.timer
```

Cada execução gera `sicav-backup-<timestamp>.tar.gz` (contém `db.sql.gpg`,
`fotos.tar.gz.gpg`, `MANIFEST.txt.gpg` e `SHA256SUMS`) e o `.sha256` do
arquivo. O timer roda diariamente às 03:17 com `Persistent=true` (se o host
estiver desligado, o backup roda no próximo boot).

> **`BACKUP_DIR` continua no mesmo drive por padrão** — isso protege contra
> erro humano, não contra falha do disco. Configure `BACKUP_REMOTE` (rsync para
> outro host) para ter uma cópia realmente separada. O script recusa enviar
> para o remoto sem criptografia.

#### Restauração

Em um host novo (após os passos 1-3 do runbook, com o drive preparado e o
`.env` configurado):

```bash
set -a; . ./.env; set +a   # exporta MYSQL_ROOT_PASSWORD / VISITORPASS_DB_NAME

# 1. Extraia e verifique o backup (fora do drive de dados)
mkdir -p /tmp/sicav-restore
tar -xzf sicav-backup-<timestamp>.tar.gz -C /tmp/sicav-restore
(cd /tmp/sicav-restore && sha256sum -c SHA256SUMS)

# 2. Descripto (pule se o backup foi feito com --no-encrypt)
gpg --decrypt /tmp/sicav-restore/db.sql.gpg > /tmp/sicav-restore/db.sql
gpg --decrypt /tmp/sicav-restore/fotos.tar.gz.gpg > /tmp/sicav-restore/fotos.tar.gz

# 3. Suba a stack — o migrate cria o schema vazio
./scripts/deploy.sh

# 4. Restaure o dump por cima (senha via MYSQL_PWD, fora de argv)
docker compose exec -T -e MYSQL_PWD="$MYSQL_ROOT_PASSWORD" db \
  mysql -u root "$VISITORPASS_DB_NAME" < /tmp/sicav-restore/db.sql

# 5. Restaure as fotos para o drive dedicado (confira o destino antes!)
sudo rm -rf /mnt/visitantes/fotos/*
sudo tar -xzf /tmp/sicav-restore/fotos.tar.gz -C /mnt/visitantes/fotos
sudo chown -R 1000:1000 /mnt/visitantes/fotos
```

> Antes do go-live, **execute esse restore em um host de teste** e registre o
> RTO/RPO obtidos. Um backup sem restore validado não é backup.

### Atualização da stack

```bash
cd /opt/sistema-visitantes

# 1. Faça backup (seção acima)

# 2. Atualize o código na branch main
git switch main
git pull --ff-only origin main

# 3. Rebuild e recrie os containers (com preflight + espera dos healthchecks)
./scripts/deploy.sh

# 4. Migrações novas (se houver) rodam automaticamente no boot do app —
#    docker/migrate.php é idempotente e pode rodar várias vezes com segurança

# 5. Registre a versão publicada (tag vX.Y.Z no GitHub)
./scripts/release.sh
```

Os dados no drive dedicado (`/mnt/visitantes`) não são tocados pelo rebuild.
O deploy é feito **somente a partir da `main`**. O fluxo completo de branches,
PRs e versionamento está em [docs/operacao/FLUXO-GIT.md](docs/operacao/FLUXO-GIT.md).

### Solução de problemas

| Sintoma | Causa provável | Diagnóstico / correção |
|---------|----------------|--------------------------|
| Dados apareceram no disco do sistema em vez do drive dedicado | O drive não estava montado quando a stack subiu — o Docker criou `/mnt/visitantes` como pasta comum | `findmnt /mnt/visitantes`; `docker compose down`, mova os dados, monte o drive e suba novamente |
| `db` nunca fica `healthy` | Permissões erradas na pasta do banco | `ls -ln /mnt/visitantes/mysql` → dono deve ser `999:999` (`sudo chown -R 999:999 /mnt/visitantes/mysql`) |
| Upload de foto falha / erro de escrita | Permissões erradas na pasta de fotos | `ls -ln /mnt/visitantes/fotos` → dono deve ser `1000:1000` |
| `bind: address already in use` na subida | Outro serviço usando a porta | `sudo ss -ltnp \| grep 8000`; troque `NGINX_PORT` no `.env` |
| nginx responde `502 Bad Gateway` | Container `app` parado ou reiniciando em loop | `docker compose ps`, `docker compose logs app` |
| Login falha para usuário válido | Banco restaurado sem dados / schema ausente | `docker compose logs app` (procure `Migration completed`); confira com `docker compose exec db mysql -u root -p visitorpass` |
| Rate limiting bloqueia todo mundo / auditoria sempre com o mesmo IP | O `mod_remoteip` não carregou a conf (tmpfs ausente no `app`) | `docker compose exec app cat /etc/visitantes/remoteip/remoteip.conf`; se o arquivo não existir, confira o `tmpfs` do serviço `app` no compose e o `IncludeOptional` em `docker/apache.conf` |
| `request_id` do nginx não bate com o do `AUDIT` do PHP | Requisição não passou pelo proxy (acesso direto ao app) | Compare as duas linhas pelo horário; o app só converge o `X-Request-ID` quando a requisição entra pelo nginx |

Logs de qualquer serviço: `docker compose logs [-f] [app\|nginx\|db]`.

## Fluxo de branches e releases

- `main`: código validado e em produção. É a única origem de deploy.
- `fix/dev-development`: integração de correções e melhorias. Recebe PRs das
  branches de trabalho e é promovida para `main` por PR após validação.
- Branches temporárias (`fix/*`, `feat/*`, `chore/*`, `docs/*`): saem de
  `fix/dev-development` e são excluídas automaticamente após o merge.

```text
fix/<assunto> ──PR──▶ fix/dev-development ──PR──▶ main ──▶ deploy ──▶ tag vX.Y.Z
```

Cada versão publicada em produção recebe uma tag SemVer. Depois do deploy e da
verificação, execute na `main`:

```bash
./scripts/release.sh          # v1.0.0 -> v1.0.1 (patch)
./scripts/release.sh minor    # v1.0.0 -> v1.1.0
./scripts/release.sh major    # v1.0.0 -> v2.0.0
./scripts/release.sh 1.2.3    # versão explícita
```

O workflow `release.yml` cria a release no GitHub para cada tag `v*.*.*`.
Detalhes e comandos de PR estão em [docs/operacao/FLUXO-GIT.md](docs/operacao/FLUXO-GIT.md).

## Instalação local (sem Docker)

Requisitos: PHP 8.4+ (a imagem Docker usa PHP 8.5), MySQL 8.4+, extensões PHP
PDO_MySQL, GD, ZIP e JSON, Apache com mod_rewrite e a pasta
`fotos_visitantes/` na raiz do projeto.

```bash
# Clone o repositório
git clone https://github.com/elciomsantos/sicav.git
cd sicav

# Configure o ambiente
cp .env.example .env
# Edite .env com suas credenciais do banco

# Instale dependências
composer install --no-dev

# Crie o schema e o usuário admin padrão (o script cria o banco se não existir).
# Ajuste as variáveis para o seu MySQL local:
VISITORPASS_DB_HOST=127.0.0.1 \
VISITORPASS_DB_PORT=3306 \
VISITORPASS_DB_NAME=visitorpass \
VISITORPASS_DB_USER=root \
VISITORPASS_DB_PASS='sua_senha' \
VISITORPASS_ENV=development \
php docker/migrate.php

# Configure o VirtualHost para apontar para /public
```

## Variáveis de ambiente

Variáveis lidas pelo `docker-compose.yml` a partir do `.env` (não commitado):

| Variável | Default | Descrição |
|----------|---------|-----------|
| `NGINX_PORT` | `8000` | Porta externa (única) publicada no host |
| `APP_ENV` | `production` | `production` = instala apenas dependências de produção e remove `.env`, `node_modules` e arquivos `.md` da imagem; `development` = instala dependências de dev e executa PHPUnit + checagem de sintaxe JS durante o build |
| `COMPOSE_PROJECT_NAME` | nome da pasta | Prefixo de containers/redes — use valores distintos para cada sistema no mesmo servidor |
| `VISITORPASS_DB_NAME` | `visitorpass` | Nome do banco |
| `VISITORPASS_DB_USER` | `vpuser` | Usuário da aplicação |
| `VISITORPASS_DB_PASS` | — | **Obrigatória em produção**: senha da aplicação |
| `MYSQL_ROOT_PASSWORD` | — | **Obrigatória em produção**: senha root do MySQL |
| `DB_DATA_DIR` | `/mnt/visitantes/mysql` | Pasta dos dados do MySQL (drive dedicado) |
| `FOTOS_DIR` | `/mnt/visitantes/fotos` | Pasta das fotos dos visitantes (drive dedicado) |
| `VISITORPASS_SESSION_TIMEOUT` | `3600` | Timeout de inatividade da sessão (segundos) |
| `VISITORPASS_SESSION_ABSOLUTE_LIFETIME` | `43200` | Vida máxima absoluta da sessão (segundos) |
| `PMA_PORT` | `8081` | Porta do phpMyAdmin — **apenas no override de desenvolvimento** |

Sem `VISITORPASS_DB_PASS` e `MYSQL_ROOT_PASSWORD` o `docker compose` **falha
de propósito** (`${VAR:?...}`): não existe mais fallback de credencial de
desenvolvimento no compose. Em desenvolvimento, `.env.example.docker` já traz
valores de exemplo para as duas.

As imagens (`php`, `composer`, `nginx`, `mysql`, `phpmyadmin`) são fixadas por
digest `sha256` — a tag pode ser movida silenciosamente pelo registro, e o
digest garante que o build use sempre o artefato testado. Para atualizar:

```bash
./scripts/pin-images.sh          # mostra o que mudaria, sem alterar
./scripts/pin-images.sh --apply  # aplica nos arquivos
```

O Dependabot também abre PR de atualização da imagem do Compose.

## Qualidade e CI

Os checks rodam no GitHub Actions a cada push em `main` e em
`fix/dev-development`, além de todos os pull requests:

| Workflow | O que verifica |
|----------|----------------|
| `tests.yml` — PHPUnit | Suíte PHP em PHP 8.4 e 8.5, além de `composer validate`, `composer audit` e `php -l` em todos os arquivos |
| `tests.yml` — PHPStan | Análise estática nível 5 (`phpstan.neon`) |
| `tests.yml` — Frontend | Sintaxe dos JS (Node 22) e smoke test HTML |
| `ci.yml` — Compose | `docker compose config` de produção (dados em `/mnt/visitantes`) e do override de desenvolvimento |
| `ci.yml` — Lint | ShellCheck em `scripts/*.sh`/`docker/*.sh` e Hadolint no `Dockerfile` |
| `ci.yml` — Cobertura | PHPUnit com PCOV e upload do `clover.xml` como artifact |
| `ci.yml` — Smoke | Build e subida de `db + app + nginx` (dados em dirs temporários do runner), espera o banco ficar `healthy`, valida HTTP na porta 8000 e confere que o `X-Request-ID` do nginx aparece no evento de auditoria do PHP com o mesmo IP de cliente |
| `ci.yml` — Scan (Trivy) | Scanner de vulnerabilidades nas imagens de produção (`app`, `nginx` e MySQL atualizado no build), falhando em `HIGH`/`CRITICAL` (`--ignore-unfixed`) |
| `codeql.yml` | Code scanning: CodeQL para JavaScript/TypeScript e GitHub Actions; PHPStan (SARIF) e Psalm taint analysis (SARIF) para PHP — o CodeQL não oferece mais análise de PHP |
| `release.yml` | Cria a release no GitHub para cada tag `v*.*.*` |
| `dependency-review.yml` | Revisão de dependências em PRs (falha em severidade `high`) |
| Dependabot | PRs semanais para Composer, GitHub Actions, imagem Docker e Compose (majors do MySQL são ignorados para manter a linha LTS 8.4) |

Rodando os checks principais localmente (requer Docker e `composer install`):

```bash
# Testes PHP e análise estática
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.5-cli \
  php vendor/bin/phpunit
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.5-cli \
  php vendor/bin/phpstan analyse --no-progress --memory-limit=1G

# Sintaxe dos JS
for f in public/assets/js/*.js; do node --check "$f"; done

# Lint de infraestrutura
docker run --rm -v "$PWD:/mnt" -w /mnt koalaman/shellcheck:stable \
  scripts/*.sh docker/*.sh
docker run --rm -i -v "$PWD/.hadolint.yaml:/hadolint.yaml:ro" \
  --entrypoint hadolint hadolint/hadolint:2.12.0 \
  --config /hadolint.yaml - < Dockerfile

# Validação do Compose
docker compose config --quiet
docker compose -f docker-compose.yml -f docker-compose.dev.yml config --quiet
```

> O repositório é público. Code scanning (CodeQL/PHPStan/Psalm) e Dependency
> Review executam sem necessidade de GitHub Advanced Security.

## Histórico de segurança

### Remoção do MFA (2026-08-20)

A autenticação em duas etapas (MFA/TOTP) foi removida do sistema:

- **scripts/login_submit.php** - Removida verificação `mfaIsEnabled()` após login bem-sucedido
- **public/index.php** - Removidas rotas `mfa_challenge` e `admin_mfa`
- **inc/navbar.php** - Removido item "Verificação em Duas Etapas" do menu do usuário
- **.env** - Variável `VISITORPASS_MFA_KEY` removida
- **docker-compose.yml / docker-compose.dev.yml** - Variável removida

O login agora redireciona diretamente para a página inicial após autenticação válida. As tabelas `usuarios_mfa` e `mfa_recovery_codes` não são mais criadas em bancos novos; bancos antigos podem mantê-las (sem uso).

### Correção de XSS refletido (2026-11, PR #9)

Adicionado Psalm taint analysis (SAST) ao CI, que apontou um XSS refletido nos
filtros do relatório de visitas; a saída passou a ser escapada no servidor.

### Endurecimento de proxy, imagens e credenciais (2026-11-13)

Cinco pendências de infraestrutura documentadas em
[docs/seguranca/PLANO_IMPLEMENTACAO.md](docs/seguranca/PLANO_IMPLEMENTACAO.md) foram fechadas:

- **IP real do cliente atrás do nginx:** `mod_remoteip` habilitado no
  `Dockerfile`, com `RemoteIPHeader X-Forwarded-For` e
  `RemoteIPInternalProxy` apontando para as redes Docker — que o
  `docker/entrypoint.sh` detecta em `/proc/net/route` no boot e grava no
  tmpfs `/etc/visitantes/remoteip/`. Antes o rate limiting e a auditoria
  viam todos os clientes como um só (o IP do container nginx). `getClientIp()`
  passou a ler apenas `REMOTE_ADDR` — header de proxy vindo do cliente nunca é
  usado para decidir o IP.
- **`X-Request-ID` de ponta a ponta:** o nginx passa a gerar o ID
  (`$request_id`) e encaminhá-lo ao PHP; `getRequestId()` reutiliza o valor
  (validado por padrão) em vez de gerar outro localmente. O log de acesso do
  nginx começa com o ID, cruzando com o `AUDIT` do PHP.
- **Imagens fixadas por digest:** `php`, `composer`, `nginx`, `mysql` e
  `phpmyadmin` saíram de tags soltas para `imagem:tag@sha256:...`. A manutenção
  fica em `./scripts/pin-images.sh`.
- **Credenciais obrigatórias:** `VISITORPASS_DB_PASS` e `MYSQL_ROOT_PASSWORD`
  não têm mais fallback no `docker-compose.yml` — sem eles o `docker compose`
  interrompe com mensagem explícita (inclusive no `healthcheck` do banco).
- **Scanner de imagem no CI:** job Trivy em `ci.yml` falha em vulnerabilidades
  `HIGH`/`CRITICAL` (`--ignore-unfixed`) para a imagem do app e para as
  imagens base. O smoke teste da stack ainda passou a validar a propagação do
  `request_id` e a igualdade do IP entre o que o nginx vê e o que o PHP
  registra.

## Documentação

A documentação detalhada está indexada em [docs/README.md](docs/README.md):

- [Análise de prontidão para produção](docs/auditoria/ANALISE-PRONTO-PRODUCAO.md) e
  [registro de achados](docs/auditoria/ACHADOS-PRODUCAO.md)
- [Especificação de segurança](docs/seguranca/ESPECIFICACAO-SEGURANCA.md) e
  [plano de implementação](docs/seguranca/PLANO_IMPLEMENTACAO.md)
- [Fluxo de branches, PRs e releases](docs/operacao/FLUXO-GIT.md)

## Estrutura do projeto

```
sicav/
├── .github/          # Workflows (CI, testes, CodeQL, release, dependency review) e Dependabot
├── api/              # Endpoints REST
├── config/           # Configurações do app, banco e permissões
├── deploy/           # Configs de infraestrutura (nginx container)
├── docker/           # Entrypoint, Apache/PHP ini e migrações do container
├── docs/             # Documentação (índice, auditoria, segurança e operação)
│   ├── README.md     # Índice da documentação
│   ├── auditoria/    # Prontidão, achados e protocolo de auditoria
│   ├── seguranca/    # Especificação e plano de implementação
│   └── operacao/     # Fluxo de Git, PRs e releases
├── fotos_visitantes/ # Fotos dos visitantes (bind mount do drive dedicado em produção)
├── inc/              # Helpers, segurança, sessão, rate limit e includes
├── public/           # DocumentRoot (index.php, assets e imagens)
├── scripts/          # Controllers, CRUD, setup e release
├── src/              # Classes (Router, Database, Middleware)
├── tests/            # Testes PHPUnit e smoke test HTML
├── vendor/           # Dependências Composer (gerado, não versionado)
├── Dockerfile        # Build da imagem PHP 8.5
├── docker-compose.yml
├── docker-compose.dev.yml  # Override de desenvolvimento (phpMyAdmin)
├── composer.json / composer.lock
├── phpstan.neon      # Configuração do PHPStan
├── psalm.xml         # Configuração do Psalm (taint analysis)
├── phpunit.xml       # Configuração do PHPUnit
├── .env.example      # Variáveis para execução local
└── .env.example.docker # Variáveis para Docker Compose
```

## Licença

Uso interno.
