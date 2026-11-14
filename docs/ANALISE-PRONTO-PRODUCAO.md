# Análise de prontidão para produção — SICAV

- **Data:** 2026-11-14
- **Commit analisado:** `18e6d1d` (`fix/dev-development`)
- **Alvo:** Ubuntu Server + Docker Engine + Docker Compose
- **Escopo:** aplicação PHP, banco MySQL, imagens/Compose, deploy, operação e continuidade
- **Método:** leitura do código e da infraestrutura, validação de `docker compose config`,
  `docker build --check` e build real da imagem de produção, inspeção da imagem gerada
  e comparação entre a documentação (`README.md`, `docs/PLANO_IMPLEMENTACAO.md`,
  `docs/ESPECIFICAÇÃO-SEGURANÇA.md`) e o estado do repositório.

---

## 1. Veredito

A **camada de aplicação está madura** e a infraestrutura Docker já está bem acima da média:
container non-root, filesystem read-only, `cap_drop`, rede interna para o banco, imagens
fixadas por digest, logs rotacionados, CI com testes/análise estática/Trivy e auditoria
estruturada. O build de produção foi reproduzido com sucesso e o Compose valida sem erros.

O sistema **ainda não está pronto para ser publicado sem algumas correções de processo e
operação**. Os bloqueadores não estão na aplicação em si, mas em:

1. **branch de deploy divergente** — a `main` não contém o hardening mais recente e contém
   artefatos de desenvolvimento;
2. **exemplo de ambiente com `APP_ENV=development`** — um deploy seguindo o exemplo cria
   usuário supervisor padrão e imagem de desenvolvimento;
3. **credenciais iniciais conhecidas** — senha do admin está no código e na documentação;
4. **TLS/HTTPS ainda não implementado** (aceitável apenas se a rede for realmente isolada);
5. **backup/restauração manuais, sem teste de restore e no mesmo drive dos dados**;
6. **risco de os dados caírem no disco do sistema se o drive não estiver montado**.

Com os P0 resolvidos, o sistema fica adequado para produção em rede interna. Para exposição
fora da LAN, TLS e os itens de monitoramento/OS hardening passam a ser obrigatórios.

---

## 2. O que já está pronto (não regredir)

- Senhas com **Argon2id**; sessão com regeneração no login e token opaco armazenado em hash.
- **CSRF** em web e API; **CSP com nonce**, HSTS condicional, `nosniff`, `X-Frame-Options`.
- **SQL parametrizado** em toda a camada de banco; upload validado por MIME/tamanho/extensão.
- **Rate limiting** por IP+rota em login, admin, exportações e fotos.
- **Sessão única**, timeout por inatividade e expiração absoluta.
- **Auditoria** de visitantes por triggers + eventos estruturados `AUDIT` com `request_id`.
- **IP real do cliente** atrás do nginx via `mod_remoteip` gerado no boot.
- `.env` fora do Git e fora da imagem; `vendor/` não versionado.
- `app`: UID 1000, `read_only`, `cap_drop: ALL`, `no-new-privileges`, tmpfs, limites.
- `db`: sem porta publicada, em rede `backend` com `internal: true`.
- Imagens fixadas por digest; CI valida Compose, ShellCheck, Hadolint, PHPUnit, PHPStan,
  Trivy e smoke test da stack com `request_id`/IP.
- Logs Docker com rotação `10m × 5`.

---

## 3. Bloqueadores (P0 — resolver antes do go-live)

### P0-01 — Branch de deploy divergente e artefatos de dev na `main`

**Evidência**

- `git merge-base --is-ancestor 18e6d1d main` retorna **1** (falso): o commit de hardening
  de 2026-11-13 **não está na `main`**.
- `main` está em `21b339a` e contém:
  - `phpactor.phar` (4,8 MB) versionado;
  - `phpactor-lsp` (wrapper local);
  - `phpactor/phpactor` adicionado em `composer.json` **sem atualizar o `composer.lock`**.
- `fix/dev-development` tem 8 commits à frente da `main`; a `main` tem 3 commits que não
  estão na branch de integração.

**Impacto**

