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

namespace App\Controller;

use App\Contract\ServiceInterface;
use App\Request\PessoaStoreRequest;
use App\Request\PessoaUpdateRequest;
use App\Service\PessoaService;
use Psr\Http\Message\ResponseInterface;

class PessoaController extends AbstractCrudController
{
    public function __construct(
        protected PessoaService $pessoaService
    ) {
    }

    public function store(PessoaStoreRequest $request): ResponseInterface
    {
        // Agora o validated() trará todos os campos definidos no Request acima
        $data = $request->validated();

        $pessoa = $this->pessoaService->criar($data);

        return $this->response->json([
            'message' => 'Criado com sucesso',
            'id' => $pessoa->cd_pessoa,
        ])->withStatus(201);
    }

    public function update(int $id, PessoaUpdateRequest $request): ResponseInterface
    {
        $ok = $this->pessoaService->atualizar($id, $request->validated());
        if (! $ok) {
            return $this->response->json(['message' => 'Recurso não encontrado'])->withStatus(404);
        }
        return $this->response->json(['message' => 'Atualizado com sucesso']);
    }

    protected function getService(): ServiceInterface
    {
        return $this->pessoaService;
    }
}
