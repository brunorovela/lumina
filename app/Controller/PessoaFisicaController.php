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
use App\Request\PessoaFisicaStoreRequest;
use App\Request\PessoaFisicaUpdateRequest;
use App\Service\PessoaFisicaService;
use Psr\Http\Message\ResponseInterface;

class PessoaFisicaController extends AbstractCrudController
{
    public function __construct(
        protected PessoaFisicaService $pessoaFisicaService
    ) {
    }

    public function store(PessoaFisicaStoreRequest $request): ResponseInterface
    {
        $model = $this->pessoaFisicaService->criar($request->validated());
        return $this->response->json([
            'message' => 'Criado com sucesso',
            'id' => $model->cd_pessoa,
        ])->withStatus(201);
    }

    public function update(int $id, PessoaFisicaUpdateRequest $request): ResponseInterface
    {
        $ok = $this->pessoaFisicaService->atualizar($id, $request->validated());
        if (! $ok) {
            return $this->response->json(['message' => 'Recurso não encontrado'])->withStatus(404);
        }
        return $this->response->json(['message' => 'Atualizado com sucesso']);
    }

    protected function getService(): ServiceInterface
    {
        return $this->pessoaFisicaService;
    }
}