O `README.md` determina que o deploy é feito **somente a partir da `main`**. Publicar hoje
a partir da `main` entrega uma versão **sem** as correções de IP real, `X-Request-ID`,
digests e credenciais obrigatórias, além de carregar lixo de desenvolvimento para a imagem.
O `composer validate`/CI pode falhar por lock desatualizado.

**Ação**

1. Abrir PR de `fix/dev-development` → `main`, resolver conflitos (especialmente
   `composer.json`, `opencode.json` e `.gitignore`).
2. Remover `phpactor.phar` e `phpactor-lsp` do versionamento; remover
   `phpactor/phpactor` de `require-dev` (ou instalar como ferramenta global).
3. Adicionar `*.phar` e `phpactor-lsp` ao `.gitignore`.
4. Rodar `composer update --lock` (ou `composer install` e commitar o lock coerente).
5. Só então criar a tag de release (`vX.Y.Z`) e fazer o deploy a partir da `main`.

**Critério de aceite**

```bash
git merge-base --is-ancestor 18e6d1d main && echo OK
git ls-tree -r --name-only main | grep -E 'phpactor|\.phar$' && echo FALHA || echo OK
```

---

### P0-02 — `.env.example.docker` entrega `APP_ENV=development`

**Evidência**

- `.env.example.docker:10`: `APP_ENV=development`.
- `docker-compose.yml:40`: `VISITORPASS_ENV=${APP_ENV:-production}`.
- `docker/migrate.php:220-231`: em `development`, cria também o usuário **supervisor**
  com a senha pública `Supervisor@2026Temp!`.
- Em `development`, o Dockerfile instala dependências de dev, roda testes e mantém
  `node_modules`/artefatos de teste (`Dockerfile:46-65`).

**Impacto**

Quem copiar o exemplo para o servidor e não revisar sobe uma imagem de desenvolvimento e
um usuário adicional com senha conhecida — falha de segurança direta.

**Ação**

- Trocar o default do exemplo para `APP_ENV=production`, ou criar um
  `.env.production.example` separado e deixar o atual explicitamente como dev.
- Adicionar um **preflight de deploy** que recusa `APP_ENV != production`, valores
  `troque-esta-senha`/`troque-esta-senha-root` e senhas curtas.
- Renomear `scripts/setup_docker.sh` para deixar claro que é só desenvolvimento ou fazer
  ele abortar quando detectar servidor/produção.

---

### P0-03 — Credenciais iniciais conhecidas e fallback de senha fraca

**Evidência**

- `docker/migrate.php:223`: `password_hash('Admin@2026Temp!', PASSWORD_ARGON2ID)`.
- `README.md:245-247` publica as senhas iniciais (repositório público).
- `docker/entrypoint.sh:28` e `docker/migrate.php:11` usam fallback `visitorpassword`.
- O usuário nasce com `senha_temporaria=1`, mas **quem fizer o primeiro login primeiro
  define a senha definitiva**.

**Impacto**

Se a porta for exposta antes do primeiro acesso, um atacante entra como admin com uma
credencial pública. Em `development`, o supervisor também é criado com senha pública.

**Ação**

- Não hardcodar senha de admin. Na primeira execução:
  - exigir `VISITORPASS_ADMIN_INITIAL_PASSWORD` (falhar se ausente), ou
  - gerar senha aleatória, exibir **uma única vez** no log e marcar troca obrigatória.
- Remover os fallbacks `visitorpassword` de `entrypoint.sh` e `migrate.php` (falhar
  fechado).
- Remover as senhas padrão do README ou deixá-las apenas no fluxo de desenvolvimento.
- No runbook, criar o admin **antes** de liberar a porta no firewall.

---

### P0-04 — TLS/HTTPS

**Evidência**

- `deploy/nginx/default.conf` escuta apenas `:80`; o Compose publica
  `${NGINX_PORT:-8000}:80` em todas as interfaces.
- `docs/ESPECIFICAÇÃO-SEGURANÇA.md` §21 exige HTTPS em produção; o próprio documento
  registra o requisito como pendente para exposição externa.
- Cookies só recebem `Secure`/`__Host-SID` quando `isHttpsRequest()` detecta HTTPS.

