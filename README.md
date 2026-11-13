# Sistema de Visitantes v1.0

Sistema web para controle de entrada e saída de visitantes com geração de
crachás, relatórios e auditoria.

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
- [Estrutura do projeto](#estrutura-do-projeto)
- [Licença](#licença)

## Funcionalidades

- Cadastro de visitantes com foto via webcam
- Geração de crachá personalizado (impressão)
- Controle de entrada/saída com registro de data/hora
- Relatórios por período com gráficos
- Auditoria de todas as alterações
- Níveis de acesso: admin, supervisor, user
- Bloqueio automático após tentativas de login inválidas
- Proteção CSRF e sanitização XSS
- Sessões seguras com timeout configurável

## Arquitetura da stack Docker

```
navegador ──HTTP──▶ nginx ──▶ app (Apache + PHP 8.4) ──▶ db (MySQL 8.4)
                     :8000     rede frontend (interna)   rede backend
                     única porta                           (internal: true)
                     publicada
```

- **nginx** (`nginx:stable-alpine`): reverse proxy e **única porta publicada**
  no host (`NGINX_PORT`, padrão 8000). Config em `deploy/nginx/default.conf`.
- **app** (imagem construída do `Dockerfile`): Apache + PHP 8.4, roda como
  UID 1000 e não publica portas — acessado apenas pelo nginx na rede
  `frontend`. Container endurecido: `cap_drop: ALL`, `read_only` com tmpfs,
  `no-new-privileges` e limites de memória/CPU/pids.
- **db** (`mysql:8.4`): fica apenas na rede `backend` marcada `internal: true`
  — sem porta no host e sem saída para a internet. Acesso administrativo
  apenas via `docker compose exec db mysql -u root -p`.
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

O script gera o `.env` a partir de `.env.example.docker` (com
`APP_ENV=development` e senhas fracas de exemplo) — **não use em
produção**. No servidor, siga o runbook abaixo com segredos fortes.

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

# Build e inicie (nginx e a unica porta publicada; app e MySQL nao)
cd /opt/sistema-visitantes
docker compose up -d --build
```

Os dados persistentes (banco e fotos) ficam no drive dedicado em
`/mnt/visitantes` (padrão do `docker-compose.yml`).

> Se o drive não estiver montado, o Docker cria `/mnt/visitantes` como pasta
> comum no disco do sistema e os dados caem no lugar errado — confira com
> `findmnt /mnt/visitantes` antes de subir a stack.

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

Acesse em `http://<IP-do-servidor>:8000`.

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

> O nginx repassa `Host`, `X-Real-IP`, `X-Forwarded-For` e `X-Forwarded-Proto`
> ao PHP. Logs de acesso/erro aparecem em `docker compose logs nginx`.

#### 5. Verificação pós-deploy

```bash
# Os três containers devem estar Up (db: healthy)
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
# Gere o hash bcrypt da sua senha
docker compose exec app php -r 'echo password_hash("SuaSenhaForte!", PASSWORD_BCRYPT), PHP_EOL;'

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
drive (outro disco/servidor).

```bash
# Banco: dump consistente com a stack rodando (sem downtime)
docker compose exec db sh -c \
  'mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers visitorpass' \
  > backup-visitorpass-$(date +%F).sql

# Fotos: cópia do conteúdo do drive dedicado
sudo rsync -a /mnt/visitantes/fotos/ /outro-disco/backups/fotos/
```

Exemplo de dump diário no crontab (`crontab -e`), às 2h da manhã:

```cron
0 2 * * * cd /opt/sistema-visitantes && docker compose exec db sh -c 'mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers visitorpass' | gzip > /outro-disco/backups/visitorpass-$(date +\%F).sql.gz
```

#### Restauração

Em um host novo (após os passos 1-3 do runbook, com o drive preparado e o
`.env` configurado):

```bash
# 1. Suba a stack — o migrate cria o schema vazio
docker compose up -d --build

# 2. Restaure o dump por cima
docker compose exec -T db sh -c \
  'mysql -u root -p"$MYSQL_ROOT_PASSWORD" visitorpass' < backup-visitorpass.sql

# 3. Restaure as fotos para o drive dedicado
sudo rsync -a backup-fotos/ /mnt/visitantes/fotos/
```

### Atualização da stack

```bash
cd /opt/sistema-visitantes

# 1. Faça backup (seção acima)

# 2. Atualize o código
sudo git pull --ff-only

# 3. Rebuild e recrie os containers
docker compose up -d --build

# 4. Migrações novas (se houver) rodam automaticamente no boot do app —
#    docker/migrate.php é idempotente e pode rodar várias vezes com segurança

# 5. Registre a versão publicada (tag vX.Y.Z no GitHub)
./scripts/release.sh
```

Os dados no drive dedicado (`/mnt/visitantes`) não são tocados pelo rebuild.
O deploy é feito **somente a partir da `main`**. O fluxo completo de branches,
PRs e versionamento está em [docs/FLUXO-GIT.md](docs/FLUXO-GIT.md).

### Solução de problemas

| Sintoma | Causa provável | Diagnóstico / correção |
|---------|----------------|--------------------------|
| Dados apareceram no disco do sistema em vez do drive dedicado | O drive não estava montado quando a stack subiu — o Docker criou `/mnt/visitantes` como pasta comum | `findmnt /mnt/visitantes`; `docker compose down`, mova os dados, monte o drive e suba novamente |
| `db` nunca fica `healthy` | Permissões erradas na pasta do banco | `ls -ln /mnt/visitantes/mysql` → dono deve ser `999:999` (`sudo chown -R 999:999 /mnt/visitantes/mysql`) |
| Upload de foto falha / erro de escrita | Permissões erradas na pasta de fotos | `ls -ln /mnt/visitantes/fotos` → dono deve ser `1000:1000` |
| `bind: address already in use` na subida | Outro serviço usando a porta | `sudo ss -ltnp \| grep 8000`; troque `NGINX_PORT` no `.env` |
| nginx responde `502 Bad Gateway` | Container `app` parado ou reiniciando em loop | `docker compose ps`, `docker compose logs app` |
| Login falha para usuário válido | Banco restaurado sem dados / schema ausente | `docker compose logs app` (procure `Migration completed`); confira com `docker compose exec db mysql -u root -p visitorpass` |

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
Detalhes e comandos de PR estão em [docs/FLUXO-GIT.md](docs/FLUXO-GIT.md).

## Instalação local (sem Docker)

Requisitos: PHP 8.4+, MySQL 8.4+, extensões PHP PDO_MySQL, GD, ZIP e JSON,
Apache com mod_rewrite e a pasta `fotos_visitantes/` na raiz do projeto.

```bash
# Clone o repositório
git clone https://github.com/elciomsantos/sistema-visitantes-v1.0.git
cd sistema-visitantes-v1.0

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
| `APP_ENV` | `production` | `production` = `composer install --no-dev` e remove arquivos auxiliares da imagem; `development` = instala deps de dev e roda os testes durante o build |
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

## Qualidade e CI

Os checks rodam no GitHub Actions a cada push em `main` e em pull requests:

| Workflow | O que verifica |
|----------|----------------|
| `tests.yml` — PHPUnit | Suíte PHP em PHP 8.4 e 8.5, além de `composer validate`, `composer audit` e `php -l` em todos os arquivos |
| `tests.yml` — PHPStan | Análise estática nível 5 (`phpstan.neon`) |
| `tests.yml` — Frontend | Sintaxe dos JS e smoke test HTML |
| `ci.yml` — Compose | `docker compose config` de produção (dados em `/mnt/visitantes`) e do override de desenvolvimento |
| `ci.yml` — Lint | ShellCheck em `scripts/*.sh`/`docker/*.sh` e Hadolint no `Dockerfile` |
| `ci.yml` — Cobertura | PHPUnit com PCOV e upload do `clover.xml` como artifact |
| `ci.yml` — Smoke | Build e subida de `db + app + nginx` (dados em dirs temporários do runner), espera o banco ficar `healthy` e valida HTTP na porta 8000 |
| `codeql.yml` | Code scanning: CodeQL para JavaScript/TypeScript e GitHub Actions; PHPStan (SARIF) e Psalm taint analysis (SARIF) para PHP — o CodeQL não oferece mais análise de PHP |
| `dependency-review.yml` | Revisão de dependências em PRs (falha em severidade `high`) |
| Dependabot | PRs semanais para Composer, GitHub Actions, imagem Docker e Compose |

Rodando os checks principais localmente:

```bash
# Testes PHP e análise estática
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.4-cli \
  php vendor/bin/phpunit
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.4-cli \
  php vendor/bin/phpstan analyse --no-progress --memory-limit=1G

# Sintaxe dos JS
for f in public/assets/js/*.js; do node --check "$f"; done

# Lint de infraestrutura
docker run --rm -v "$PWD:/mnt" -w /mnt koalaman/shellcheck:stable \
  scripts/setup_docker.sh docker/entrypoint.sh
docker run --rm -i -v "$PWD/.hadolint.yaml:/hadolint.yaml:ro" \
  --entrypoint hadolint hadolint/hadolint:2.12.0 \
  --config /hadolint.yaml - < Dockerfile

# Validação do Compose
docker compose config --quiet
docker compose -f docker-compose.yml -f docker-compose.dev.yml config --quiet
```

> **Repositório privado:** CodeQL e Dependency Review exigem GitHub Advanced Security
> habilitado na organização. Sem isso, apenas os demais workflows são executados.

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

## Estrutura do projeto

```
sistema-visitantes-v1.0/
├── .github/          # Workflows (CI, CodeQL, Dependency Review) e Dependabot
├── api/              # Endpoints REST
├── config/           # Configurações do app
├── deploy/           # Configs de infraestrutura (nginx container)
├── docker/           # Entrypoint e migrações do container
├── fotos_visitantes/ # Fotos dos visitantes (bind mount do drive dedicado em produção)
├── inc/              # Helpers e includes
├── public/           # DocumentRoot (index.php, assets)
├── scripts/          # CRUD e controllers
├── src/              # Classes (Router, Database, Middleware)
├── tests/            # Testes PHPUnit
├── vendor/           # Dependências Composer
├── Dockerfile        # Build da imagem PHP
├── docker-compose.yml
├── docker-compose.dev.yml  # Override de desenvolvimento (phpMyAdmin)
├── phpstan.neon      # Configuração do PHPStan
└── .env.example
```

## Licença

Uso interno.
