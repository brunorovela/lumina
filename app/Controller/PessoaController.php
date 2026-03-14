<?php

declare(strict_types=1);

namespace App\Controller;

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

    protected function getService(): \App\Contract\ServiceInterface
    {
        return $this->pessoaService;
    }

    public function store(PessoaStoreRequest $request): ResponseInterface
    {
        $data = $request->validated();
        if ($data === [] && method_exists($request, 'getParsedBody')) {
            $parsed = $request->getParsedBody();
            $data = is_array($parsed) ? $parsed : [];
        }
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
}
