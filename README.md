# Sistema de Visitantes v1.0

Sistema web para controle de entrada e saída de visitantes com geração de crachás, relatórios e auditoria.

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

## Tecnologias

- PHP 8.4
- MySQL 8.4
- Apache com mod_rewrite
- JavaScript (Vanilla + Chart.js)
- Bootstrap 5
- Docker Compose

## Requisitos

- PHP 8.4+
- MySQL 8.4+
- Extensões PHP: PDO_MySQL, GD, ZIP, JSON
- Apache com mod_rewrite
- Criar a pasta fotos_visitantes na raiz do projeto

## Instalação local

```bash
# Clone o repositório
git clone https://github.com/GhostTerminals/sistema-visitantes-v1.0.git
cd sistema-visitantes-v1.0

# Configure o ambiente
cp .env.example .env
# Edite .env com suas credenciais do banco

# Instale dependências
composer install --no-dev

# Importe o schema do banco
mysql -u root -p < db/visitorpass.sql

# Configure o VirtualHost para apontar para /public
```

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

#### 2. Montar o RAID 6 em /srv de forma persistente

O drive D (RAID 6) deve ser montado em `/srv` via `/etc/fstab`, para continuar
montado após reinicializações:

```bash
# Descubra o UUID do array (ex.: /dev/md0)
sudo blkid /dev/md0

# Crie o ponto de montagem e adicione a entrada no /etc/fstab:
# UUID=<uuid-do-raid>  /srv  ext4  defaults,nofail  0  2
sudo mkdir -p /srv
sudo mount -a
```

A opção `nofail` evita que o servidor trave no boot caso o array esteja degradado.

#### 3. Implantar o sistema em /opt

```bash
# Implante o projeto em /opt/sistema-visitantes
sudo mkdir -p /opt/sistema-visitantes
# (copie o projeto para /opt/sistema-visitantes)

# Crie as pastas de dados no drive D (RAID 6, montado em /srv)
sudo mkdir -p /srv/sistema-visitantes/mysql /srv/sistema-visitantes/fotos
# MySQL 8.4 roda como UID 999 e o app como UID 1000:
sudo chown 999:999 /srv/sistema-visitantes/mysql
sudo chown 1000:1000 /srv/sistema-visitantes/fotos
# Ubuntu usa AppArmor (não SELinux): os bind mounts funcionam sem os sufixos :z/:Z.

# Configure variáveis seguras
vim .env
# Use senhas fortes para: VISITORPASS_DB_PASS, MYSQL_ROOT_PASSWORD
# E gere a chave de criptografia do MFA (64 hex chars = 32 bytes):
#   echo "VISITORPASS_MFA_KEY=$(openssl rand -hex 32)" >> .env
#
# Opcional - caminhos alternativos no RAID 6:
#   DB_DATA_DIR=/srv/sistema-visitantes/mysql
#   FOTOS_DIR=/srv/sistema-visitantes/fotos
# Porta externa do sistema (troque se houver mais de um sistema no servidor):
#   NGINX_PORT=8000
# Prefixo de containers/redes (troque para cada sistema/instância):
#   COMPOSE_PROJECT_NAME=visitantes

# Build e inicie (nginx e a unica porta publicada; app, phpMyAdmin e MySQL nao)
cd /opt/sistema-visitantes
docker compose up -d --build
```

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

> Para hospedar **outro sistema no mesmo servidor**, use outro `NGINX_PORT`
> (ex.: 8001) e outro `COMPOSE_PROJECT_NAME` (ex.: `outro-sistema`) no `.env` do
> outro projeto. Cada stack tem seu próprio nginx e não depende do nginx do host.
> Não há `container_name` fixo: os containers/redes recebem o prefixo do projeto.
> Evite portas já em uso (`sudo ss -ltnp`).

> O nginx repassa `Host`, `X-Real-IP`, `X-Forwarded-For` e `X-Forwarded-Proto`
> ao PHP. Logs de acesso/erro aparecem em `docker compose logs nginx`.

### Setup automático

