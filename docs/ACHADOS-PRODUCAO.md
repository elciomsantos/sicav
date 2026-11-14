# Registro de achados — prontidão para produção (SICAV)

- **Data:** 2026-11-14
- **Commit analisado:** `18e6d1d` (`fix/dev-development`)
- **Alvo:** Ubuntu Server + Docker Engine + Docker Compose
- **Documento completo:** [ANALISE-PRONTO-PRODUCAO.md](ANALISE-PRONTO-PRODUCAO.md)
- **Legenda de severidade:** P0 = bloqueador de go-live · P1 = alto, corrigir na primeira semana · P2 = melhoria contínua
- **Status inicial de todos os itens:** Aberto

## Resumo

| Severidade | Quantidade |
|---|---:|
| P0 (bloqueador) | 6 |
| P1 (alto) | 11 |
| P2 (melhoria) | 11 |
| **Total** | **28** |

---

## P0 — Bloqueadores de go-live

| ID | Área | Achado | Evidência | Impacto | Ação recomendada | Status |
|---|---|---|---|---|---|---|
| P0-01 | Deploy/Git | `main` não contém o hardening mais recente e contém artefatos de desenvolvimento | `git merge-base --is-ancestor 18e6d1d main` → 1; `main` em `21b339a` com `phpactor.phar` (4,8 MB), `phpactor-lsp` e `phpactor/phpactor` em `composer.json` sem atualizar `composer.lock` | Deploy pela `main` publica versão sem as correções de IP real, `X-Request-ID`, digests e credenciais obrigatórias; CI/`composer validate` pode falhar | Abrir PR `fix/dev-development` → `main`, remover binários/wrapper do phpactor, corrigir o lock, rodar CI e só então taguear | Aberto |
| P0-02 | Configuração | `.env.example.docker` define `APP_ENV=development` | `.env.example.docker:10`; `docker-compose.yml:40`; `docker/migrate.php:220-231`; `Dockerfile:46-65` | Deploy seguindo o exemplo sobe imagem de dev e cria usuário `supervisor` com senha pública | Trocar o default para `production` ou criar `.env.production.example`; preflight que recusa `APP_ENV != production` | Aberto |
| P0-03 | Autenticação | Credenciais iniciais conhecidas e fallback de senha fraca | `docker/migrate.php:223` (`Admin@2026Temp!`); `README.md:245-247`; fallback `visitorpassword` em `docker/entrypoint.sh:28` e `docker/migrate.php:11` | Quem fizer o primeiro login antes do responsável assume o admin com credencial pública | Exigir `VISITORPASS_ADMIN_INITIAL_PASSWORD` ou gerar senha aleatória exibida uma única vez; remover fallbacks; criar admin antes de liberar a porta | Aberto |
| P0-04 | Rede/TLS | Produção sem HTTPS; nginx apenas em `:80` publicado em todas as interfaces | `deploy/nginx/default.conf`; `docker-compose.yml:80-81`; `docs/ESPECIFICAÇÃO-SEGURANÇA.md` §21 | Credenciais e cookies trafegam em claro; requisito normativo não atendido para acesso externo | Para rede isolada: decisão formal de risco + firewall restrito. Para acesso externo: proxy TLS, publicar nginx só em loopback, redirecionar HTTP→HTTPS e configurar `real_ip` no nginx do Compose | Aberto |
| P0-05 | Backup/DR | Backup e restauração manuais, sem automação, criptografia, retenção ou restore testado | `README.md:279-316`; não existe `scripts/backup.sh`; banco e fotos no mesmo drive `/mnt/visitantes` | Perda do drive ou erro humano pode causar perda definitiva de dados; PII sem criptografia no backup | Criar script com `flock` + `mysqldump --single-transaction --routines --triggers --events`, cópia de fotos, checksum, criptografia, envio offsite, retenção e timer systemd; executar restore de teste | Aberto |
| P0-06 | Armazenamento | Se `/mnt/visitantes` não estiver montado, o Docker cria a pasta no disco do sistema | `docker-compose.yml:44,117`; aviso apenas textual em `README.md:182-184` | Dados do banco/fotos passam a residir no disco errado, com risco de perda e falta de espaço | Guarda no deploy (`findmnt -M /mnt/visitantes`), unit systemd com `RequiresMountsFor`/`ConditionPathIsMountPoint` e alerta de disco | Aberto |

