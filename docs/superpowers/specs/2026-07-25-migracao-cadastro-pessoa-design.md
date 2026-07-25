# Migração da API de Cadastro de Pessoa (LMS legado → lumina)

Data: 2026-07-25

## Contexto

O LMS legado (`/home/brovela/uni-docker-hub/apps/lms`, Laminas MVC + Doctrine ORM + PDO síncrono) será migrado gradativamente pro lumina (Hyperf/Swoole, coroutines). Esta é a primeira API migrada — **cadastro de pessoa** — e serve de exemplo/padrão pras próximas migrações (ACL, autenticação, camadas, paginação, documentação).

Volume esperado: 10 mil+ acessos simultâneos. Toda a stack de banco precisa ser coroutine-safe (não bloqueante).

### Estado atual do lumina (achados durante o brainstorming, não documentados no CLAUDE.md)

- `hyperf/database`, `hyperf/db-connection` e `hyperf/pool` **não estão instalados** (não aparecem em `composer.lock`/`vendor/`), apesar de `config/autoload/databases.php` já vir configurado (pool 32-512, sticky) e `app/Model/Model.php` fazer `extends Hyperf\DbConnection\Model\Model` — import quebrado hoje.
- `config/autoload/exceptions.php` referencia `AppExceptionHandler`, `DatabaseExceptionHandler`, `ValidationExceptionHandler` — nenhuma classe existe em `app/Exception/Handler`.
- `config/autoload/middlewares.php` tem `AclMiddleware` comentado (classe não existe).
- `config/autoload/swagger.php` não existe, apesar do CLAUDE.md descrever como se existisse. `hyperf/swagger` não está no `composer.json`. Só existe scaffold parcial: `storage/swagger/swagger-ui.html`, `storage/swagger/http.json`, variáveis `SWAGGER_*` no `.env.example`.
- `test/Cases/AclRouteOptionsTest.php` referencia `PessoaController`, `AclMiddleware`, `AclRouteOptions` — classes de uma tentativa anterior deletada no commit `e9d4769`. Quebra `composer test` hoje (class not found).

Este design corrige todos esses pontos como parte do trabalho, não como débito separado.

## Decisões de arquitetura

### ORM: hyperf/database (Eloquent-style), não Cycle nem Doctrine

Avaliamos 3 opções pro banco:

| | hyperf/database | Cycle ORM | Doctrine ORM |
|---|---|---|---|
| Suporte oficial a corrotina Hyperf | Sim, nativo | Não | Não |
| Bridge de coroutine é nosso? | Não precisa | Sim (~200-400 linhas, sem pacote pronto) | Sim (pacotes comunitários existem — `opsway/doctrine-orm-swoole`, `diego-ninja/swoole-mysql-doctrine-driver` — mas nenhum oficial Hyperf) |
| Padrão | ActiveRecord | Data Mapper | Data Mapper |
| Reaproveita legado | Não | Não | Sim — legado já tem XML mapping + entidades Doctrine 2.20 pra `unim_pessoa` e relacionadas |
| Complexidade interna a isolar por corrotina | Baixa | Média | Alta (EntityManager + UnitOfWork + proxy) |

**Decisão: hyperf/database.** É o único caminho sem bridge de corrotina não testado por ninguém além de nós — requisito mais crítico e menos negociável do projeto (10k+ acesso simultâneo). O ganho de Data Mapper do Cycle/Doctrine (entidade sem lógica de persistência) é obtido escondendo o Model Eloquent atrás de uma interface de Repository — ver "Estrutura de pastas" abaixo. Isso deixa a porta aberta pra revisitar Cycle/Doctrine no futuro, com mais tempo pra validar bridge de corrotina sob carga real, sem reescrever regra de negócio.

Instalar: `hyperf/database`, `hyperf/db-connection` (traz `hyperf/pool` como dependência, confirmado que este último não depende de `hyperf/database`/`hyperf/db-connection` — é componente de pool genérico usado em toda a stack Hyperf).

### Banco de dados

