# Lumina — Contratos HTTP (rotas)

**Fonte:** `config/routes.php`.  
**Formato:** REST JSON.  
**OpenAPI:** _(To be generated)_ — planejado; outro container consumirá a spec.

## Convenções

- Corpo JSON: usar `Content-Type: application/json`; validação via `AbstractFormRequest` (merge com `getParsedBody()`).
- IDs numéricos nas rotas: `{id:\d+}`.
- **ACL:** `AclMiddleware` lê `acl_resource` / `acl_level` das opções da rota (`Hyperf\HttpServer\Router\Handler->options`) via `App\Support\AclRouteOptions`. Sem `user_profile_id` → **401**; sem permissão no bitmask → **403**.

## Rotas globais

| Método | Caminho | Handler | Observação |
|--------|---------|---------|------------|
| GET, POST, HEAD | `/` | `IndexController@index` | Sem ACL |
| GET | `/favicon.ico` | closure | Sem ACL |

## Pessoa — `/pessoa`

| Método | Caminho | Ação | ACL |
|--------|---------|------|-----|
| GET | `/pessoa` | `index` | `Pessoa.Gerenciamento`, `ACESSAR` |
| GET | `/pessoa/{id}` | `show` | `Pessoa.Gerenciamento`, `ACESSAR` |
| POST | `/pessoa` | `store` | `Pessoa.Gerenciamento`, `INSERIR` |
| PUT | `/pessoa/{id}` | `update` | `Pessoa.Gerenciamento`, `ALTERAR` |
| DELETE | `/pessoa/{id}` | `destroy` | `Pessoa.Gerenciamento`, `REMOVER` |

## Pessoa física — `/pessoa-fisica`

| Método | Caminho | Ação | ACL |
|--------|---------|------|-----|
| GET | `/pessoa-fisica` | `index` | `PessoaFisica.Gerenciamento`, `ACESSAR` |
| GET | `/pessoa-fisica/{id}` | `show` | idem |
| POST | `/pessoa-fisica` | `store` | `INSERIR` |
| PUT | `/pessoa-fisica/{id}` | `update` | `ALTERAR` |
| DELETE | `/pessoa-fisica/{id}` | `destroy` | `REMOVER` |

## Pessoa endereço — `/pessoa-endereco`

| Método | Caminho | Ação | ACL |
|--------|---------|------|-----|
| GET | `/pessoa-endereco` | `index` | `PessoaEndereco.Gerenciamento`, `ACESSAR` |
| GET | `/pessoa-endereco/{id}` | `show` | idem |
| POST | `/pessoa-endereco` | `store` | `INSERIR` |
| PUT | `/pessoa-endereco/{id}` | `update` | `ALTERAR` |
| DELETE | `/pessoa-endereco/{id}` | `destroy` | `REMOVER` |

## Middleware global HTTP

`config/autoload/middlewares.php`: `AclMiddleware` pode **não** estar no array global; ACL é aplicada **por rota** via opções em `config/routes.php`.

## Outros controllers (referência)

- `TestNativeDbController` — uso de teste/desempenho; confirmar se exposto em rotas em outros arquivos ou só dev.
