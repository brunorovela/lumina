# Lumina — Arquitetura

## Visão em camadas

```
HTTP (Swoole)
    → Router (config/routes.php)
    → Middleware global (config/autoload/middlewares.php) + por rota (ex.: AclMiddleware)
    → Controller (injecção por construtor para serviços)
    → FormRequest (validação; AbstractFormRequest mescla body JSON)
    → Service (regras, cache em AbstractCrudService)
    → Repository (persistência)
    → Model (Hyperf DB / Eloquent-style)
```

## Componentes principais

| Camada | Papel |
|--------|--------|
| **Controller** | Orquestra HTTP, delega a serviços; `AbstractCrudController` implementa index/show/destroy. |
| **Service** | `AbstractCrudService`: listagem com cache, busca por id com cache, criar/atualizar/remover com invalidação. |
| **Repository** | `AbstractRepository`: all/find/create/update/delete sobre o model. |
| **Model** | Tabelas `unim_*`, relacionamentos `hasOne` / `belongsTo`. |
| **ACL** | `AclMiddleware` lê `user_profile_id`, `acl_resource`, `acl_level`; `AclService` consulta Redis (`lms:acl:profile:{id}`) e recarrega do banco via `AclRepository` em miss. |

## Tratamento de erros

Handlers registrados em `config/autoload/exceptions.php` (HTTP, validação, app, banco — conforme projeto).

## Integrações futuras (não acopladas no código atual)

- **SSO / JWT:** middleware de autenticação deve popular `user_profile_id` antes do ACL.
- **OpenAPI:** consumo por container externo de documentação.

## Escalabilidade (lembrete)

Runtime assíncrono (Swoole) ajuda em I/O; escala de ~50k usuários simultâneos exige desenho de dados, Redis, pools e testes de carga — ver documento de roadmap SSO/OpenAPI.