**Decisão necessária**

- **Rede interna isolada:** aceitável manter HTTP **desde que** haja decisão formal de
  risco, firewall restrito e a porta publicada apenas na interface interna.
- **Qualquer acesso externo/VPN/outra rede:** TLS é obrigatório.

**Ação recomendada**

Terminar TLS em um proxy à frente do nginx (Caddy, nginx do host com Certbot ou proxy
corporativo) e:

- publicar o nginx do Compose apenas em loopback: `127.0.0.1:${NGINX_PORT:-8000}:80`;
- liberar no firewall somente 443 (e SSH), nunca 8000;
- no proxy de TLS, enviar `X-Forwarded-Proto: https` e `X-Forwarded-For`;
- **atenção ao IP real:** se houver um proxy TLS na frente do nginx do Compose, o
  `$remote_addr` do nginx passa a ser o IP desse proxy. É preciso configurar `real_ip`
  (`set_real_ip_from` + `real_ip_header X-Forwarded-For`) no
  `deploy/nginx/default.conf`, senão rate limiting e auditoria voltam a ver um único IP;
- validar que o PHP emite HSTS e o cookie vira `__Host-SID` sob HTTPS.

Exemplo de diretiva no nginx do Compose:

```nginx
set_real_ip_from 172.16.0.0/12;   # ajustar à rede do proxy TLS
real_ip_header X-Forwarded-For;
real_ip_recursive on;
```

---

### P0-05 — Backup e restauração não automatizados nem testados

**Evidência**

- O README tem apenas comandos manuais de `mysqldump` e `rsync` (`README.md:279-316`).
- Não existe `scripts/backup.sh`, timer/cron versionado, verificação de integridade,
  criptografia, retenção ou teste de restore.
- Banco e fotos ficam no **mesmo drive** `/mnt/visitantes`; a perda do drive perde os dois.
- O dump usa senha root em argumento de linha de comando (visível em `ps`).

**Ação**

1. Criar `scripts/backup.sh` com `set -euo pipefail` e `flock` para evitar execuções
   concorrentes.
2. Dump consistente com o banco em uso:
   `mysqldump --single-transaction --routines --triggers --events --set-gtid-purged=OFF`.
3. Usar `MYSQL_PWD` ou `--defaults-extra-file` com arquivo `600`, não `-p<senha>`.
4. Copiar/compactar as fotos com `rsync`/`tar`; calcular `sha256` dos artefatos.
5. **Criptografar** o backup (PII: CPF, telefone, e-mail e fotos) com `age` ou GPG.
6. Enviar para **outro host/disco** (NFS, S3 compatível, rsync remoto) e aplicar retenção
   (ex.: 7 diários, 4 semanais, 12 mensais).
7. Agendar por `systemd timer` (preferível a cron) e monitorar falha.
8. Documentar e **executar um restore de teste** em host limpo antes do go-live; registrar
   RTO/RPO.

**Critério de aceite**

- Backup diário automático com data, tamanho e checksum monitorados.
- Restore completo (banco + fotos) validado em ambiente separado.

---

### P0-06 — Drive de dados pode não estar montado e o Compose cria pasta no disco do sistema

**Evidência**

- `docker-compose.yml:44` e `:117` usam bind mounts em `/mnt/visitantes/...`.
- Se o drive não estiver montado, o Docker **cria o diretório no disco raiz** e os dados
  vão para o lugar errado, silenciosamente. O README alerta, mas não há bloqueio técnico.
- `nofail` no `fstab` aumenta a chance de o boot seguir sem o drive.

**Ação**

- Criar unidade systemd para a stack com `RequiresMountsFor=/mnt/visitantes`,
  `After=docker.service` e `ConditionPathIsMountPoint=/mnt/visitantes`.
- No script de deploy, abortar se `findmnt -M /mnt/visitantes` falhar.
- Monitorar o uso do disco e alertar em 80%.
- Considerar `x-systemd.requires-mounts-for`/`x-systemd.device-timeout` no `fstab`.

---

## 4. Riscos altos (P1 — corrigir na primeira semana)

### P1-01 — Apache MPM sem tuning vs. limite de memória

