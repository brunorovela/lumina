# Lumina — Árvore de fontes (anotada)

**Raiz do código de aplicação:** `app/`

```
app/
├── Constants/           # HttpStatus, Permission, MySqlError — constantes compartilhadas
├── Contract/            # RepositoryInterface, ServiceInterface
├── Controller/          # Endpoints HTTP; AbstractController + AbstractCrudController
├── Exception/Handler/   # Respostas de erro (app, validação, banco)
├── Listener/            # Eventos do framework (DB, exit coordinator)
├── Middleware/          # AclMiddleware — autorização por recurso/nível
├── Model/               # Pessoa, PessoaFisica, PessoaEndereco, Model base
├── Repository/          # AbstractRepository + repositórios por entidade + AclRepository
├── Request/             # Form requests (validação); AbstractFormRequest (JSON)
└── Service/             # AbstractCrudService, serviços de domínio, Auth/AclService
```

## Configuração e bootstrap

| Caminho | Função |
|---------|--------|
| `bin/hyperf.php` | Entrada CLI / servidor |
| `config/routes.php` | Definição de rotas |
| `config/autoload/` | databases, redis, cache, exceptions, middlewares, etc. |
| `composer.json` | Dependências e scripts (`start`, `watch`, testes) |

## Testes

| Caminho | Função |
|---------|--------|
| `test/` | Bootstrap PHPUnit / casos de exemplo |

## Artefatos de documentação (este scan)

| Caminho | Função |
|---------|--------|
| `_bmad-output/project-docs/` | Índice e docs gerados pelo workflow document-project |
| `_bmad-output/lumina-arquitetura-roadmap-sso-openapi.md` | Roadmap estratégico |
