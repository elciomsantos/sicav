# PLANO DE IMPLEMENTAÇÃO

## Alinhado à ESPECIFICAÇÃO-SEGURANÇA.md e ao protocolo ESPECIFICACAO-AUDITORIA-PHP.md

- **Data original:** 2026-08-19
- **Última revisão:** 2026-11-13
- **Escopo:** Aplicação PHP + MySQL + Docker Compose (SICAV — Sistema de Visitantes)
- **Baseline atual:** `composer audit` limpo; 153 testes PHPUnit passando (240 asserções) em PHP 8.4/8.5; imagem Docker com PHP 8.5 e MySQL 8.4.

> **Revisão de 2026-11-13:** o MFA/TOTP foi removido do sistema em 2026-08-20
> e não faz parte do estado atual. O fluxo oficial de branches, PRs, deploy e
> tags está em [`docs/FLUXO-GIT.md`](FLUXO-GIT.md).

---

## 1. Resumo Executivo

O projeto já implementa corretamente boa parte da especificação de segurança:

- Queries parametrizadas (PDO) em todos os pontos (`src/Database.php`).
- CSRF synchronizer token web + API (`inc/security.php`, `src/Middleware/CsrfMiddleware.php`).
- Sessão server-side com regeneração no login, token opaco 256 bits armazenado como hash SHA-256 (`inc/single_session.php`, `scripts/login_submit.php:149`).
- Rate limiting de login por conta e por IP (`scripts/login_submit.php`).
- Senhas com `password_hash()` / `password_verify()`.
- Headers de segurança e CSP com nonce (`public/index.php`).
- Upload validado por MIME real, tamanho e extensão (`api/api_cadastro_visitante.php`).
- `.env` fora do versionamento; `.dockerignore` presente.

Os achados abaixo são as lacunas **confirmadas** entre a especificação e o estado atual, ordenadas por prioridade.

---

## 2. Matriz de Achados Confirmados

| ID | Área | Severidade | Prioridade | Esforço | Confiança | Status |
|---|---|---|---|---:|---:|---|---|
| SEC-DOCKER-001 | Docker | Alto | Alta | 2 | Confirmado | Validado |
| SEC-DOCKER-002 | Docker | Alto | Alta | 2 | Confirmado | Validado |
| SEC-DOCKER-003 | Docker | Médio | Média | 1 | Confirmado | Validado |
| SEC-DOCKER-004 | Docker | Médio | Média | 1 | Confirmado | Validado |
| SEC-DOCKER-005 | Docker | Alto | Alta | 1 | Confirmado | Validado |
| SEC-DOCKER-006 | Docker | Alto | Alta | 1 | Confirmado | Validado |
| SEC-DOCKER-007 | Docker | Médio | Média | 2 | Confirmado | Validado |
| SEC-PASS-001 | Autenticação | Médio | Média | 1 | Confirmado | Validado |
| SEC-COOKIE-001 | Sessões | Alto | Alta | 1 | Confirmado | Validado |
| SEC-SESS-001 | Sessões | Médio | Média | 3 | Confirmado | Validado |
| SEC-RATE-001 | Segurança | Médio | Média | 2 | Confirmado | Validado |
| SEC-MFA-001 | Autenticação | Alto | Alta | 5 | Confirmado | Removido |
| SEC-HDR-001 | Configuração | Médio | Média | 2 | Confirmado | Validado |
| SEC-LOG-001 | Logs | Médio | Média | 2 | Confirmado | Validado |
| QUAL-BUG-001 | Backend | Alto | Alta | 1 | Confirmado | Validado |

---

## 3. Achados — Formato Completo

---

### SEC-DOCKER-001 — Container executa como root

- **Problema:** O container da aplicação executa o Apache/PHP como `root` (nenhum `USER` no Dockerfile). Violação da regra §34 (Container Non-Root) e §33 da especificação.
- **Localização:** `Dockerfile:1-55`, `docker-compose.yml:2-28`.
- **Evidência (estado original):** `FROM php:8.2-apache` sem diretiva `USER`; processo Apache rodava como root. A imagem atual usa `php:8.5-apache`.
- **Causa raiz:** Dockerfile herdado de imagem base sem etapa de usuário dedicado.
- **Impacto:** Comprometimento do PHP concede root no container; aumenta risco de container escape.
- **Implementação recomendada:** Criar usuário não-root (ex.: `app`, UID 1000) no Dockerfile, definir `WORKDIR` e `USER app`, ajustar permissões dos volumes (`fotos_visitantes`). Em `docker-compose.yml`, `user: "1000:1000"`. Verificar escrita em `/var/log/php_errors.log` e `/tmp` (usar `tmpfs`).
- **Testes:** `docker exec <container> id` deve retornar UID não-root; subir stack e exercitar upload de foto.
- **Compatibilidade:** Apache precisa de porta >=1024 ou `CAP_NET_BIND_SERVICE`; alternativa: manter bind da porta 80 via `USER` + `cap_add: [NET_BIND_SERVICE]` ou usar `a2enmod` e porta 8080 interna.
- **Status:** Validado. Dockerfile cria usuário `app` (UID/GID 1000), define `WORKDIR`/`USER app` e ajusta `APACHE_RUN_USER/GROUP` via `sed` no `envvars`. Compose aplica `user: "1000:1000"` + `cap_add: [NET_BIND_SERVICE]`. Testado: `docker compose exec app id` → `uid=1000(app)`; Apache/PHP rodam como `app`; stack sobe e responde HTTP 200.