O container `app` tem `mem_limit: 512m` (`docker-compose.yml:72`) e o PHP
`memory_limit=256M` (`docker/php.ini:6`). O MPM prefork padrão da imagem é
`StartServers 5` / `MaxRequestWorkers 150`: algumas requisições simultâneas podem estourar
o limite e o OOM killer derrubar o Apache.

**Ação:** adicionar tuning em `docker/apache.conf` (ou arquivo próprio) e alinhar o limite:

```apache
<IfModule mpm_prefork_module>
    StartServers             2
    MinSpareServers          2
    MaxSpareServers          4
    ServerLimit              10
    MaxRequestWorkers        10
    MaxConnectionsPerChild   1000
</IfModule>
```

Alternativa: elevar `mem_limit` do `app` para `1g` e manter `MaxRequestWorkers` baixo.
Validar com teste de carga leve (ex.: `hey`/`ab`) e monitorar `docker stats`.

### P1-02 — Sem healthcheck de `app` e `nginx`

Só o `db` tem healthcheck (`docker-compose.yml:118-123`). O `nginx` depende apenas de
`app` iniciado (`depends_on` sem condição). Um app travado continua "Up" e o nginx segue
respondendo 502.

**Ação:**

```yaml
# app
healthcheck:
  test: ["CMD", "curl", "-fsS", "-o", "/dev/null", "http://127.0.0.1/index.php?page=login"]
  interval: 30s
  timeout: 5s
  retries: 3
  start_period: 30s

# nginx
healthcheck:
  test: ["CMD", "wget", "-q", "--spider", "http://127.0.0.1/"]
  interval: 30s
  timeout: 5s
  retries: 3

# nginx depends_on
depends_on:
  app:
    condition: service_healthy
```

### P1-03 — `entrypoint.sh` interpola a senha dentro de código PHP

`docker/entrypoint.sh:26-36` monta o script PHP com a senha entre aspas simples:

```php
new PDO(..., '${VISITORPASS_DB_PASS:-visitorpassword}', ...)
```

Uma senha com `'`, `$`, backtick ou quebra de linha quebra o comando (ou pior, permite
injeção no shell). O loop também não tem timeout: se o banco não subir, o container fica
em loop indefinidamente.

**Ação:** ler as variáveis via `getenv()` no PHP (sem interpolar), remover o fallback e
limitar as tentativas (ex.: 60 × 2 s), falhando com mensagem clara.

### P1-04 — `session.gc_maxlifetime` fixo em 3600

`docker/php.ini:12` define `session.gc_maxlifetime = 3600`, mas a sessão absoluta padrão é
`43200` (`VISITORPASS_SESSION_ABSOLUTE_LIFETIME`). Se o timeout for aumentado, o PHP pode
coletar a sessão antes do tempo configurado.

**Ação:** alinhar para `>=` a vida absoluta (ex.: `86400`) ou gerar o valor no entrypoint a
partir da variável de ambiente.

### P1-05 — Binlog do MySQL sem expiração e healthcheck acoplado à senha root

- `docker-compose.yml:107`: `--log-bin-trust-function-creators=1` (necessário para os
  triggers), mas o binlog fica habilitado sem `--binlog-expire-logs-seconds`. Em uso
  prolongado, o binlog pode encher o drive.
- Healthcheck (`:119`) usa `-p${MYSQL_ROOT_PASSWORD}`. Se a senha root for rotacionada no
  `.env` depois da inicialização do banco, o healthcheck quebra (a senha real do MySQL não
  muda sozinha).

**Ação:** definir `--binlog-expire-logs-seconds=604800` (7 dias) ou desabilitar binlog se
não houver réplica/PITR; monitorar `/mnt/visitantes/mysql`. No healthcheck, usar
`mysqladmin ping -h localhost` sem senha (o `ping` retorna sucesso mesmo com acesso
negado, pois só verifica se o servidor está vivo) ou um arquivo de credenciais `600`.

### P1-06 — Monitoramento e alertas ausentes

Não há verificação de disponibilidade, espaço em disco, idade do backup, erros 5xx, saúde
do banco ou expiração de certificado.

