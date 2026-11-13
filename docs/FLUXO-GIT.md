# Fluxo de branches, PRs e releases

Este documento define o fluxo oficial do repositório. O objetivo é manter
`main` sempre pronta para produção, concentrar as mudanças em uma única branch
de integração e registrar cada versão publicada com uma tag.

## Branches permanentes

| Branch | Papel |
|--------|-------|
| `main` | Código validado e em produção. É a **única** origem de deploy. Recebe mudanças apenas por pull request. |
| `fix/dev-development` | Branch de integração para correções e melhorias. Recebe PRs das branches de trabalho e é promovida para `main` após validação. |

## Branches temporárias

Crie uma branch curta a partir de `fix/dev-development` para cada trabalho:

- `fix/<assunto>` — correções
- `feat/<assunto>` — novas funcionalidades
- `chore/<assunto>` — manutenção, dependências e infraestrutura
- `docs/<assunto>` — documentação

Depois do merge, a branch temporária é excluída. O GitHub está configurado com
**Delete branch on merge**, então a exclusão é automática para PRs.

## Fluxo de uma mudança

```text
fix/<assunto>  ──PR──▶  fix/dev-development  ──PR──▶  main  ──▶  deploy  ──▶  tag vX.Y.Z
```

1. Atualize a branch de integração:

   ```bash
   git switch fix/dev-development
   git pull --ff-only origin fix/dev-development
   ```

2. Crie a branch de trabalho:

   ```bash
   git switch -c fix/descricao-curta
   ```

3. Faça commits, envie a branch e abra PR **para `fix/dev-development`**:

   ```bash
   git push -u origin fix/descricao-curta
   gh pr create --base fix/dev-development --fill
   ```

4. Após revisão e CI verde, faça o merge. A branch temporária é excluída.

5. Quando o conjunto de mudanças estiver validado em desenvolvimento, abra um
   PR de `fix/dev-development` para `main`:

   ```bash
   gh pr create --base main --head fix/dev-development --fill
   ```

6. O merge em `main` exige os checks obrigatórios configurados na proteção da
   branch. Não faça push direto em `main`.

## Deploy em produção

O deploy é feito **somente a partir de `main`**, no servidor:

```bash
cd /opt/sistema-visitantes

# 1. Backup do banco e das fotos
# 2. Atualize o código
git switch main
git pull --ff-only origin main

# 3. Rebuild e suba a stack
docker compose up -d --build

# 4. Verifique a aplicação
docker compose ps
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8000/
```

## Versionamento e tags

Cada versão publicada em produção recebe uma tag anotada no formato
**SemVer**: `vMAJOR.MINOR.PATCH`.

| Mudança | Incremento | Exemplo |
|---------|------------|---------|
| Correção compatível | patch | `v1.0.0` → `v1.0.1` |
| Nova funcionalidade compatível | minor | `v1.0.1` → `v1.1.0` |
| Mudança incompatível | major | `v1.1.0` → `v2.0.0` |

Depois do deploy e da verificação pós-deploy, crie e publique a tag a partir da
`main`:

```bash
./scripts/release.sh          # patch
./scripts/release.sh minor    # funcionalidade nova
./scripts/release.sh major    # mudança incompatível
./scripts/release.sh 1.2.3    # versão explícita
```

O script valida que você está na `main`, que a árvore está limpa e que o commit
local é igual a `origin/main`. Em seguida cria a tag anotada e a envia para o
GitHub. O workflow `.github/workflows/release.yml` gera automaticamente a
release do GitHub para cada tag `v*.*.*`.

Para listar as versões publicadas:

```bash
git fetch --tags
git tag -n
```

## Regras resumidas

- `main` = produção; deploy somente a partir dela.
- `fix/dev-development` = integração; todo trabalho passa por ela.
- Branches de trabalho são temporárias e excluídas após o merge.
- Toda promoção para `main` é feita por pull request.
- Toda versão implantada em produção recebe uma tag `vX.Y.Z`.
