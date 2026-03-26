# Lumina — Visão geral do projeto

## Nome e finalidade

**Lumina** (`rovela/lumina`) é um backend em **Hyperf** descrito no `composer.json` como orquestrador de serviços e integrações. No contexto de negócio atual, o produto alvo é um **LMS** (ambiente de aprendizagem), com foco em escala e integração com SSO e documentação de API (OpenAPI) em evolução.

## Resumo executivo

- **Estilo de repositório:** monólito de API (sem frontend neste repositório).
- **Padrão arquitetural:** camadas **Controller → Service → Repository → Model**, com **contratos** (`RepositoryInterface`, `ServiceInterface`) e **CRUD abstrato** reutilizável.
- **Persistência:** banco relacional (MySQL), tabelas com prefixo `unim_` no domínio de pessoa.
- **Cache:** Redis via driver PSR-16 do Hyperf e cache de permissões no `AclService`.
- **Segurança em evolução:** `AclMiddleware` + `App\Service\Auth\AclService` (bitmask por recurso no Redis).

## Classificação

| Dimensão | Valor |
|----------|--------|
| Tipo (BMad) | `backend` |
| Linguagem | PHP 8.4+ |
| Framework | Hyperf ~3.1 |
| Servidor HTTP | Swoole (corrotinas) |

## Links para documentação detalhada

| Documento | Conteúdo |
|-----------|----------|
| [architecture.md](./architecture.md) | Camadas, fluxo de request, ACL |
| [api-contracts.md](./api-contracts.md) | Rotas HTTP registradas |
| [data-models.md](./data-models.md) | Entidades Eloquent e tabelas |
| [source-tree-analysis.md](./source-tree-analysis.md) | Pastas e responsabilidades |
| [development-guide.md](./development-guide.md) | Como rodar e testar |

## Riscos e lacunas (brownfield)

- OpenAPI/Swagger ainda não integrado ao `composer.json` (planejado).
- README na raiz mistura conteúdo de skeleton; esta pasta é a fonte preferida para contexto atual.
- Rotas de ACL podem estar **inconsistentes** (ex.: só `GET /pessoa` com middleware); revisar em sprint de segurança.