**Ação mínima:** Uptime Kuma ou `curl` + systemd timer + alerta (e-mail/Telegram);
`node_exporter`/`cAdvisor` se houver Prometheus. Alertar para:

- container `app`/`nginx`/`db` fora do ar;
- HTTP diferente de 200/302 na tela de login;
- disco > 80% e crescimento anormal do MySQL;
- backup com mais de 26 h ou falha no checksum;
- certificado TLS a < 21 dias.

### P1-07 — Retenção dos logs de auditoria

Os eventos `AUDIT` vão para stderr e ficam no `json-file` com rotação de 50 MB por serviço.
Eventos de segurança antigos são perdidos. A auditoria de visitantes no banco persiste, mas
`LOGIN_FAILURE`, `RATE_LIMIT_TRIGGERED`, `SESSION_REVOKED` etc. não.

**Ação:** enviar os logs para arquivo persistente/`journald` remoto/Loki com retenção
definida (ex.: 90 dias), ou usar um sidecar/plugin de log. Definir se há requisito legal de
retenção.

### P1-08 — Hardening do host Ubuntu

O README cobre Docker e firewall, mas não o SO. Antes do go-live:

- usuário dedicado de deploy (não usar root); acesso ao grupo `docker` é equivalente a root;
- SSH somente por chave, `PermitRootLogin no`, `PasswordAuthentication no`;
- UFW default deny, liberar apenas SSH e a porta do proxy TLS;
- **atenção:** portas publicadas pelo Docker ignoram o UFW (cadeia `DOCKER-USER`). Nunca
  subir o override de desenvolvimento (`phpMyAdmin` na 8081) no servidor;
- `unattended-upgrades` para patches de segurança e política de reboot;
- `fail2ban`/CrowdSec para SSH (e, se possível, para o proxy);
- `chrony`/NTP ativo (a aplicação e o banco dependem de horário correto);
- swap dimensionado e alerta de memória;
- Docker daemon: `live-restore`, limites default de log e revisão de quem acessa o socket.

### P1-09 — Gestão e rotação de segredos

- `.env` deve ser `root:root 600` no servidor (no checkout local está `600`, correto).
- Documentar que alterar `MYSQL_ROOT_PASSWORD` no `.env` **não** altera a senha já
  inicializada no MySQL; é preciso `ALTER USER` e atualizar o healthcheck.
- O mesmo vale para `VISITORPASS_DB_PASS`: após o primeiro boot, mudar no `.env` não muda
  a senha do usuário no banco.
- Não usar `docker compose config` em logs/CI público (ele resolve os segredos).
- Backup do `.env` deve ser criptografado e guardado separado do backup dos dados.

### P1-10 — LGPD / proteção de dados

O sistema armazena CPF, nome, telefone, e-mail e foto. Antes de produção:

- definir base legal, finalidade e prazo de retenção;
- documentar procedimento de exclusão/anonimização e atendimento ao titular;
- restringir quem tem nível `user`/`supervisor`/`admin`;
- considerar criptografia do disco de dados (LUKS) e dos backups;
- registrar acesso a fotos/relatórios (já há auditoria parcial).

### P1-11 — Migrações sem versionamento

`docker/migrate.php` é idempotente, mas não tem tabela de versão nem ordem de migrações.
Alterações futuras de schema exigirão lógica condicional manual e são difíceis de auditar.

**Ação:** introduzir `schema_migrations` + arquivos SQL numerados, aplicados uma vez, com
transação e registro de versão. Para o go-live atual não é bloqueante, mas deve entrar antes
da primeira evolução de schema em produção.

---

## 5. Melhorias (P2 — após estabilizar)

- **Imagem de produção menor:** o build real gerou **1,12 GB**. Node.js é instalado
  incondicionalmente (`Dockerfile:54-58`, ~95 MB de binário + 16 MB de módulos) e pacotes
  de build permanecem na imagem final (~219 MB de layer). Usar multi-stage: compilar
  extensões no estágio de build, copiar `.so` para a imagem final, instalar Node apenas no
  estágio de testes e remover `git`/`unzip`/`curl`/headers ao final.