- Mesmo banco/tabelas do legado (`unim_pessoa`, `unim_pessoa_fisica`, `unim_pessoa_juridica` — sem duplicar dado, sem sincronização). `config/autoload/databases.php` já tem a config de pool/read-write/sticky certa, só faltava o pacote.
- Escopo desta primeira API: núcleo `UnimPessoa` + `UnimPessoaFisica`/`UnimPessoaJuridica` (associação quase sempre obrigatória — ver regra de negócio abaixo). Endereço, perfis e contatos ficam pras próximas APIs (sub-recursos).

### Estrutura de pastas

Convenção por camada (mantém o padrão do skeleton Hyperf já existente), com subpasta por módulo — repetível pras próximas migrações:

```
app/
  Controller/Pessoa/PessoaController.php
  Controller/Auth/AuthController.php
  Middleware/AuthMiddleware.php
  Middleware/AclMiddleware.php
  Service/Pessoa/PessoaService.php
  Service/Auth/AuthService.php
  Service/Acl/AclService.php
  Repository/Pessoa/PessoaRepositoryInterface.php + PessoaRepository.php
  Model/Pessoa/UnimPessoa.php, UnimPessoaFisica.php, UnimPessoaJuridica.php
  Request/Pessoa/CreatePessoaRequest.php, UpdatePessoaRequest.php, PatchPessoaRequest.php, ListPessoaRequest.php
  Resource/Pessoa/PessoaResource.php
  Exception/Handler/{App,Database,Validation}ExceptionHandler.php
  Exception/Pessoa/{PessoaNaoEncontrada,LoginJaExiste}Exception.php
  Support/ApiResponse.php
```

Regra dura: Model Eloquent nunca sai de dentro do Repository. Controller/Service só conhecem `PessoaRepositoryInterface`.

## Autenticação

Lumina emite e valida o próprio token (não depende de login externo/gateway).

- **Token**: opaco (`random_bytes` + hex, 32+ bytes), não JWT. Guardado no Redis: chave `session:{token}` → JSON `{cd_pessoa, cd_cliente, cd_perfis: [int, ...]}`, TTL configurável via `.env` (default 8h). Escolhido em vez de JWT stateless porque bate com "controle de sessão via Redis" pedido — permite logout/revogação imediata (JWT puro exigiria blacklist à parte).
- **Múltiplos perfis por pessoa**: achado ao iniciar a implementação (dado real: as pessoas de teste têm 5 perfis simultâneos cada) — o vínculo pessoa↔perfil do legado (`lgin_pessoa_perfil`, chave composta `cd_pessoa+cd_perfil+cd_coligada`, sem `cd_cliente` direto — o escopo por cliente vem de `unim_coligada.cd_cliente`, com `unim_coligada.dt_excluido IS NULL`) permite N perfis por pessoa por cliente, não 1. A sessão guarda a **lista completa** de `cd_perfil` da pessoa pro cliente autenticado, e o ACL é avaliado por **união de permissões**: a ação é permitida se **qualquer** perfil da lista conceder o privilégio.
- **Login** (`POST /auth/login`): recebe `cd_cliente`, `ds_login`, `ds_senha`. Verifica senha em cascata **compatível com o legado** (`Nucleo/Service/AuthService.php:302-343`): **BCrypt** (`password_verify`) → **MD5** (`md5($senha) === ds_senha`) → **texto puro** (`$senha === ds_senha`, contas residuais). Se bateu por MD5 ou texto puro, reescreve `ds_senha` em BCrypt na hora (upgrade silencioso). Escrita nova (cadastro/troca de senha) sempre BCrypt, nunca gera MD5/texto puro de novo.
- **Logout** (`POST /auth/logout`): `DEL session:{token}`.
- **AuthMiddleware**: lê `Authorization: Bearer <token>`, busca `session:{token}` no Redis. Não achou → 401. Achou → seta identidade no contexto da corrotina (`Hyperf\Context\Context::set`), não vaza entre corrotinas.

## ACL

