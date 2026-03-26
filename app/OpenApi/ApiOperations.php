<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\OpenApi;

use Hyperf\Swagger\Annotation\HyperfServer;
use OpenApi\Attributes as OA;

/**
 * Stubs só para documentação OpenAPI (Hyperf BuildPathsProcessor exige #[HyperfServer] na classe).
 * Rotas reais: config/routes.php.
 */
#[HyperfServer(name: 'http')]
final class ApiOperations
{
    // --- Pessoa ---
    #[OA\Get(path: '/pessoa', summary: 'Listar pessoas', tags: ['Pessoa'])]
    #[OA\Response(response: 200, description: 'JSON array')]
    public function docPessoaIndex(): void
    {
    }

    #[OA\Get(path: '/pessoa/{id}', summary: 'Obter pessoa', tags: ['Pessoa'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'OK')]
    #[OA\Response(response: 404, description: 'Não encontrado')]
    public function docPessoaShow(): void
    {
    }

    #[OA\Post(path: '/pessoa', summary: 'Criar pessoa', tags: ['Pessoa'])]
    #[OA\Response(response: 201, description: 'Criado')]
    public function docPessoaStore(): void
    {
    }

    #[OA\Put(path: '/pessoa/{id}', summary: 'Atualizar pessoa', tags: ['Pessoa'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'OK')]
    #[OA\Response(response: 404, description: 'Não encontrado')]
    public function docPessoaUpdate(): void
    {
    }

    #[OA\Delete(path: '/pessoa/{id}', summary: 'Remover pessoa', tags: ['Pessoa'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'OK')]
    #[OA\Response(response: 404, description: 'Não encontrado')]
    public function docPessoaDestroy(): void
    {
    }

    // --- Pessoa física ---
    #[OA\Get(path: '/pessoa-fisica', summary: 'Listar', tags: ['PessoaFisica'])]
    #[OA\Response(response: 200, description: 'JSON array')]
    public function docPfIndex(): void
    {
    }

    #[OA\Get(path: '/pessoa-fisica/{id}', summary: 'Obter', tags: ['PessoaFisica'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'OK')]
    public function docPfShow(): void
    {
    }

    #[OA\Post(path: '/pessoa-fisica', summary: 'Criar', tags: ['PessoaFisica'])]
    #[OA\Response(response: 201, description: 'Criado')]
    public function docPfStore(): void
    {
    }

    #[OA\Put(path: '/pessoa-fisica/{id}', summary: 'Atualizar', tags: ['PessoaFisica'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'OK')]
    public function docPfUpdate(): void
    {
    }

    #[OA\Delete(path: '/pessoa-fisica/{id}', summary: 'Remover', tags: ['PessoaFisica'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'OK')]
    public function docPfDestroy(): void
    {
    }

    // --- Pessoa endereço ---
    #[OA\Get(path: '/pessoa-endereco', summary: 'Listar', tags: ['PessoaEndereco'])]
    #[OA\Response(response: 200, description: 'JSON array')]
    public function docPeIndex(): void
    {
    }

    #[OA\Get(path: '/pessoa-endereco/{id}', summary: 'Obter', tags: ['PessoaEndereco'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'OK')]
    public function docPeShow(): void
    {
    }

    #[OA\Post(path: '/pessoa-endereco', summary: 'Criar', tags: ['PessoaEndereco'])]
    #[OA\Response(response: 201, description: 'Criado')]
    public function docPeStore(): void
    {
    }

    #[OA\Put(path: '/pessoa-endereco/{id}', summary: 'Atualizar', tags: ['PessoaEndereco'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'OK')]
    public function docPeUpdate(): void
    {
    }

    #[OA\Delete(path: '/pessoa-endereco/{id}', summary: 'Remover', tags: ['PessoaEndereco'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'OK')]
    public function docPeDestroy(): void
    {
    }
}