```bash
# Cria .env, builda as imagens, sobe containers, aguarda o banco e
# verifica que os usuarios padrao (admin/supervisor) foram criados
./scripts/setup_docker.sh

# Forca rebuild das imagens
./scripts/setup_docker.sh --build

# Remove containers e recria do zero (dados no RAID 6 em /srv SAO preservados)
./scripts/setup_docker.sh --reset
```

O banco de dados não expõe porta no host. Acessos administrativos devem ser feitos via
`docker compose exec db mysql -u root -p` ou pela rede interna.

## Qualidade e CI

Os checks rodam no GitHub Actions a cada push em `main` e em pull requests:

| Workflow | O que verifica |
|----------|----------------|
| `tests.yml` — PHPUnit | Suíte PHP em PHP 8.4 e 8.5, além de `composer validate`, `composer audit` e `php -l` em todos os arquivos |
| `tests.yml` — PHPStan | Análise estática nível 5 (`phpstan.neon`) |
| `tests.yml` — Frontend | Sintaxe dos JS, testes unitários (`node --test tests/js/*.test.mjs`) e smoke test HTML |
| `ci.yml` — Compose | `docker compose config` de produção e do override de desenvolvimento |
| `ci.yml` — Lint | ShellCheck em `scripts/*.sh`/`docker/*.sh` e Hadolint no `Dockerfile` |
| `ci.yml` — Cobertura | PHPUnit com PCOV e upload do `clover.xml` como artifact |
| `ci.yml` — Smoke | Build e subida de `db + app + nginx`, espera o banco ficar `healthy` e valida HTTP na porta 8000 |
| `codeql.yml` | Code scanning: CodeQL para JavaScript/TypeScript e GitHub Actions, e PHPStan (SARIF) para PHP — o CodeQL não oferece mais análise de PHP |
| `dependency-review.yml` | Revisão de dependências em PRs (falha em severidade `high`) |
| Dependabot | PRs semanais para Composer, GitHub Actions, imagem Docker e Compose |

Rodando os checks principais localmente:

```bash
# Testes PHP e análise estática
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.4-cli \
  php vendor/bin/phpunit
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.4-cli \
  php vendor/bin/phpstan analyse --no-progress --memory-limit=1G

# Testes JS
node --test tests/js/*.test.mjs

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

## Usuários padrão (Docker)

Os seguintes usuários são criados automaticamente na primeira execução:

| Usuário | Senha | Nível |
|---------|-------|-------|
| admin | Admin@2026Temp! | admin |
| supervisor | Supervisor@2026Temp! | supervisor |

**Altere as senhas imediatamente após o primeiro login.**

## Remoção do MFA (2026-08-20)

A autenticação em duas etapas (MFA/TOTP) foi removida do sistema:

- **scripts/login_submit.php** - Removida verificação `mfaIsEnabled()` após login bem-sucedido
- **public/index.php** - Removidas rotas `mfa_challenge` e `admin_mfa`
- **inc/navbar.php** - Removido item "Verificação em Duas Etapas" do menu do usuário
- **.env** - Variável `VISITORPASS_MFA_KEY` não é mais necessária
- **docker-compose.yml** - Variável mantida mas sem uso (compatibilidade)

O login agora redireciona diretamente para a página inicial após autenticação válida. Tabelas `usuarios_mfa` e `mfa_recovery_codes` permanecem no banco mas não são mais utilizadas.

## Estrutura do projeto

```
sistema-visitantes-v1.0/
├── .github/          # Workflows (CI, CodeQL, Dependency Review) e Dependabot
├── api/              # Endpoints REST
├── config/           # Configurações do app
├── db/               # Scripts SQL
├── deploy/           # Configs de infraestrutura (nginx container)
├── docker/           # Configurações Docker
├── fotos_visitantes/ # Uploads de fotos (bind mount no RAID 6 em produção)
├── inc/              # Helpers e includes
├── public/           # DocumentRoot (index.php, assets)
├── scripts/          # CRUD e controllers
├── tests/            # Testes unitários (PHP e JS)
├── vendor/           # Dependências Composer
├── Dockerfile        # Build da imagem PHP
├── docker-compose.yml
├── docker-compose.dev.yml  # Override de desenvolvimento (phpMyAdmin)
├── phpstan.neon      # Configuração do PHPStan
└── .env.example
```

## Licença

Uso interno.