- **Remover da imagem de produção:** `tests/`, `docs/`, `phpunit.xml`, `phpstan.neon`,
  `psalm.xml`, `docker-compose*.yml`, `Dockerfile` e `opencode.json`. Hoje eles ficam em
  `/var/www/html` (fora do DocumentRoot, mas é superfície desnecessária).
- **OPcache:** já vem habilitado na imagem base, mas sem tuning. Para código imutável na
  imagem: `opcache.validate_timestamps=0`, `opcache.memory_consumption=128`,
  `opcache.interned_strings_buffer=16`, `opcache.max_accelerated_files=10000`. Exige
  restart no deploy.
- **Compressão no nginx:** `gzip on` para `text/*`, `application/json`,
  `application/javascript`, `image/svg+xml`. As imagens em `public/imagens` somam ~4,3 MB
  (`avatar.png` 2,1 MB, `logo_sts.png` 1,3 MB, `tela_fundo.png` 0,95 MB); converter para
  WebP/redimensionar reduz muito o tempo de primeiro carregamento.
- **Tuning do MySQL:** `innodb_buffer_pool_size=256M`, `max_connections=50`,
  `slow_query_log` e alerta de conexões. Avaliar com o uso real antes de aplicar.
- **MFA/SSO para admin:** o MFA foi removido; para contas administrativas em rede
  corporativa, reavaliar a decisão ou integrar com SSO/2FA no proxy.
- **Contas individuais:** não usar o `admin` compartilhado; criar uma conta por
  administrador e desativar a padrão após o bootstrap.
- **WAF/rate limit de borda:** CrowdSec/ModSecurity no proxy TLS, se houver exposição.
- **Deploy sem downtime:** para uma atualização, hoje o `docker compose up -d --build`
  recria o app e causa uma janela curta. Para uso interno é aceitável; documentar janela de
  manutenção. Para HA real seria necessário réplica + load balancer + sessão compartilhada.
- **Teste de carga e plano de capacidade:** medir latência de login, listagem e exportação
  com o volume esperado; validar os limites de memória/CPU.

---

## 6. Plano de implantação revisado (Ubuntu)

### Fase 0 — antes de copiar o projeto para o servidor

1. Resolver P0-01 (merge para `main`, limpar artefatos, lock coerente, CI verde).
2. Corrigir `.env.example.docker`/criar exemplo de produção (P0-02).
3. Implementar bootstrap de admin sem senha fixa e remover fallbacks (P0-03).
4. Decidir TLS: rede isolada com risco aceito **ou** proxy TLS (P0-04).
5. Criar script + timer de backup e executar um restore de teste (P0-05).
6. Adicionar guarda de mount no deploy/systemd (P0-06).
7. Aplicar P1-01 a P1-05 (MPM, healthchecks, entrypoint, sessão, MySQL).

### Fase 1 — preparação do host

```bash
# 1. Docker oficial + Compose plugin (conforme README)
# 2. Drive dedicado persistente
sudo blkid /dev/sdb1
# /etc/fstab:
# UUID=<uuid>  /mnt/visitantes  ext4  defaults,nofail,x-systemd.device-timeout=30s  0  2
sudo mkdir -p /mnt/visitantes
sudo mount -a
findmnt -M /mnt/visitantes   # precisa retornar o mountpoint

# 3. Pastas e permissões
sudo mkdir -p /mnt/visitantes/mysql /mnt/visitantes/fotos
sudo chown 999:999 /mnt/visitantes/mysql
sudo chown 1000:1000 /mnt/visitantes/fotos

# 4. Projeto e segredos
sudo mkdir -p /opt/sistema-visitantes
# copiar a main para /opt/sistema-visitantes
cd /opt/sistema-visitantes
sudo install -m 600 /dev/null .env
sudoedit .env
# APP_ENV=production, senhas fortes, NGINX_PORT, COMPOSE_PROJECT_NAME

# 5. Firewall
sudo ufw default deny incoming
sudo ufw allow 22/tcp
# Se TLS no host/proxy:
# sudo ufw allow 443/tcp
# Se HTTP interno:
# sudo ufw allow from <rede-interna> to any port 8000 proto tcp
sudo ufw enable
```