Reaproveita o padrão do legado (`Nucleo/Service/Factory/AclServiceFactory.php` + `PerfilRecursoPrivilegioService.php`), mecanismo via **middleware PSR-15** (não AOP/atributo — mais simples, testável via `HttpTestCase`, idiomático Hyperf).

- Cache Redis `acl:perfil:{cd_perfil}` → JSON `{recurso: [privilégios]}`, TTL 1 dia (mesmo TTL do legado) — um cache por perfil individual, não por pessoa (perfis são compartilhados entre pessoas, cachear por perfil evita recomputar a mesma coisa pra cada pessoa que tem aquele perfil).
- Cache miss → monta do banco (tabela perfil/recurso/privilégio), grava no Redis.
- `AclService->isAllowed(array $cdPerfis, string $resource, string $privilege)` — **união de permissões**: retorna `true` se **qualquer** `cd_perfil` da lista conceder o privilégio (consulta/monta o cache de cada perfil envolvido).
- `AclService->invalidar(int $cdPerfil)` → `DEL` da chave daquele perfil. Método pronto desde já pra quando a API de perfil for migrada (fora de escopo agora — só o mecanismo de invalidação fica preparado).
- `AclMiddleware` roda depois do `AuthMiddleware` na pipeline, lê `resource`/`privilege` exigido da opção de rota (ex: `['acl' => ['resource' => 'pessoa', 'privilege' => 'listar']]`), pega a lista de `cd_perfis` da sessão. Não permitido → 403.

## CRUD de Pessoa

Todas as rotas atrás de `AuthMiddleware` + `AclMiddleware` (resource `pessoa`):

| Rota | Privilege | Ação |
|---|---|---|
| `POST /pessoas` | `criar` | Cadastra |
| `PUT /pessoas/{id}` | `atualizar` | Substitui completo — payload exige todos campos |
| `PATCH /pessoas/{id}` | `atualizar` | Parcial — só campos enviados, exige ao menos 1 |
| `DELETE /pessoas/{id}` | `excluir` | Soft-delete |
| `GET /pessoas/{id}` | `visualizar` | Busca |
| `GET /pessoas` | `listar` | Lista paginada |

### Regras de negócio (criar/atualizar)

- Login único por `cd_cliente` — checagem de negócio própria (não só constraint de banco). Duplicado → `LoginJaExisteException` (409).
- `sn_pessoa_juridica` decide `UnimPessoaFisica` ou `UnimPessoaJuridica`, **exceto** login `admin`/`administrador` (fiel ao legado — `PessoaService::salvarPessoa`, ~linha 280 — isento de fisica/juridica).
- Senha: obrigatória no create, opcional no update/patch (se não vier, mantém a atual). Sempre BCrypt na escrita.
- `cd_cliente` sempre vem da identidade autenticada (contexto da corrotina), nunca do payload ou de query param — impede pessoa criada/vista/listada fora do próprio cliente.
- `PessoaRepository->salvar()` roda dentro de `DB::transaction()` — `UnimPessoa` + `Fisica`/`Juridica` atômico.

### Soft-delete

- Migration (via `hyperf/database`, comando `gen:migration`/`migrate` — mecanismo a confirmar no plano de implementação) adiciona `dt_excluido` (datetime nullable) em `unim_pessoa` — segue a mesma convenção usada em 10+ tabelas do legado (`dt_excluido IS NULL` = ativo).
- Model usa `SoftDeletes` nativo do Eloquent (`const DELETED_AT = 'dt_excluido'`) — toda query já ignora excluído automaticamente. Isso é uma melhoria sobre o legado, que exige adicionar o filtro manualmente em cada query/join (fonte real de bug se esquecido).
- `DELETE /pessoas/{id}` seta `dt_excluido = now()`, nunca remove linha. Sem endpoint de restaurar nesta primeira versão.

### Buscar e Listar

