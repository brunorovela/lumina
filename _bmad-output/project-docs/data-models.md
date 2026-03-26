# Lumina — Modelos de dados

**ORM:** Hyperf Database (estilo Eloquent). **Namespace:** `App\Model`.

## Resumo

| Model | Tabela | Chave primária | Relações principais |
|-------|--------|----------------|---------------------|
| `Pessoa` | `unim_pessoa` | `cd_pessoa` (auto) | `hasOne` PessoaFisica, PessoaEndereco |
| `PessoaFisica` | `unim_pessoa_fisica` | `cd_pessoa` (não incrementa) | `belongsTo` Pessoa |
| `PessoaEndereco` | `unim_pessoa_endereco` | `cd_pessoa` (não incrementa) | `belongsTo` Pessoa |

## Pessoa (`unim_pessoa`)

- **Fillable (exemplos):** `cd_cliente`, `ds_nome`, `ds_login`, `ds_senha`, campos de qualificação/unidade/turma, `dt_cadastro`.
- **Timestamps Eloquent:** desligados (`$timestamps = false`); uso de `dt_cadastro` / `dt_base` conforme model.
- **Senha:** mutator `setDsSenhaAttribute` — hash com `password_hash`.
- **FK:** `cd_cliente` pode referenciar `saas_cliente` no banco — validar existência ou omitir campo conforme regra de negócio.

## PessoaFisica (`unim_pessoa_fisica`)

- Um registro por `cd_pessoa` (PK = FK para pessoa).
- Campos típicos: estado civil, nomes oficial/social, CPF, nascimento, sexo.

## PessoaEndereco (`unim_pessoa_endereco`)

- Um registro por `cd_pessoa`.
- Campos: país, estado, cidade, CEP, logradouro, número, complemento.

## ACL (dados de apoio)

- `AclRepository` — permissões por perfil (usado pelo `AclService` para hidratar Redis).
- Detalhes exatos das tabelas dependem do schema MySQL; este documento reflete o uso no código.

## Migrações

Não há pasta `database/migrations` padrão visível no scan; schema provavelmente legado/LMS. _(Migrações versionadas no repositório — To be generated)_ se adotarem migrations Hyperf.
