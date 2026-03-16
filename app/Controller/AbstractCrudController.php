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
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Controller CRUD base reutilizável (SOLID - DRY).
 * Delega ao Service e padroniza respostas JSON.
 */
abstract class AbstractCrudController extends AbstractController
{
    public function index(): ResponseInterface
    {
        $items = $this->getService()->listagem($this->request->all());
        return $this->response->json($items);
    }

    public function show(int $id): ResponseInterface
    {
        $model = $this->getService()->buscarPorId($id);
        if ($model === null) {
            return $this->response->json(['message' => 'Recurso não encontrado'])->withStatus(404);
        }
        return $this->response->json($model->toArray());
    }

    public function destroy(int $id): ResponseInterface
    {
        $ok = $this->getService()->remover($id);
        if (! $ok) {
            return $this->response->json(['message' => 'Recurso não encontrado ou não removido'])->withStatus(404);
        }
        return $this->response->json(['message' => 'Removido com sucesso']);
    }

    abstract protected function getService(): ServiceInterface;

    protected function getResponse(): HttpResponse
    {
        return $this->response;
    }
}