---

### SEC-DOCKER-002 — Ausência de hardening do container

- **Problema:** Não há `cap_drop: ALL`, `no-new-privileges`, `read_only` nem `tmpfs`. Violação das regras §35, §36 e §37.
- **Localização:** `docker-compose.yml`.
- **Evidência:** O serviço `app` possui apenas `ports`, `environment`, `volumes`, `networks`, `restart`.
- **Impacto:** Container com capabilities desnecessárias; mitigação de escapes reduzida.
- **Implementação recomendada:**
  ```yaml
  app:
    security_opt:
      - no-new-privileges:true
    cap_drop:
      - ALL
    read_only: true
    tmpfs:
      - /tmp
  ```
  Com `read_only: true`, garantir que `fotos_visitantes` e `php_errors.log` estejam em volumes graváveis. Em modo de produção, a rotação de logs do Apache precisa de volume ou `ApaLogs` em `/dev/stdout`.
- **Testes:** `docker inspect <container> | grep -i cap`; validar upload e login.
- **Status:** Validado. Compose do `app` aplica `security_opt: no-new-privileges`, `cap_drop: [ALL]` (com `cap_add: [NET_BIND_SERVICE]` para bind da porta 80), `read_only: true` e `tmpfs` para `/tmp`, `/var/run/apache2` e `/var/lock/apache2`. `apache.conf` e `php.ini` passam a logar em `/proc/self/fd/{1,2}` (capturado pelo Docker). Stack testada com upload e HTTP 200.

---

### SEC-DOCKER-003 — Sem limites de recursos

- **Problema:** Nenhum limite de CPU/memória/PIDs nos serviços. Violação §40.
- **Localização:** `docker-compose.yml`.
- **Evidência:** Ausência de `mem_limit`, `cpus`, `pids_limit`.
- **Impacto:** DoS por consumo de recursos.
- **Implementação recomendada:** Adicionar limites dimensionados ao consumo real:
  ```yaml
  app:
    mem_limit: 512m
    cpus: "1.0"
    pids_limit: 256
  db:
    mem_limit: 1g
    pids_limit: 512
  ```
- **Status:** Validado. Limites adicionados em `docker-compose.yml`: `app` com `mem_limit: 512m`, `cpus: "1.0"`, `pids_limit: 256`; `db` com `mem_limit: 1g`, `pids_limit: 512`. `docker compose config` validado.

---

### SEC-DOCKER-004 — Sem rotação de logs Docker

- **Problema:** Logs do Docker sem rotação. Violação §41.
- **Localização:** `docker-compose.yml`.
- **Evidência:** Ausência de `logging:` nos serviços.
- **Implementação recomendada:**
  ```yaml
  logging:
    driver: json-file
    options:
      max-size: "10m"
      max-file: "5"
  ```
- **Status:** Validado. `logging` com `json-file`, `max-size: "10m"`, `max-file: "5"` adicionado em `app` e `db` no `docker-compose.yml`. `docker compose config` validado.

---

### SEC-DOCKER-005 — MySQL publicado no host

- **Problema:** `db` publica porta `3307:3306` no host. Violação §38/§39 ("Não publicar 3306 externamente").
- **Localização:** `docker-compose.yml:34-35`.
- **Evidência:** `ports: - "${DB_PORT:-3307}:3306"`.
- **Impacto:** Superfície de ataque adicional; banco alcançável fora da rede interna.
- **Implementação recomendada:** Remover o bloco `ports` do serviço `db`. Acessos administrativos devem ocorrer via `docker exec` ou rede interna.
- **Testes:** `docker compose up -d`; confirmar `docker ps` sem porta publicada para o banco; validar migração e login.
- **Status:** Validado. Bloco `ports` do serviço `db` removido do `docker-compose.yml`. `docker compose config` validado; `docker ps` confirmado sem porta publicada para o banco.

---

### SEC-DOCKER-006 — phpMyAdmin exposto com imagem `latest`

- **Problema:** Serviço `phpmyadmin:latest` publicado em `8081`. Viola §31 (banco isolado), §44 (não usar `latest`) e §58 (separação de ambientes). Em produção concede acesso administrativo ao banco.
- **Localização:** `docker-compose.yml:54-67`.
- **Evidência:** `image: phpmyadmin:latest` + `ports: "${PMA_PORT:-8081}:80"`.
- **Implementação recomendada:** Extrair `phpmyadmin` para um compose de desenvolvimento (`docker-compose.dev.yml`), com imagem fixada e sem publicação externa na configuração de produção. Documentar o override.
- **Testes:** `docker compose up -d` (produção) sem o serviço; `docker compose -f docker-compose.dev.yml up` (dev) com phpMyAdmin.
- **Status:** Validado. `phpmyadmin` extraído para `docker-compose.dev.yml` (imagem fixada `phpmyadmin:5.2`, porta `${PMA_PORT:-8081}:80`); removido do compose de produção. README atualizado com o override.

---

### SEC-DOCKER-007 — Rede única; banco não isolado

- **Problema:** Todos os serviços em `visitantes-net` (bridge comum). Violação §38 (redes internas para o banco).
- **Localização:** `docker-compose.yml:75-77`.
- **Evidência:** `networks: visitantes-net: driver: bridge` usado por `app`, `db` e `phpmyadmin`.
- **Implementação recomendada:**
  ```yaml
  networks:
    frontend:
    backend:
      internal: true
  ```
  `app` em `frontend` + `backend`; `db` somente em `backend` (internal).
