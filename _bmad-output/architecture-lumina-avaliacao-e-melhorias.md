# Lumina — Avaliação arquitetural (Winston) e sugestões de melhoria

**Autor do parecer:** perspectiva de arquitetura de sistemas (skill bmad-agent-architect).  
**Documentos avaliados:**  
- `_bmad-output/lumina-arquitetura-roadmap-sso-openapi.md` (analista / roadmap)  
- `_bmad-output/project-docs/architecture.md` (scan document-project)

**Destinatário:** Bruno  
**Idioma:** português (Brasil)

**Última atualização:** 2026-03-26

---

## 1. Avaliação do trabalho do analista

### 1.1 O que está forte (e pode permanecer como referência)

| Aspecto | Avaliação |
|---------|-----------|
| **Fidelidade ao código** | O roadmap descreve corretamente Hyperf 3.x, Swoole, MySQL, Redis, camadas Repository → Service → Controller e o papel do `AbstractFormRequest` com JSON. |
| **Priorização** | A sequência Autenticação → ACL → OpenAPI → testes → hardening de escala é **coerente com risco**: segurança e contrato vêm antes de otimização fina. |
| **SSO** | A distinção entre “validar identidade na borda” e “não duplicar senhas do IdP” está alinhada a boas práticas de SSO. |
| **Escala ~50k** | O texto evita prometer que “Swoole resolve tudo” e lista fatores reais (pool DB, N+1, Redis, filas, observabilidade). Isso é **realista**. |
| **OpenAPI** | A comparação anotações vs artefato CI vs YAML versionado cobre os trade-offs usuais; a sugestão de não expor Swagger público em produção é **pragmática**. |
| **Lacuna de ACL nas rotas** | Apontar que só `GET /pessoa` usa `AclMiddleware` é **crítico** e correto como alerta de produto/segurança. |

### 1.2 Onde o documento do analista pode ser refinado (sem invalidar o conteúdo)

| Ponto | Comentário |
|-------|------------|
| **“50 mil simultâneos”** | Vale **definir métrica** (conexões WebSocket? RPS agregado? usuários logados com heartbeat?) — arquitetura e dimensionamento mudam conforme o significado. |
| **Cache em `AbstractCrudService`** | O roadmap menciona cache de leitura; em escala alta, política de **invalidação** e **consistência eventual** por chave devem ser explícitas em ADR ou runbook para evitar bugs sutis. |
| **Monólito vs serviços** | O repositório é um **monólito de API** — está claro no scan; o roadmap poderia **nomear** isso (“deploy unitário, escala horizontal por réplicas”) para alinhar expectativas de time. |

**Veredito:** o documento do analista é **adequado como base de decisão** e já identifica os principais gaps. O que falta é **consolidar decisões arquiteturais** (ADRs leves) e **fechar o desenho de segurança** (ACL uniforme + auth).

---

## 2. Visão arquitetural consolidada (Lumina)

### 2.1 Estilo e fronteiras

- **Estilo:** API HTTP **stateless** (alvo), backend **monolítico** em um repositório.
- **Runtime:** processo Swoole com workers; bom para **muitas conexões I/O-bound**; CPU pesada deve ir para **fila** ou outro serviço.
- **Dados:** MySQL como fonte de verdade transacional; Redis para **cache de leitura** e **ACL** (dois papéis distintos — monitorar uso de memória e TTL).

### 2.2 Fluxo de request (estado alvo)

```
Cliente
  → [Opcional] API Gateway (terminação TLS, rate limit global)
  → Hyperf Router
  → Middleware: Auth (JWT / introspection) → preenche user_profile_id / tenant
  → Middleware: ACL (recurso + nível) — consistente em todas as rotas protegidas
  → Controller → Service → Repository → MySQL
  → Resposta JSON
```

**Estado atual:** o fluxo de **Auth** antes do ACL está **documentado como desejável**, mas **não implementado de ponta a ponta** no código descrito; o ACL está **parcial** nas rotas — isso é a **principal dívida arquitetural imediata**.

### 2.3 Decisões que já estão “implícitas” no código (vale formalizar)