### Fase 2 — subir e validar

```bash
cd /opt/sistema-visitantes
VISITORPASS_DB_PASS=... MYSQL_ROOT_PASSWORD=... docker compose config --quiet
docker compose up -d --build
docker compose ps
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8000/
docker compose logs app | grep -i migration
```

Depois:

- criar o admin/alterar a senha **antes** de liberar a porta;
- criar contas individuais e desativar o admin padrão;
- validar upload de foto, login, logout, sessão única e um relatório;
- validar que `docker compose exec app id` retorna UID 1000;
- validar que o banco não tem porta publicada (`docker compose ps`, `ss -ltnp`);
- executar o primeiro backup e o restore de teste;
- configurar monitoramento e alertas.

### Fase 3 — operação

- backup diário + verificação semanal + restore trimestral;
- atualização mensal de imagens (`./scripts/pin-images.sh --apply`) e rebuild;
- revisão de vulnerabilidades (Trivy/`composer audit`);
- rotação de senhas conforme política;
- revisão trimestral de acessos e contas;
- teste de reboot do host (garantir que o drive monta e a stack sobe).

---

## 7. Checklist go/no-go

**Bloqueadores**

- [ ] `main` contém o commit de hardening e não contém `phpactor.phar`/`phpactor-lsp`
- [ ] `composer.lock` coerente e CI verde na `main`
- [ ] `APP_ENV=production` no `.env` do servidor e preflight recusando placeholders
- [ ] Admin inicial sem senha pública; fallback `visitorpassword` removido
- [ ] TLS decidido: proxy TLS configurado **ou** risco de HTTP interno formalmente aceito
- [ ] Firewall restrito; porta 8000 acessível apenas pela rede interna (ou só 443)
- [ ] Backup automático, criptografado, fora do host, com restore testado
- [ ] Guarda de mount do drive `/mnt/visitantes` ativa
- [ ] Healthchecks de `app`/`nginx` e tuning de MPM/memória

**Operação**

- [ ] Monitoramento de disponibilidade, disco, banco, backup e certificado
- [ ] Retenção de logs/auditoria definida
- [ ] SSH por chave, root desabilitado, patches automáticos, NTP
- [ ] Contas individuais criadas; admin padrão desativado
- [ ] Runbook de incidente, restore e contatos definidos
- [ ] Política de retenção/LGPD documentada

---

## 8. Evidências da análise

```text
docker compose config --quiet                          -> OK (produção)
docker compose -f docker-compose.yml -f docker-compose.dev.yml config --quiet -> OK
docker build --check .                                 -> Check complete, no warnings
docker build --build-arg ENVIRONMENT=production .      -> exit 0
docker image ls sicav-app:analysis                     -> 1.12 GB
docker run --rm sicav-app:analysis id                  -> uid=1000(app)
docker run --rm sicav-app:analysis sh -c 'apache2ctl -M' -> mpm_prefork, remoteip, rewrite, headers, expires
imagem de produção: /var/www/html/.env                 -> ausente (OK)
imagem de produção: node v20.20.2 presente             -> ~95 MB binário + 16 MB módulos
imagem de produção: tests/, docs/, phpunit.xml, phpstan.neon, psalm.xml presentes
```

Comandos de branch:

```text
git merge-base --is-ancestor 18e6d1d main -> 1 (não é ancestral)
main = 21b339a ("correçãoes mpc"; phpactor.phar + composer.json sem lock)
fix/dev-development = 18e6d1d (hardening de 2026-11-13)
```

---

## 9. Observações finais

- A aplicação em si **não apresentou falha crítica** de SQLi/XSS/CSRF/path traversal na
  revisão; os controles descritos no `PLANO_IMPLEMENTACAO.md` estão implementados.
- O maior risco atual é **operacional**: publicar a branch errada, subir com o exemplo de
  desenvolvimento, expor HTTP sem decisão formal, ou perder dados por ausência de backup
  testado/mount não verificado.
- Para rede interna, os P0 + P1-01..05 são suficientes para um go-live seguro. Para
  exposição externa, tratar também TLS, WAF/rate limit de borda, MFA/SSO e retenção de
  auditoria como requisitos.