- **Status:** Validado. Redes `frontend` (bridge) e `backend` (`internal: true`). `app` em ambas; `db` somente em `backend`; phpMyAdmin (dev) em `frontend`+`backend` para alcançar o banco e ser acessível no host. Validado via `docker network inspect`.

---

### SEC-PASS-001 — Algoritmo de hash de senha

- **Problema:** Uso de `PASSWORD_BCRYPT` e `PASSWORD_DEFAULT` em vez de `PASSWORD_ARGON2ID`. Violação §11.
- **Localização:** `docker/migrate.php:189,192,195`, `scripts/cadastroUsuario.php:42`, `scripts/trocar_senha.php:35`, `scripts/editarUsuario.php:79`, `scripts/create_default_users.php:73`.
- **Evidência:** `password_hash(..., PASSWORD_BCRYPT, ['cost' => 10])` e `PASSWORD_DEFAULT`.
- **Impacto:** Senhas novas não utilizam o algoritmo recomendado (Argon2id). Senhas existentes em bcrypt continuarão válidas via `password_verify`.
- **Implementação recomendada:** Trocar para `password_hash($senha, PASSWORD_ARGON2ID)` em todos os pontos. Manter `password_verify` (compatível com hashes antigos). Verificar a extensão `sodium` (disponível no PHP 8.4/8.5 usado pelo projeto).
- **Testes:** Criar usuário, autenticar, alterar senha; inspecionar hash no banco (prefixo `$argon2id$`).
- **Compatibilidade:** Requer PHP >= 8.4 com libsodium (imagem Docker atual: PHP 8.5). Hashes bcrypt existentes permanecem verificáveis.
- **Status:** Validado. `PASSWORD_ARGON2ID` aplicado em `docker/migrate.php:189,192,195`, `scripts/cadastroUsuario.php:42`, `scripts/trocar_senha.php:35`, `scripts/editarUsuario.php:79`, `scripts/create_default_users.php:73`. `php -l` OK em todos; baseline atual com 153 testes passando.

---

### SEC-COOKIE-001 — Cookie de sessão não segue o padrão `__Host-`

- **Problema:** Cookie de sessão nomeado `PHPSESSID`, sem prefixo `__Host-`, com `SameSite=Lax` e `Secure` condicional. Violação §9 (exige `__Host-SID`, `Secure`, `HttpOnly`, `SameSite=Strict`, `Path=/`, sem `Domain`).
- **Localização:** `config/app.php:36-57`, `inc/security.php:1-5`.
- **Evidência:** `ini_set('session.name')` não definido (default `PHPSESSID`); `'samesite' => 'Lax'`; `'secure' => $secureCookie` (condicional).
- **Impacto:** Menor proteção contra session fixation/roubo em cenários de proxy com HTTP; prefixo `__Host-` não aplicado.
- **Implementação recomendada:** Em produção (HTTPS), definir:
  ```ini
  session.name = __Host-SID
  session.cookie_secure = 1
  session.cookie_samesite = Strict
  session.cookie_httponly = 1
  ```
  No PHP: `session_name('__Host-SID')` antes de `session_start()`, e manter `setcookie` com `Secure` + `SameSite=Strict` em `clearLocalSessionState()`.
- **Atenção:** `__Host-` exige `Secure` obrigatoriamente; em desenvolvimento via HTTP o cookie não será enviado. Usar `Strict` apenas quando a app roda em HTTPS, ou manter `Lax` em dev com justificativa registrada.
- **Testes:** `curl -I` no login deve exibir `Set-Cookie: __Host-SID=...; Secure; HttpOnly; SameSite=Strict; Path=/` em HTTPS.
- **Status:** Validado. `configureSessionSecurity()` em `config/app.php` agora usa `session_name()` `__Host-SID` em HTTPS (mantém `PHPSESSID` em HTTP dev), `SameSite=Strict` em HTTPS (`Lax` em dev), `Secure` conforme `isHttpsRequest()`. `php -l` OK.

---

### SEC-SESS-001 — Modelo de sessões não contempla expiração/revogação na tabela

- **Problema:** A tabela `user_sessions` usa flag `active` e `last_seen`, sem `expires_at`/`revoked_at`/`last_activity_at` e sem expiração absoluta. Divergência da estrutura mínima §8.
- **Localização:** `docker/migrate.php:76-90`, `inc/single_session.php`.
- **Evidência:** `user_sessions` possui `active TINYINT`, `last_seen`, sem colunas de expiração/revogação; timeout é somente por inatividade (`SESSION_TIMEOUT`).
- **Impacto:** Sessões inativas dependem de GC por inatividade; não há expiração absoluta nem trilha de revogação.
- **Implementação recomendada:** Adicionar colunas `expires_at DATETIME`, `last_activity_at DATETIME`, `revoked_at DATETIME NULL` na migração (com `ALTER TABLE` idempotente). Registrar `expires_at = NOW() + INTERVAL SESSION_TIMEOUT SECOND`. Considerar expiração absoluta (ex.: 12h) além do timeout de inatividade. Manter o conceito de sessão única (política atual).
- **Testes:** Criar sessão; simular expiração; validar que `isUniqueSessionValid` rejeita expirada/revogada.
- **Status:** Validado. A migração cria `expires_at`, `last_activity_at` e
  `revoked_at` em `user_sessions`; `inc/single_session.php` aplica expiração por
  inatividade, expiração absoluta (`SESSION_ABSOLUTE_LIFETIME`), revogação em
  logout/novo login/concorrência e `purgeExpiredSessions()` no login.