- `GET /pessoas/{id}`: filtra por `cd_cliente` do contexto + `id` sempre juntos. Não achou (ou é de outro cliente) → `PessoaNaoEncontradaException` (404) — nunca revela que existe em outro cliente.
- `GET /pessoas`: query params `page` (default 1), `per_page` (default 20, máx 100 — clampa, não erra se vier maior), `nome` (LIKE parcial em `ds_nome`), `tipo_pessoa` (`fisica`|`juridica` → mapeia pra `sn_pessoa_juridica`). `cd_cliente` sempre do contexto, nunca filtro opcional.
- Resposta da listagem: `{ success: true, data: [...], meta: { total, per_page, current_page, last_page } }`. Resposta de recurso único (get/create/update) **não tem** `meta`: `{ success: true, data: {...} }`.
- `PessoaResource` formata toda saída — nunca expõe `ds_senha`.
- Status HTTP de sucesso: `201` no create, `200` nos demais (get/list/put/patch/delete). Delete não retorna corpo vazio (`204`) — devolve o envelope padrão com a pessoa já marcada como excluída, consistente com o resto da API.

## OpenAPI / Swagger

- Toda rota ganha atributo OpenAPI direto no método do controller (via `zircote/swagger-php`, motor por baixo do `hyperf/swagger`) — request body, query params, schema de resposta (sucesso e erro), tag por recurso. Convenção obrigatória pras próximas rotas migradas também.
- Instalar `hyperf/swagger`, criar `config/autoload/swagger.php` (não existe hoje). Gera o JSON reaproveitando o scaffold `storage/swagger/http.json` já existente.
- **Sem expor a porta 9500 publicamente** — só gera o arquivo, não serve UI embutida no processo Hyperf.
- `docker-compose.yml` ganha serviço novo de documentação: imagem leve (~64MB) tipo `open-swagger-ui`, igual ao padrão do legado (`techsuperior/web-swagger`, ver `uni-docker-hub/services/lms/docker-compose.yml`), lê o JSON gerado via volume, serve a UI numa porta própria — desacoplado do ciclo de vida do app.

## Tratamento de erro

- `AppExceptionHandler` — catch genérico (`Throwable` não mapeado) → 500, loga stack completo, nunca vaza mensagem interna em produção (mensagem genérica + código de rastreio).
- `ValidationExceptionHandler` — captura exceção de validação do `hyperf/validation` (a instalar, não está no `composer.json` hoje) → 422, `{ success: false, message, errors: { campo: [mensagens] } }`.
- `DatabaseExceptionHandler` — captura erro de driver/constraint → 400/409 conforme o tipo, nunca expõe SQL cru.
- Exceções de domínio (`PessoaNaoEncontradaException` → 404, `LoginJaExisteException` → 409) sabem o próprio status HTTP.
- Todos os handlers devolvem o mesmo envelope via `Support\ApiResponse::erro(...)` — formato único de erro em toda a API.

## Testes

- `test/Cases/AclRouteOptionsTest.php` (órfão, referencia classes deletadas no commit `e9d4769`) — **removido**, testes novos desenhados do zero conforme este design.
- Cobertura via `HttpTestCase` (já existe base): cada rota feliz (create/put/patch/delete/get/list), login duplicado, cascata de senha (bcrypt/md5/texto puro + upgrade silencioso), exceção do login admin sem fisica/juridica, soft-delete some da listagem/busca, ACL negando (403) e permitindo, Auth negando (401 sem token/token inválido/expirado).
- Teste de integração real (request → banco, transação com rollback por teste) — sem mock de Repository pro fluxo completo, evita divergência tipo "mock passou, prod quebrou".
- `composer test` (cs-check + php-unit + analyse) precisa passar do zero, incluindo o phpstan nível 0 forçado pelo script atual (fora de escopo revisar esse nível agora, só sinalizando a inconsistência com `phpstan.neon` que pede nível 9).

## Fora de escopo (fica pras próximas APIs)

- Endereço, perfis e contatos de pessoa (sub-recursos da transação composta do legado).
- API de gerenciar perfil/permissão (o mecanismo de invalidação do ACL já fica pronto, mas a API que o aciona não).
- Endpoint de restaurar pessoa soft-deletada.
- Revisão do nível do phpstan (`analyse` força `-l 0` mesmo com `phpstan.neon` pedindo nível 9).
