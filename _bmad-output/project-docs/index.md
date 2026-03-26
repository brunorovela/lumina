# Lumina — Índice da documentação do projeto

**Tipo:** monólito backend (API Hyperf / PHP).  
**Idioma dos artefatos:** português (Brasil).  
**Última geração:** 2026-03-26 (scan inicial / document-project)

> **Nota:** a pasta `docs/` na raiz do repositório pode estar sem permissão de escrita neste ambiente; os artefatos foram gravados em `_bmad-output/project-docs/`. Copie para `docs/` localmente se desejar: `mkdir -p docs && cp -r _bmad-output/project-docs/* docs/`

---

## Visão rápida

| Item | Valor |
|------|--------|
| Pacote | `rovela/lumina` |
| Propósito | Serviço LMS / orquestração de integrações (em construção) |
| Runtime | PHP 8.4+, Hyperf 3.x, Swoole |
| Dados | MySQL (tabelas `unim_*`), Redis (cache + ACL) |

---

## Documentação gerada

- [Visão geral do projeto](./project-overview.md)
- [Árvore de fontes e pastas críticas](./source-tree-analysis.md)
- [Arquitetura](./architecture.md)
- [Contratos HTTP (rotas)](./api-contracts.md)
- [Modelos de dados](./data-models.md)
- [Guia de desenvolvimento](./development-guide.md)

## Documentação complementar (BMad)

- [Arquitetura, roadmap, SSO e OpenAPI](../lumina-arquitetura-roadmap-sso-openapi.md)
- [Avaliação arquitetural e melhorias (Winston)](../architecture-lumina-avaliacao-e-melhorias.md)

## Documentação existente na raiz

- [README principal](../../README.md) — skeleton Hyperf + notas diversas (pode divergir do estado atual)

---

## Como usar com IA / PRD brownfield

Use este **`index.md`** como ponto de entrada: ele referencia visão geral, API, modelos e arquitetura.

---

## Estado do scan

Arquivo de estado: [project-scan-report.json](./project-scan-report.json)