---

### SEC-RATE-001 — Rate limiting limitado ao login

- **Problema:** Rate limiting existe apenas no login. Violação §24 (exige também para downloads, endpoints administrativos, operações de alto custo e APIs).
- **Localização:** `scripts/login_submit.php` (única implementação), `api/get_photo.php`, endpoints admin.
- **Evidência:** `api_bootstrap.php` não aplica rate limit; `get_photo.php` e endpoints de exportação sem controle.
- **Impacto:** Abuso de download de fotos e exportações.
- **Implementação recomendada:** Criar helper genérico de rate limiting (tabela `rate_limits` com chave por IP+rota+janela), aplicar em `get_photo` (ex.: 60 req/min/IP), exportações e endpoints admin. Registrar evento `RATE_LIMIT_TRIGGERED`.
- **Status:** Validado. A tabela `rate_limits` e o helper `inc/rate_limit.php`
  aplicam janela deslizante por IP+rota em `get_photo`, exportações, cadastro
  de visitante e rotas administrativas POST; violações geram
  `RATE_LIMIT_TRIGGERED` na auditoria. **Ressalva:** atrás do nginx,
  `getClientIp()` usa apenas `REMOTE_ADDR`; sem `mod_remoteip`/validação dos
  headers do proxy, todos os clientes podem ser contabilizados com o IP do
  nginx.

---

### SEC-MFA-001 — MFA (histórico, removido)

- **Problema original:** nenhum mecanismo de MFA; contas administrativas
  protegidas apenas por senha (violação da §12 da especificação).
- **Histórico:** o TOTP chegou a ser implementado em 2026-08, com tabelas
  `usuarios_mfa`/`mfa_recovery_codes`, tela de desafio e `VISITORPASS_MFA_KEY`.
- **Estado atual (2026-11-13):** o MFA foi **removido do sistema em
  2026-08-20**. Não existem mais `inc/totp.php`, `inc/mfa.php`, rotas de
  desafio/setup, tabelas novas de MFA nem a variável `VISITORPASS_MFA_KEY`.
  O login segue direto para a home após a autenticação.
- **Evidência:** `scripts/login_submit.php`, `public/index.php`,
  `docker/migrate.php`, `docker-compose.yml` e `.env.example`.
- **Status:** Removido. A exigência normativa da §12 permanece como decisão de
  produto pendente; os controles atuais incluem Argon2id, bloqueio por
  tentativas, rate limiting, sessão única e auditoria.

---

### SEC-HDR-001 — Ausência de HSTS e de reforço HTTPS no Apache

- **Problema:** Não há `Strict-Transport-Security`, e o Apache expõe apenas porta 80. Violação §20/§21.
- **Localização:** `docker/apache.conf`, `public/index.php:44-48`.
- **Evidência:** `public/index.php` envia CSP/nosniff/Referrer-Policy/Permissions-Policy/X-Frame-Options, mas nenhum HSTS; Apache escuta apenas `:80`.
- **Impacto:** Sem HSTS, clientes podem negociar HTTP; sem TLS no proxy, credenciais trafegam em claro.
- **Implementação recomendada:** Em produção, terminar TLS no reverse proxy (ex.: Caddy/Nginx) e enviar:
  ```http
  Strict-Transport-Security: max-age=31536000; includeSubDomains
  ```
  Configurar redirecionamento HTTP→HTTPS no proxy. No Apache dev, manter porta 80.
- **Status:** Validado no app. O `Strict-Transport-Security` é emitido por
  `public/index.php` quando `isHttpsRequest()` detecta HTTPS; a terminação TLS
  e o redirecionamento HTTP→HTTPS continuam sob responsabilidade do proxy
  reverso externo.

---

### SEC-LOG-001 — Sem eventos estruturados de auditoria e request ID

- **Problema:** Não existem eventos de log estruturados (`LOGIN_SUCCESS`, `LOGIN_FAILURE`, `LOGOUT`, `SESSION_CREATED`, `AUTHORIZATION_DENIED`) nem `request_id` de correlação. Violação §49/§50.
- **Localização:** `inc/api_bootstrap.php` (apenas `API_ERROR`), `scripts/login_submit.php`.
- **Evidência:** Apenas `error_log('API_ERROR ...')` e logs de erro de banco. Auditoria em banco existe apenas para `visitorinfo` (triggers).
- **Implementação recomendada:** Criar helper `logEvent(string $event, array $context)` que emite JSON para `php://stderr` (capturado pelo Docker) incluindo `request_id` (gerado por request), `user_id`, `ip`, `timestamp`. Aplicar nos eventos listados em §49. Nunca logar senha/token/MFA.
- **Status:** Validado. `inc/log_event.php` fornece `getRequestId()` e
  `logEvent()` com saída JSON para o stderr, aplicado a login, logout, sessão,
  autorização, rate limit e auditoria. **Ressalva:** o `request_id` é gerado no
  PHP; o nginx ainda não gera nem repassa `X-Request-ID`.

---

### QUAL-BUG-001 — INSERT executado via `$db->select()` sem retorno de `insert_id`

