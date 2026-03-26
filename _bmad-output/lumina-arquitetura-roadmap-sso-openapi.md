# Lumina — Arquitetura, roadmap técnico, SSO e OpenAPI

**Produto:** LMS (Learning Management System) em construção.  
**Meta de escala (declarada):** ~50 mil usuários simultâneos.  
**Princípios:** código simples, prático e rápido; evitar acoplamento excessivo; respeitar SSO onde possível.  
**Documentação de API:** toda rota deve ser descrita em OpenAPI; outro container consumirá a especificação.

**Última atualização do documento:** 2026-03-26

---

## 1. Resumo executivo

O **Lumina** (`rovela/lumina`) é um serviço **Hyperf 3.x / PHP 8.4** sobre **Swoole**, com **MySQL**, **Redis** e camadas **Repository → Service → Controller**. Há **CRUDs** para entidades de pessoa (`/pessoa`, `/pessoa-fisica`, `/pessoa-endereco`), **cache** em operações de leitura no serviço base, e um esboço de **ACL** com **Redis** e perfil vindo do request.

Para atingir escala e governança (SSO + documentação), os próximos passos prioritários são: **(1)** pipeline claro **Autenticação (token/claims) → Autorização (ACL)**; **(2)** **OpenAPI** gerado ou servido de forma automática; **(3)** testes de carga e observabilidade nas rotas críticas.

---

## 2. Estado atual do código (referência técnica)

### 2.1 Stack

| Componente | Uso no projeto |
|------------|----------------|
| PHP | >= 8.4 |
| Framework | Hyperf (~3.1): `http-server`, `database`, `db-connection`, `validation`, `cache`, `redis`, `rate-limit`, etc. |
| Runtime | Swoole (corrotinas, alto paralelismo de I/O) |
| Persistência | MySQL (schema legado / LMS, tabelas `unim_*`) |
| Cache | Redis (driver de cache Hyperf + uso direto em ACL) |

### 2.2 Organização de pastas (conceito)

- **`app/Contract/`** — interfaces (`RepositoryInterface`, `ServiceInterface`).
- **`app/Repository/`** — acesso a dados; `AbstractRepository` + repositórios por entidade.
- **`app/Service/`** — regras e orquestração; `AbstractCrudService` com cache PSR-16; serviços por entidade; **`App\Service\Auth\AclService`** (bitmask em Redis + fallback ao repositório).
- **`app/Controller/`** — HTTP fino; `AbstractCrudController` + controllers por recurso.
- **`app/Request/`** — `AbstractFormRequest` com **`validationData()`** mesclando body JSON (`getParsedBody`) com `all()` — necessário para validação correta com `Content-Type: application/json`.
- **`app/Middleware/`** — `AclMiddleware`: lê `user_profile_id`, `acl_resource`, `acl_level` do request e delega ao `AclService`.
- **`app/Model/`** — Eloquent-style (Hyperf DB), relacionamentos entre Pessoa / Física / Endereço.

### 2.3 Rotas registradas (`config/routes.php`)

- `GET|POST|HEAD /` — `IndexController@index`
- **Pessoa:** `GET/POST/PUT/DELETE` em `/pessoa` e `/pessoa/{id}` (padrão REST).
- **Pessoa física e endereço:** CRUD em `/pessoa-fisica` e `/pessoa-endereco`.

**Observação de segurança:** apenas **`GET /pessoa`** está declarado com `AclMiddleware` e opções `acl_resource` / `acl_level`. As demais rotas do mesmo recurso **não** repetem o middleware na definição atual — decisão deve ser **explícita** (aplicar ACL em todas, ou documentar exceções, ex.: cadastro público).

### 2.4 ACL e alinhamento a SSO

- **`AclMiddleware`** espera o atributo **`user_profile_id`** no request (comentário no código: perfil via JWT ou sessão).
- **`AclService`** usa Redis (`lms:acl:profile:{id}`) com hash por recurso e bitmask de permissão; miss carrega do banco via `AclRepository`.

**Implicação para SSO:** o IdP / API Gateway deve **emitir token** (ex.: JWT) e um middleware **anterior** ao ACL deve **validar o token** e **preencher** `user_profile_id` (e opcionalmente outros claims) como atributos PSR-7. O Lumina não precisa “fazer login” completo se a política for SSO centralizado — apenas **confiar na identidade já validada na borda** ou validar JWT com chaves do IdP.

### 2.5 Lacunas conhecidas (em construção)

