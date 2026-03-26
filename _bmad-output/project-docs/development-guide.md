# Lumina — Guia de desenvolvimento

## Pré-requisitos

- Linux ou macOS (recomendado pelo Hyperf) ou Docker.
- PHP **>= 8.4** com extensões: **Swoole** (ou Swow), **json**, **pcntl**, **openssl**, **pdo**, **pdo_mysql**, **redis** (conforme uso).
- **Composer** 2.x.

## Instalação

```bash
cd /caminho/do/lumina
composer install
```

Copiar ambiente se necessário:

```bash
test -f .env || cp .env.example .env
```

Ajustar `config/autoload/databases.php`, `redis.php`, etc., conforme `.env`.

## Executar o servidor

```bash
composer start
# ou
php ./bin/hyperf.php start
```

Desenvolvimento com reload (watcher):

```bash
composer watch
```

Porta padrão comum em exemplos: **9501** (confirmar em `config/autoload/server.php`).

## Testes e qualidade

```bash
composer test      # cs-check + php-unit + phpstan (conforme composer.json)
composer cs-check
composer php-unit
composer analyse
```

## Estrutura útil para novos recursos

1. **Model** em `app/Model/` (tabela, fillable, casts).
2. **Repository** estendendo `AbstractRepository` + binding no container (Hyperf resolve por tipo).
3. **Service** estendendo `AbstractCrudService` ou serviço dedicado.
4. **FormRequest** estendendo `AbstractFormRequest`.
5. **Controller** estendendo `AbstractCrudController` quando for CRUD padrão.
6. **Rotas** em `config/routes.php`.

## Docker

Existe `docker-compose.yml` na raiz — usar para alinhar ambiente de equipe e CI.

## Documentação gerada

Índice mestre: [_bmad-output/project-docs/index.md](./index.md)