- **Problema:** `api/api_cadastro_visitante.php:153` chama `$db->select($sql, $params)` para uma instrução `INSERT`; `select()` não define `insert_id` (definido apenas em `insert()`).
- **Localização:** `api/api_cadastro_visitante.php:135-163`, `src/Database.php:32-62`.
- **Evidência:** `select()` retorna `success/status/data/count`; `insert()` retorna `insert_id`. O endpoint lê `$result['insert_id']` que estará indefinido → `null` no JSON de resposta.
- **Causa raiz:** Chamada incorreta do método da camada de banco (confusão entre `select` e `insert`).
- **Impacto:** O visitante é gravado (INSERT executado), porém a resposta retorna `id: null`, podendo quebrar fluxos do frontend que dependem do ID e induzir cadastros duplicados.
- **Implementação recomendada:** Usar `$db->insert($sql, $params)` e manter a checagem `$result['status'] !== 'success'`.
- **Testes:** Cadastrar visitante via API; validar que a resposta contém `id` numérico e o registro no banco. Adicionar teste de integração.
- **Status:** Validado. `api/api_cadastro_visitante.php:153` agora usa `$db->insert($sql, $params)`, mantendo a checagem `$result['status'] !== 'success'` e retornando `id` numérico. `php -l` OK; baseline atual com 153 testes passando.

---

## 4. Fases de Implementação

### Fase 0 — Quick Wins (esforço baixo, alto retorno)
1. SEC-DOCKER-005: remover porta MySQL publicada.
2. SEC-DOCKER-006: extrair phpMyAdmin para compose de dev.
3. SEC-PASS-001: trocar para `PASSWORD_ARGON2ID`.
4. SEC-COOKIE-001: cookie `__Host-SID` + `SameSite=Strict` (HTTPS).
5. QUAL-BUG-001: corrigir `$db->select()` → `$db->insert()`.
6. SEC-DOCKER-004: adicionar `logging` com rotação.
7. SEC-DOCKER-003: adicionar limites de recursos.

### Fase 1 — Hardening Docker
1. ~~SEC-DOCKER-001: usuário não-root.~~ Validado.
2. ~~SEC-DOCKER-002: `cap_drop`, `no-new-privileges`, `read_only`, `tmpfs`.~~ Validado.
3. ~~SEC-DOCKER-007: redes internas para o banco.~~ Validado.

### Fase 2 — Autenticação e Sessões
1. ~~SEC-SESS-001: expiração/revogação de sessões na tabela.~~ Validado.
2. ~~SEC-RATE-001: rate limiting genérico.~~ Validado.
3. ~~SEC-HDR-001: HSTS + TLS no proxy (produção).~~ Validado (HSTS no app; TLS depende do proxy).
4. ~~SEC-LOG-001: eventos estruturados + request_id.~~ Validado.
5. ~~SEC-MFA-001: TOTP implementado (esforço alto).~~ **Removido em 2026-08-20** — não faz parte do sistema atual; ver §6.

### Fase 3 — Validação e Testes de Segurança
- Executar testes obrigatórios da especificação (§62): autenticação (brute force, session fixation), autorização (IDOR/BOLA), web (SQLi, XSS, CSRF, path traversal), arquivos (upload inválido, executável, download sem autorização), container (usuário, capabilities, ports).
- Adicionar testes automatizados para login, sessão, upload e autorização.

---

## 4.1 Registro de Execução — Fase 0

Data de execução: 2026-08-19.

| ID | Correção | Validação |
|---|---|---|
| SEC-DOCKER-005 | `ports` removido do serviço `db` | `docker compose config` OK |
| SEC-DOCKER-006 | phpMyAdmin movido para `docker-compose.dev.yml` (`phpmyadmin:5.2`) | override de dev validado; README atualizado |
| SEC-PASS-001 | `PASSWORD_ARGON2ID` em 5 pontos de hash | `php -l` OK; hashes existentes continuam verificáveis |
| SEC-COOKIE-001 | `__Host-SID` + `SameSite=Strict` + `Secure` (HTTPS) | `php -l` OK; dev HTTP mantém `PHPSESSID`/`Lax` |
| QUAL-BUG-001 | `$db->insert()` no cadastro de visitante | `php -l` OK; `id` numérico na resposta |
| SEC-DOCKER-004 | `logging` com rotação em `app` e `db` | `docker compose config` OK |
| SEC-DOCKER-003 | `mem_limit`/`cpus`/`pids_limit` em `app` e `db` | `docker compose config` OK |

Correções adicionais de higiene (fora dos achados): chave `command:` duplicada removida em `docker-compose.yml`; falsos positivos do LSP eliminados em `scripts/logout.php` e `scripts/editarUsuario.php` (casts `(int)`/`(string)` → `intval()`/`strval()`).

Validação da suíte: `vendor/bin/phpunit` → 114 testes, 129 asserções OK.

### Fase 1 — Registro de Execução

Data de execução: 2026-08-19.

| ID | Correção | Validação |
|---|---|---|
| SEC-DOCKER-001 | Dockerfile: usuário `app` (1000:1000), `USER app`, `WORKDIR`, `APACHE_RUN_USER/GROUP=app`; compose `user: 1000:1000` + `cap_add: NET_BIND_SERVICE` | Build OK; `docker exec id` → `uid=1000(app)`; Apache/PHP como `app`; HTTP 200 |
| SEC-DOCKER-002 | `no-new-privileges`, `cap_drop: [ALL]`, `read_only: true`, `tmpfs` (`/tmp`, `/var/run/apache2`, `/var/lock/apache2`); logs Apache/PHP → `/proc/self/fd/{1,2}` | Stack sobe; upload e login validados; HTTP 200 |
| SEC-DOCKER-007 | Redes `frontend` (pública) + `backend` (`internal: true`); `db` só em `backend` | `docker network inspect`: `backend` internal com app+db; `frontend` só app; pma dev em ambas |

