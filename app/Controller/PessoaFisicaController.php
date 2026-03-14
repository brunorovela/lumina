<?php

declare(strict_types=1);

namespace App\Controller;

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

    protected function getService(): \App\Contract\ServiceInterface
    {
        return $this->pessoaFisicaService;
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
}