---

## P1 — Riscos altos

| ID | Área | Achado | Evidência | Impacto | Ação recomendada | Status |
|---|---|---|---|---|---|---|
| P1-01 | Capacidade | Apache MPM prefork sem tuning com `mem_limit: 512m` e PHP `memory_limit=256M` | `docker-compose.yml:72`; `docker/php.ini:6`; padrão da imagem `StartServers 5` / `MaxRequestWorkers 150` | Poucas requisições simultâneas podem estourar memória e sofrer OOM kill | Limitar `MaxRequestWorkers`/`ServerLimit` (ex.: 10) ou elevar `mem_limit`; validar com teste de carga | Aberto |
| P1-02 | Disponibilidade | Apenas o `db` tem healthcheck; `app` e `nginx` não | `docker-compose.yml:118-123`; `nginx.depends_on` sem condição | App travado continua “Up”; nginx responde 502 sem detecção | Adicionar healthcheck HTTP em `app` e `nginx`; usar `depends_on: condition: service_healthy` | Aberto |
| P1-03 | Inicialização | `entrypoint.sh` interpola a senha do banco dentro de código PHP e não tem timeout | `docker/entrypoint.sh:26-36` | Senha com aspas/`$`/backtick quebra o comando ou permite injeção; banco indisponível gera loop infinito | Ler variáveis com `getenv()` no PHP, remover fallback e limitar tentativas com falha explícita | Aberto |
| P1-04 | Sessão | `session.gc_maxlifetime` fixo em 3600, menor que a vida absoluta padrão de 43200 | `docker/php.ini:12`; `config/app.php:15-16` | PHP pode coletar sessão antes do tempo configurado se o timeout for aumentado | Alinhar para `>=` vida absoluta (ex.: 86400) ou gerar no entrypoint a partir da env | Aberto |
| P1-05 | Banco | Binlog sem expiração e healthcheck dependente da senha root | `docker-compose.yml:107,119` | Binlog pode encher o drive; rotação da senha root quebra o healthcheck | Definir `--binlog-expire-logs-seconds` (ou desabilitar binlog sem réplica/PITR); usar `mysqladmin ping` sem senha ou arquivo de credenciais | Aberto |
| P1-06 | Observabilidade | Sem monitoramento de disponibilidade, disco, banco, backup ou certificado | Ausência de scripts/configuração de monitoramento no projeto | Incidentes são descobertos por usuários; falha de backup passa despercebida | Uptime Kuma/`curl` + timer + alerta; Prometheus/node_exporter/cAdvisor se disponível; alertas para containers, HTTP, disco, backup e TLS | Aberto |
| P1-07 | Auditoria | Eventos `AUDIT` só existem no `json-file` com rotação de 50 MB por serviço | `docker-compose.yml` logging; `inc/log_event.php` | Eventos de segurança antigos são perdidos; pode haver requisito legal de retenção | Enviar logs para arquivo/journald remoto/Loki com retenção definida (ex.: 90 dias) | Aberto |
| P1-08 | Host | Runbook não cobre hardening do Ubuntu | `README.md` seção de deploy | Superfície de ataque do host e risco de acesso indevido | Usuário dedicado, SSH por chave, root desabilitado, UFW, `unattended-upgrades`, `fail2ban`/CrowdSec, NTP, swap e revisão do socket Docker | Aberto |
| P1-09 | Segredos | Rotação de senhas do MySQL não documentada; segredos em `.env` | `.env`; `docker-compose.yml:36,110,113` | Trocar senha no `.env` após o primeiro boot não altera a senha no banco e pode derrubar o serviço | Documentar `ALTER USER`, atualizar healthcheck, manter `.env` `root:root 600`, backup criptografado e evitar `docker compose config` em logs | Aberto |
| P1-10 | LGPD | Dados pessoais (CPF, telefone, e-mail e foto) sem política de retenção/anonimização documentada | `docker/migrate.php` (schema); endpoints de cadastro | Risco de não conformidade e exposição indevida de PII | Definir base legal, retenção, exclusão/anonimização, criptografia do disco/backup e revisão de acessos | Aberto |
| P1-11 | Banco | Migrações idempotentes, mas sem versionamento/ordem | `docker/migrate.php` | Evoluções futuras de schema ficam arriscadas e difíceis de auditar | Criar tabela `schema_migrations` e arquivos SQL numerados aplicados uma única vez | Aberto |