Nota: `phpMyAdmin` no override de dev ficou em `frontend`+`backend` porque, neste ambiente Docker, portas publicadas não são expostas em redes `internal` — ele precisa do `backend` para alcançar o banco e do `frontend` para ser alcançado pelo host.

Correções adicionais descobertas durante a validação da Fase 1 (bugs pré-existentes que impediam a stack Docker de funcionar):

- **Falta de `composer install` no Dockerfile:** `.dockerignore` excluía `vendor/`, o Dockerfile nunca instalava dependências e ainda removia `vendor/` em produção — a imagem resultante era 500 (sem `vendor/autoload.php`). Corrigido: `composer install` no build (com `--no-dev` em produção, removendo `composer` depois) e `vendor/` deixou de ser apagado. Validação: build prod OK; `vendor/autoload.php` presente na imagem prod; app dev e prod HTTP 200.
- **BOM UTF-8 (`EF BB BF`) em 6 arquivos PHP** (`public/index.php`, `scripts/login.php`, `scripts/editarUsuario.php`, `scripts/logout.php`, `inc/footer.php`, `api/session_check.php`): o BOM emitia output antes de `configureSessionSecurity()`, anulando `session_name()`/`ini_set()` da sessão — o cookie `__Host-SID` da Fase 0 (SEC-COOKIE-001) **nunca era aplicado** e o warning `session_name(): Session name cannot be changed after headers have already been sent` aparecia em toda requisição. Corrigido: BOMs removidos. Validação: login E2E OK (302 → trocar_senha), cookie de sessão emitido, zero warnings PHP nos logs.

Validação da suíte: `vendor/bin/phpunit` → 114 testes, 129 asserções OK.

### Fase 2 — Registro de Execução

Data de execução: 2026-08-19.

| ID | Correção | Validação |
|---|---|---|
| SEC-LOG-001 | `inc/log_event.php` (`getRequestId()` + `logEvent()` JSON → stderr, evento `AUDIT`); aplicado em login (LOGIN_FAILURE/LOGIN_SUCCESS), logout (LOGOUT), API (AUTHORIZATION_DENIED), sessão (SESSION_CREATED/EXPIRED/REVOKED) e rate limit | Eventos AUDIT observados nos logs do container |
| SEC-SESS-001 | `SESSION_ABSOLUTE_LIFETIME`; migração: `expires_at`, `last_activity_at`, `revoked_at` em `user_sessions`; expiração por tempo absoluto, revogação em logout/novo login/concorrência; `purgeExpiredSessions` no login | Logout → `active=0` + `revoked_at` preenchido; timeout → SESSION_EXPIRED |
| SEC-RATE-001 | Tabela `rate_limits` + `inc/rate_limit.php` (janela deslizante por IP+rota); get_photo 60/min, api_export 10/min, exportar_auditoria 10/min, api_cadastro_visitante 30/min, rotas admin POST 30/min | get_photo: 60×200 + 429; api_export: 10×200 + 429; admin POST: 429 após limite; RATE_LIMIT_TRIGGERED auditado |
| SEC-HDR-001 | `Strict-Transport-Security` enviado quando `isHttpsRequest()` (produção atrás de proxy TLS); nota no `apache.conf` | Header presente com `X-Forwarded-Proto: https`; ausente em HTTP (dev) |
| SEC-MFA-001 | Implementação TOTP (2026-08) e remoção posterior em 2026-08-20 | Removido do código, schema novo, rotas e compose; login direto para a home |

Correções adicionais descobertas na Fase 2 (bug pré-existente):

- **Redirects admin quebrados (`headers already sent`):** com `output_buffering=0`, as rotas incluem `inc/header.php` antes do script, então todo `header('Location:')` em scripts admin/user falhava (ex.: `desbloquear_login` → 500; `trocar_senha` emitia warning). Corrigido com `ob_start()` no topo de `public/index.php`. Validação: `desbloquear_login` → 302 OK; trocar_senha → 302 OK.
- **`revoked_at = '0000-00-00'`:** comparação contra valor zero-date no `isUniqueSessionValid` quebrava sob `NO_ZERO_DATE` (MySQL 8 default sql_mode) → 500 em `trocar_senha`. Removida a comparação legada (revogado agora usa NULL). Validação: login E2E OK sem erros de banco.

Configuração exigida (histórico): `VISITORPASS_MFA_KEY` (64 hex chars = 32
bytes) era usada para criptografar secrets TOTP. A variável e os arquivos de
MFA foram removidos em 2026-08-20 e não existem no estado atual.

Validação da suíte: `vendor/bin/phpunit` → 114 testes, 129 asserções OK.

### Fase 3 — Registro de Execução

Data de execução: 2026-08-19.

