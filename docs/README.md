# Documentação — SICAV

Índice da documentação do Sistema de Gerenciamento e Controle de Visitantes
(SICAV). Para visão geral, instalação e operação, veja o
[README do repositório](../README.md).

## Como está organizada

| Área | Pasta | Conteúdo |
|------|-------|----------|
| Auditoria | [`auditoria/`](auditoria/) | Prontidão para produção, registro de achados e protocolo de auditoria |
| Segurança | [`seguranca/`](seguranca/) | Especificação normativa e plano de implementação dos controles |
| Operação | [`operacao/`](operacao/) | Fluxo de Git, PRs, releases e deploy |

## Auditoria

- [Análise de prontidão para produção](auditoria/ANALISE-PRONTO-PRODUCAO.md) —
  veredito, bloqueadores (P0), riscos altos (P1), melhorias (P2), plano de
  implantação e checklist go/no-go. Registro datado de 2026-11-14 (commit
  `18e6d1d`).
- [Registro de achados — prontidão para produção](auditoria/ACHADOS-PRODUCAO.md) —
  quadro-resumo dos achados, correções aplicadas na CI, recorrências de scan e
  critérios de aceite dos P0.
- [Especificação — Auditoria Completa de Projeto PHP](auditoria/ESPECIFICACAO-AUDITORIA-PHP.md) —
  metodologia de referência usada nas auditorias (documento normativo genérico,
  com notas de aplicação ao SICAV).

## Segurança

- [Especificação Técnica de Segurança](seguranca/ESPECIFICACAO-SEGURANCA.md) —
  padrão normativo: autenticação, sessões, MySQL, Docker, CI, testes e
  critérios go/no-go.
- [Plano de Implementação](seguranca/PLANO_IMPLEMENTACAO.md) — achados
  confirmados (`SEC-*`, `QUAL-*`), fases de execução e registro do que já foi
  corrigido.

## Operação

- [Fluxo de branches, PRs e releases](operacao/FLUXO-GIT.md) — branches
  permanentes e temporárias, PRs, deploy somente via `main` e versionamento
  SemVer.

## Convenções

- **Severidade:** P0 = bloqueador de go-live; P1 = risco alto (primeira semana);
  P2 = melhoria contínua.
- **IDs:** `P0-xx`/`P1-xx`/`P2-xx` na análise de prontidão; `SEC-*`/`QUAL-*` no
  plano de implementação.
- **Datas e commits** citados são marcos históricos de cada registro. O estado
  atual do sistema é o [README](../README.md) mais o registro de achados.

Fora do repositório: `manual.md` (raiz) é um guia local de organização do Git,
listado no `.gitignore`. A versão oficial é
[docs/operacao/FLUXO-GIT.md](operacao/FLUXO-GIT.md).

Ao mover ou renomear documentos, atualize este índice e as referências no
[README](../README.md) e nos próprios documentos.