---

## P2 — Melhorias contínuas

| ID | Área | Achado | Evidência | Impacto | Ação recomendada | Status |
|---|---|---|---|---|---|---|
| P2-01 | Imagem | Imagem de produção com 1,12 GB; Node.js instalado incondicionalmente | `Dockerfile` (build de produção); build real medido em 1,12 GB; Node ~95 MB + módulos ~16 MB | Maior tempo de pull/deploy, mais CVEs e superfície de ataque | Build multi-stage: compilar extensões e rodar testes em estágios separados; instalar Node só no estágio de testes | Parcial — Node removido da imagem de produção; multi-stage ainda pendente |
| P2-02 | Imagem | `tests/`, `docs/`, `phpunit.xml`, `phpstan.neon`, `psalm.xml`, `docker-compose*.yml` permanecem em `/var/www/html` | Inspeção da imagem de produção | Superfície desnecessária e risco residual se o DocumentRoot for mal configurado | Remover artefatos de dev da imagem final via multi-stage/`.dockerignore` | Aberto |
| P2-03 | Performance | OPcache habilitado, mas sem tuning | `docker/php.ini`; imagem base com `opcache.enable=On` | Menor desempenho de PHP em produção | `opcache.validate_timestamps=0`, memória 128 MB, `interned_strings_buffer=16`, `max_accelerated_files=10000`; reiniciar no deploy | Aberto |
| P2-04 | Performance | Sem compressão no nginx e imagens estáticas pesadas | `deploy/nginx/default.conf`; `public/imagens` ~4,3 MB (`avatar.png` 2,1 MB, `logo_sts.png` 1,3 MB, `tela_fundo.png` 0,95 MB) | Primeiro carregamento mais lento | Habilitar gzip para texto/JSON/JS/CSS/SVG; converter/redimensionar imagens para WebP | Aberto |
| P2-05 | Banco | MySQL sem tuning explícito | `docker-compose.yml:104-133` | Uso de memória/desempenho subótimo conforme o volume cresce | Avaliar `innodb_buffer_pool_size`, `max_connections` e `slow_query_log` com base no uso real | Aberto |
| P2-06 | Autenticação | MFA foi removido do produto | `README.md:496-508`; `docs/PLANO_IMPLEMENTACAO.md` | Contas admin protegidas apenas por senha | Reavaliar MFA ou integrar SSO/2FA no proxy corporativo; registrar decisão de risco | Aberto |
| P2-07 | Contas | Risco de uso compartilhado do usuário `admin` padrão | `docker/migrate.php:217-236` | Falta de rastreabilidade individual e maior impacto em caso de vazamento | Criar uma conta por administrador e desativar o admin padrão após o bootstrap | Aberto |
| P2-08 | Borda | Sem WAF/rate limiting no proxy | `deploy/nginx/default.conf` | Ataques volumétricos e scanners chegam direto à aplicação | CrowdSec/ModSecurity no proxy TLS, se houver exposição externa | Aberto |
| P2-09 | Disponibilidade | Atualização recria o container e causa janela curta de indisponibilidade | Processo `docker compose up -d --build` | Downtime em cada deploy | Documentar janela de manutenção; para HA, réplica + load balancer + sessão compartilhada | Aberto |
| P2-10 | Capacidade | Sem teste de carga/capacidade documentado | Ausência de evidência no repositório | Limites de memória/CPU podem ser inadequados ao uso real | Medir login, listagem e exportação com volume esperado; ajustar limites | Aberto |
| P2-11 | Rede | Se houver proxy TLS à frente do nginx, o IP real volta a se perder sem `real_ip` | `deploy/nginx/default.conf`; `docker/entrypoint.sh` (mod_remoteip só confia no nginx interno) | Rate limiting e auditoria passam a ver o IP do proxy TLS | Configurar `set_real_ip_from` + `real_ip_header X-Forwarded-For` + `real_ip_recursive on` no nginx do Compose | Aberto |

---

## Achados positivos (manter)