| ID | Validação | Resultado |
|---|---|---|
| SEC-AUTH-001 | Brute force: 5 falhas bloqueiam por 15 min (`login_attempts`, `MAX_LOGIN_ATTEMPTS=5`, `MAX_IP_LOGIN_ATTEMPTS=15` por IP) | **Bug corrigido — ver abaixo.** Após fix, 5 falhas → `user:5:<lock_until>`; 6ª tentativa com senha correta → bloqueada (redirect `index.php?page=login` + mensagem "Acesso temporariamente bloqueado"); LOGIN_FAILURE/account_locked auditados. |
| SEC-AUTH-002 | Session fixation: `session_regenerate_id(true)` em `finalizeLoginSession()` | **Validado** — session_id trocado após login (ex.: `fb0d…` → `630309…`); sessão antiga destruída no servidor (`session_regenerate_id(true)`); cookie atualizado. |
| SEC-AUTHZ-001 | IDOR/BOLA em rotas API e admin | **Bug corrigido — ver abaixo (SEC-AUTHZ-001).** Após fix: user→`api_estatisticas`/`exportar_auditoria` 403; supervisor→`get_auditoria` 403, `api_estatisticas` 200; admin (autenticado)→rotas admin 200; eventos `AUTHORIZATION_DENIED` auditados. IDOR em `api_detalhes`/`reactivate_visitor`/`get_photo`: by-design (dados de visitante são compartilhados, sem modelo de ownership; `vid` é `(int)`). |
| SEC-WEB-001 | SQLi / XSS / CSRF / path traversal | **Validado** — SQLi: todos os acessos via prepared statements (`:param`), probes (`' OR 1=1--`, `UNION`, `DROP TABLE`) sem bypass e sem erro DB; XSS: entradas exibidas com `htmlspecialchars(ENT_QUOTES)` (ex.: `scripts/login.php:141`), payload `<script>alert(1)</script>` não refletido; CSRF: token obrigatório em POST (`validateCsrfToken`), sem token → rejeitado (HTTP 419/403 — ver bug SEC-WEB-001 abaixo); path traversal: `get_photo` usa `(int)$vid` e `isValidImage()` (`getimagesize`), `foto_caminho` gerado no servidor. |
| SEC-FILE-001 | Upload inválido/executável; download sem autorização | **Validado** — upload exige `data:image/...;base64`, valida `getimagesizefromstring`, MIME×extensão conferem (jpg≠gif rejeitado), nome de arquivo gerado no servidor (`visitante_<uniqid>_<time>.<ext>`), tamanho ≤ 5 MB; shell PHP disfarçado de JPEG rejeitado ("Conteudo de imagem invalido"); poliglota JPEG+PHP aceito mas **inerte** (extensão `.jpeg` — handler PHP só casa `\.php$` —, fora do docroot, servido via `readfile()` com `Content-Type: image/jpeg`); acesso direto bloqueado (403). |
| SEC-DOCKER-008 | Container: usuário, capabilities, ports | **Validado (revalidação)** — Apache/PHP rodam como `app` (uid 1000); filesystem `read_only` (escrita falha fora de `fotos_visitantes`); `cap_drop: ALL` + `NET_BIND_SERVICE`; `no-new-privileges`; `mem_limit/cpus/pids_limit`; `db` sem porta publicada (só rede interna); porta pública: 8000 (nginx), app sem porta publicada. |

**Bug de segurança encontrado (SEC-AUTH-001): timezone incoerente entre PHP e MySQL.**

- **Sintoma:** após 5 falhas de login, a 6ª tentativa com a senha correta autenticava (deveria estar bloqueada).
- **Causa raiz:** `registerLoginFailure()` grava `lock_until` com `date('Y-m-d H:i:s', time()+900)` em hora local do PHP (`America/Sao_Paulo`, ex.: `18:45:17`), mas `cleanupOldLoginAttempts()` compara com `NOW()` do MySQL, que roda em UTC (ex.: `21:30:17`). Como `18:45 < 21:30`, a linha bloqueada era **apagada na tentativa seguinte**, contornando o bloqueio. O mesmo desalinhamento afetava `expires_at` de sessões (`isUniqueSessionValid`/`purgeExpiredSessions`, sessões viviam +3h) e `window_started_at` do rate limit.
- **Correção:** `src/Database.php` agora executa `SET time_zone = :offset` (`date('P')`, ex. `-03:00`) em toda conexão; `docker/migrate.php` aplica o mesmo. Assim `NOW()`/`CURRENT_TIMESTAMP`/`FROM_UNIXTIME` ficam coerentes com `date()`/`strtotime()` do PHP.
- **Validação:** E2E reexecutado — 5 falhas → lock gravado; 6ª tentativa com senha correta → bloqueada com mensagem; login normal ainda funciona (302 → trocar_senha).

**Bug de segurança encontrado (SEC-AUTHZ-001): BOLA nas rotas de API.**

- **Sintoma:** qualquer usuário autenticado (nível `user`) acessava endpoints de API restritos (estatísticas, auditoria).
- **Causa raiz:** `apiRequireEndpointAccess()` derivava o endpoint via `basename($_SERVER['SCRIPT_NAME'], '.php')`, que sob o roteador (`index.php?page=api/…`) é sempre `index` — fora do mapa de `config/permissions.php`, caía no fallback `'user'`. Todas as APIs eram tratadas como acesso de usuário comum.
- **Correção:** `inc/api_bootstrap.php` agora extrai o endpoint de `$_GET['page']` (remove prefixo `api/`), com fallback para `SCRIPT_NAME` se vazio.
- **Validação:** E2E — user→`api_estatisticas`/`exportar_auditoria` 403; supervisor→`get_auditoria` 403, `api_estatisticas` 200; admin→rotas admin 200; `AUTHORIZATION_DENIED` auditados.