- Código e testes ainda em evolução; erros pontuais serão tratados em sprints seguintes.
- **OpenAPI:** ainda não há pacote Swagger/OpenAPI no `composer.json` nem endpoint padrão de spec no fluxo atual — ver seção 4.
- **README** na raiz ainda contém trechos genéricos de skeleton; a documentação de produto deve convergir para este repositório ou para `docs/`.

---

## 3. Meta de ~50 mil usuários simultâneos — diretrizes

“Simultâneos” costuma significar **conexões ativas** ou **requisições por segundo** agregadas. O Hyperf + Swoole ajudam no **modelo de processo e I/O**, mas a escala depende de:

1. **Estado:** preferir **API stateless**; sessão em Redis só se necessário e com TTL claro.
2. **Banco:** pool de conexões adequado, índices, evitar N+1 em listagens, leituras pesadas com cache ou réplicas conforme desenho futuro.
3. **Redis:** uso já presente (ACL + cache); definir limites de memória, política de eviction e monitoramento.
4. **Filas:** para trabalhos longos (e-mail, relatórios, integrações), usar filas dedicadas (componente Hyperf ou broker externo) — reduz pressão no worker HTTP.
5. **Rate limiting:** pacote já listado no `composer.json`; aplicar em rotas sensíveis e na borda (gateway) quando existir.
6. **Observabilidade:** logs estruturados, métricas (latência, erros, pool DB, Redis), tracing distribuído se houver múltiplos serviços.

**Recomendação:** definir **SLOs** (ex.: p95 de latência por rota) e rodar **testes de carga** (ex.: `wrk`, k6) em ambiente próximo à produção antes de compromissos de escala.

---

## 4. OpenAPI (Swagger) — estratégia recomendada

**Objetivo:** cada rota HTTP tenha descrição consumível por **outro container** (portal de documentação, gateway, validação de contrato).

### 4.1 Opções no ecossistema Hyperf

| Abordagem | Prós | Contras |
|-----------|------|--------|
| **Anotações em controllers** (ex.: `hyperf/swagger`) | Próximo ao código; UI opcional em dev | Manutenção das anotações ao mudar assinaturas |
| **Geração em build** a partir de rotas + DTOs | Contrato revisável em CI | Mais trabalho inicial de mapeamento |
| **Arquivo OpenAPI versionado** (`openapi.yaml`) | Controle fino, review em PR | Duplicação se não sincronizar com código |

**Sugestão prática:** começar com **Swagger via Hyperf** (anotações ou scan de rotas conforme documentação oficial da versão 3.x do projeto) + rota **`GET /openapi.json`** (ou YAML) **apenas em ambientes não produtivos** ou protegida por rede; em produção, o **artefato** pode ser gerado no **CI** e publicado no container de documentação **sem** expor UI na internet pública.

### 4.2 Integração com o “outro container”

- O segundo container pode **buscar** `openapi.json` via HTTP interno ou **montar volume** com o arquivo gerado no pipeline.
- Versionar a API (`/v1/pessoa`, etc.) quando houver breaking changes — facilita documentação e clientes.

---

## 5. SSO — fluxo alvo (simples)

```
Cliente → API Gateway / IdP (login SSO)
       → Request com Authorization: Bearer <JWT>
       → [Middleware Auth] valida assinatura/issuer, extrai claims
       → Request com atributo user_profile_id (e opcionalmente tenant, roles)
       → [AclMiddleware] isAllowed(perfil, recurso, nível)
       → Controller
```

- **Não duplicar** armazenamento de senha do IdP neste serviço se a política for SSO puro.
- **Mapear** claim do token → `cd_perfil` interno (tabela ou cache) de forma explícita e testável.

---

## 6. Roadmap sugerido (priorizado)

| Prioridade | Item | Motivo |
|------------|------|--------|
| P0 | Corrigir **consistência** de `AclMiddleware` em todas as rotas que exigem permissão | Evitar buracos de segurança |
| P0 | Middleware de **autenticação** (JWT/headers) antes do ACL | SSO operacional |
| P1 | Adicionar **OpenAPI** (pacote + primeira versão da spec para CRUDs atuais) | Contrato com o outro container |
| P1 | Testes automatizados mínimos (HTTP + validação) nas rotas críticas | Regressão |
| P2 | Hardening de escala (pool DB, limites Redis, métricas) | Meta 50k |
| P2 | Alinhar **README** e este documento como fonte única de verdade | Onboarding |

---

## 7. Referências internas

- Código: `app/`, `config/routes.php`, `composer.json`
- Este documento: `_bmad-output/lumina-arquitetura-roadmap-sso-openapi.md`

---

*Documento produzido como continuação da análise (capacidade TR — pesquisa e diretrizes de arquitetura / implementação). Ajuste datas e prioridades conforme decisões de produto.*