- Senhas com Argon2id; sessão regenerada no login e token armazenado em hash.
- CSRF em web e API; CSP com nonce; HSTS condicional; `nosniff`; `X-Frame-Options`.
- SQL parametrizado em toda a camada de banco; upload validado por MIME/tamanho/extensão.
- Rate limiting por IP+rota em login, admin, exportações e fotos.
- Sessão única, timeout por inatividade e expiração absoluta.
- Auditoria por triggers + eventos `AUDIT` correlacionados por `request_id`.
- IP real do cliente via `mod_remoteip`, com configuração gerada no boot.
- `app` non-root (UID 1000), `read_only`, `cap_drop: ALL`, `no-new-privileges`, tmpfs e limites.
- `db` sem porta publicada e em rede `backend` com `internal: true`.
- Imagens fixadas por digest; CI com PHPUnit, PHPStan, Psalm, Trivy, ShellCheck, Hadolint e smoke test.
- `.env` fora do Git e da imagem; `vendor/` não versionado; logs Docker rotacionados.

---

## Correções aplicadas na CI (2026-11-14)

O workflow **Infra** falhava nos quatro jobs de scan Trivy. Correções aplicadas:

| Scan | Antes | Correção | Depois |
|---|---|---|---|
| `app` | 10 achados Debian + 22 `node-pkg` | `apt-get upgrade` no build (apache2 2.4.69→2.4.71, linux-libc-dev 6.12.115→6.12.117) e Node.js/npm instalados apenas no build de desenvolvimento | 0 HIGH/CRITICAL |
| `nginx` | 3 HIGH em `libxslt 1.1.43-r3` | `docker/nginx.Dockerfile` sobre o digest fixado, com `apk upgrade` (libxslt→1.1.43-r4); Compose passou a construir a imagem | 0 HIGH/CRITICAL |
| `mysql` | 9 Python (`mysqlsh`) + 26 Go (`gosu`) | `.trivyignore.yaml` com justificativa por componente e validade até **2027-02-28** (ferramentas auxiliares não usadas pela aplicação) | 0 HIGH/CRITICAL |
| `phpmyadmin` | 12 Debian + 12 Composer | Imagem existe apenas no `docker-compose.dev.yml`; não é publicada em produção e saiu do gate de CI (o override continua validado no job de Compose) | 24 achados na imagem de desenvolvimento, fora do pipeline de produção |

Também foram alinhados os scans aos mesmos digests usados no deploy: a matriz do CI não usa mais tags soltas, e `scripts/pin-images.sh` atualiza `Dockerfile`, `docker/nginx.Dockerfile`, `docker-compose.yml`, `docker-compose.dev.yml` e `.github/workflows/ci.yml`.

Pendências relacionadas: rebuild multi-stage da imagem da aplicação (P2-01/P2-02), atualização do digest/versão do MySQL quando a Oracle corrigir `mysqlsh`/`gosu` e revisão das exceções antes do vencimento em 2027-02-28.

---

## Ordem sugerida de execução

1. **P0-01** — corrigir branches e artefatos; garantir CI verde na `main`.
2. **P0-02 + P0-03** — ambiente de produção e bootstrap seguro do admin.
3. **P0-06 + P0-05** — guarda de mount e backup/restore testado.
4. **P0-04** — decisão e implementação de TLS (ou aceite formal de risco).
5. **P1-01..P1-05** — capacidade, healthchecks, entrypoint, sessão e MySQL.
6. **P1-06..P1-11** — monitoramento, logs, host, segredos, LGPD e migrações.
7. **P2** — otimizações e endurecimento contínuo.

## Critérios de aceite dos P0

- [ ] `git merge-base --is-ancestor 18e6d1d main` retorna sucesso e não há `*.phar`/`phpactor-lsp` na `main`
- [ ] `.env` de produção com `APP_ENV=production` e senhas fortes, validado por preflight
- [ ] Admin inicial criado sem senha pública; fallbacks `visitorpassword` removidos
- [ ] TLS configurado e testado, ou aceite formal de risco para HTTP interno
- [ ] Backup diário automático, criptografado, offsite, com restore de teste documentado
- [ ] Deploy aborta se `/mnt/visitantes` não estiver montado
- [ ] Healthchecks e limites de memória/MPM validados sob carga leve
