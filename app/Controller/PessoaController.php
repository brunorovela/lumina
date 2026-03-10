<?php
namespace App\Controller;

use App\Request\PessoaStoreRequest;
use App\Model\Pessoa;

class PessoaController extends AbstractController
{
    public function store(PessoaStoreRequest $request)
    {
        // Se o código chegar aqui, os dados JÁ ESTÃO VALIDADOS
        $validated = $request->validated();

        $pessoa = Pessoa::create($validated);

        return ['id' => $pessoa->cd_pessoa, 'status' => 'Criado'];
    }
}