| Decisão | Implementação atual | Recomendação |
|---------|---------------------|--------------|
| Injeção em controllers | Construtor para serviços | Manter — compatível com testes e com Hyperf DI. |
| Validação | FormRequest + merge JSON | Manter — reduz classe de erros de payload vazio. |
| Permissões | Bitmask em Redis + reload DB | Manter o modelo, mas documentar **contrato** do mapa recurso → hash e TTL. |
| Documentação de API | Ausente no composer | P1 — OpenAPI como contrato com o outro container. |

---

## 3. Sugestões de melhoria (priorizadas)

### P0 — Segurança e contrato de identidade

1. **Uniformizar ACL**  
   - Ou **todas** as rotas mutáveis e sensíveis de `/pessoa*` passam pelo mesmo `AclMiddleware` com matriz recurso/ação, ou existe **lista explícita** de rotas públicas (ex.: `POST /pessoa` aberto) **documentada** e revisada por segurança.  
   - Evitar o estado intermediário “só o GET lista está protegido” em produção.

2. **Middleware de autenticação único**  
   - Responsabilidade única: validar token (JWT/OIDC) ou confiar em headers injetados pelo gateway **com validação configurável por ambiente**.  
   - Popular `user_profile_id` (e tenant, se houver) **sempre** antes do ACL.

3. **Comportamento com `user_profile_id` ausente**  
   - Definir: 401 (não autenticado) vs 403 (autenticado sem perfil). Evita ambiguidade no ACL atual.

### P1 — Contrato de API e integração

4. **OpenAPI**  
   - Adicionar dependência oficial do ecossistema Hyperf para Swagger/OpenAPI **ou** gerar `openapi.yaml` no CI a partir de anotações.  
   - Expor artefato para o **outro container** via URL interna ou volume — como já planejado no roadmap.

5. **Versionamento de API**  
   - Prefixo `/v1/` antes que o número de clientes cresça — barato de fazer cedo, caro depois.

### P2 — Escalabilidade e operação

6. **SLOs e testes de carga**  
   - Escolher 3–5 rotas críticas; definir p95/p99; rodar k6/wrk em ambiente de staging com dados realistas.

7. **Observabilidade**  
   - Correlação `request_id` nos logs; métricas de pool de DB e latência Redis; alertas em taxa de erro 5xx.

8. **Filas**  
   - Qualquer fluxo que ultrapassar dezenas de ms (relatórios, integrações) deve sair do request síncrono — alinha com a meta de alto volume de requisições.

### P3 — Simplicidade de código (alinhado ao teu pedido)

9. **Evitar camadas extras** até surgir necessidade real — Repository + Service + Controller **já são suficientes** para o estágio atual.  
10. **ADRs curtos** (`docs/adr/` ou `_bmad-output/adr/`) só para decisões que mudam produto (SSO, OpenAPI, ACL).

---

## 4. Relação com o documento `project-docs/architecture.md`

O scan **complementa** o roadmap com diagrama de camadas e lista de componentes. Está **consistente** com o documento do analista. Sugestão: manter **um** documento “estratégico” (roadmap SSO/OpenAPI) e **um** “tático” (camadas + rotas), e neste arquivo de avaliação **linkar os três** como conjunto oficial.

---

## 5. Referências cruzadas

| Documento | Caminho |
|-----------|---------|
| Roadmap (analista) | `_bmad-output/lumina-arquitetura-roadmap-sso-openapi.md` |
| Arquitetura (scan) | `_bmad-output/project-docs/architecture.md` |
| Índice project-docs | `_bmad-output/project-docs/index.md` |
| **Este parecer** | `_bmad-output/architecture-lumina-avaliacao-e-melhorias.md` |

---

## 6. Capabilities (Winston)

| Código | Descrição | Skill |
|--------|-----------|--------|
| CA | Documentar decisões técnicas guiadas | bmad-create-architecture |
| IR | Alinhar PRD, UX, arquitetura e epics | bmad-check-implementation-readiness |

Para orientação geral do ecossistema BMad: `bmad-help`.

---

*Parecer focado em trade-offs e no que costuma “entrar em produção” com menos surpresa: segurança explícita, contrato de API e medição antes de micro-otimização.*