**Achado menor (SEC-WEB-001): status 419 não padrão reescrito para 500 pelo Apache.**

- **Sintoma:** rejeição de CSRF devolvia `{"success":false,"error":"CSRF token invalido"}` com HTTP 500 no cliente, embora o access log do Apache registrasse 419.
- **Causa raiz:** `http_response_code(419)` (código não padrão) é reescrito pelo Apache/mod_php para 500 no envio ao cliente (códigos padrão 401/403/429 passam corretamente).
- **Correção:** rejeição de CSRF agora usa HTTP 403 em `src/Middleware/CsrfMiddleware.php`, `inc/api_bootstrap.php` (`apiRequireCsrf`) e `api/renovar_sessao.php`. O controle de segurança continuava funcionando (requisição negada, sem efeito colateral); 403 é semântico e passa pelo Apache.
- **Validação:** POST sem token → `HTTP/1.1 403 Forbidden` + `{"success":false,"error":"CSRF token invalido"}`; Apache loga 403; login normal continua funcionando (302 → trocar_senha).

**Achado menor (SEC-DOCKER-009): phpMyAdmin de dev ativo em ambiente de produção.**

- **Sintoma:** `visitantes-pma` (phpMyAdmin 5.2) publicado em `0.0.0.0:8081` — o override `docker-compose.dev.yml` foi aplicado no ambiente atual (`VISITORPASS_ENV=production`), contrariando a nota "NAO utilizar em producao" do próprio arquivo.
- **Correção:** parar/remover o serviço (ex.: `docker compose -f docker-compose.yml up -d app db` sem o override de dev; `docker rm -f visitantes-pma`).
- **Validação:** pma exige login (cookie auth); mesmo assim a superfície de ataque extra não deve permanecer em produção.

Validação da suíte: `vendor/bin/phpunit` → 121 testes, 148 asserções OK.

**Testes automatizados adicionados (login/sessão/upload/autorização).**

- `tests/LoginSecurityTest.php` (7 testes): `getLoginAttempt`, `registerLoginFailure` (lock no máximo), `clearLoginAttempts`, `cleanupOldLoginAttempts`.
- `tests/SessionTest.php` (13 testes): token aleatório, hash SHA-256, `registerUniqueSession`/`invalidateUniqueSession`, `isUniqueSessionValid` (ativa/expirada/inativa/revogada/token divergente), `purgeExpiredSessions`, `finalizeLoginSession` (regenera session_id, não persiste hash de senha).
- `tests/ApiPermissionsTest.php` (8 testes): `resolveApiEndpoint` (rota via `page`, fallback via `SCRIPT_NAME`), mapa de `config/permissions.php` cobre todos os endpoints de `api/`, níveis válidos, endpoints supervisor/admin restritos, fallback `user`.
- `tests/FotoUploadTest.php` (10 testes): PNG válido salvo com caminho relativo e nome controlado pelo servidor; shell disfarçado de JPEG e poliglota rejeitados; extensão×conteúdo conferido; base64 inválido; tipo não suportado; tamanho excedente; vazio → `null`.
- Refator associado: validação de upload extraída de `api/api_cadastro_visitante.php` para `inc/foto_helpers.php` (`salvarFotoBase64`); `resolveApiEndpoint` movido para `inc/security.php`.

Validação: `vendor/bin/phpunit` → 153 testes, 240 asserções OK; E2E de upload (com e sem foto) validado após o rebuild.

---

## 5. Definition of Done

Para cada achado, antes de declarar concluído:

- [ ] Problema reproduzido/confirmado.
- [ ] Evidência registrada.
- [ ] Causa raiz identificada.
- [ ] Correção implementada.
- [ ] Testes executados (suite existente continua passando: `vendor/bin/phpunit`).
- [ ] Segurança reavaliada.
- [ ] Compatibilidade validada (Docker, MySQL 8.4, PHP 8.4/8.5).
- [ ] Documentação atualizada (este plano e README quando aplicável).
- [ ] Finding atualizado para "Validado".

---

## 6. Limitações

- Ambiente de produção não acessível; validação limitada ao Docker local.
- MFA (SEC-MFA-001): **removido do sistema em 2026-08-20**; não há TOTP,
  recovery codes nem `VISITORPASS_MFA_KEY` no código atual. A exigência da §12
  permanece como decisão de política/escopo do produto.
- TLS/HSTS (SEC-HDR-001) dependem do reverse proxy em produção (fora do repositório); HSTS é emitido pelo app quando a requisição é HTTPS.
- IP real atrás do proxy: `getClientIp()` usa `REMOTE_ADDR` e o Apache não usa `mod_remoteip`; rate limiting por IP e IP de auditoria podem registrar o IP do nginx.
- `request_id` não é propagado pelo nginx (`X-Request-ID`); a correlação usa apenas o ID gerado no PHP.
- Imagens `nginx:stable-alpine` e `composer:2` usam tags de linha; fixar patch/digest é melhoria pendente.
- `docker-compose.yml` mantém fallbacks de credenciais de desenvolvimento; em produção, senhas fortes devem ser definidas no `.env`.
- O CI não executa scanner de imagem (Trivy/Grype/Docker Scout).
- Análise de performance e testes E2E não executados nesta etapa (registrados como "Não auditado").
