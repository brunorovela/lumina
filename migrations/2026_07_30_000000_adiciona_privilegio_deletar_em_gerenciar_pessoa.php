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
use Hyperf\Database\Migrations\Migration;
use Hyperf\DbConnection\Db;

/*
 * DELETE /pessoas/{id} exige GERENCIAR_PESSOA + DELETAR, par que não existia em
 * ulms_recurso_privilegio — o LMS atual nunca expôs exclusão de pessoa, então o
 * privilégio DELETAR (que já existe em ulms_privilegio) nunca foi ligado ao recurso
 * GERENCIAR_PESSOA.
 *
 * Esta migration só torna o privilégio ATRIBUÍVEL (aparece na tela de Gerenciar
 * Permissões do LMS). Ela NÃO concede nada a perfil nenhum: continua sendo necessário
 * inserir a linha em lgin_perfil_recurso_privilegio para algum perfil poder excluir.
 */
return new class extends Migration {
    private const RECURSO = 'GERENCIAR_PESSOA';

    private const PRIVILEGIO = 'DELETAR';

    public function up(): void
    {
        [$cdRecurso, $cdPrivilegio] = $this->codigos();

        if ($cdRecurso === null || $cdPrivilegio === null) {
            return;
        }

        $jaExiste = Db::table('ulms_recurso_privilegio')
            ->where('cd_recurso', $cdRecurso)
            ->where('cd_privilegio', $cdPrivilegio)
            ->exists();

        if ($jaExiste) {
            return;
        }

        Db::table('ulms_recurso_privilegio')->insert([
            'cd_recurso' => $cdRecurso,
            'cd_privilegio' => $cdPrivilegio,
        ]);
    }

    public function down(): void
    {
        [$cdRecurso, $cdPrivilegio] = $this->codigos();

        if ($cdRecurso === null || $cdPrivilegio === null) {
            return;
        }

        $cdRecursoPrivilegio = Db::table('ulms_recurso_privilegio')
            ->where('cd_recurso', $cdRecurso)
            ->where('cd_privilegio', $cdPrivilegio)
            ->value('cd_recurso_privilegio');

        if ($cdRecursoPrivilegio === null) {
            return;
        }

        // Concessões a perfis apontam para cd_recurso_privilegio; removê-las primeiro
        // evita deixar lgin_perfil_recurso_privilegio com referência órfã.
        Db::table('lgin_perfil_recurso_privilegio')
            ->where('cd_recurso_privilegio', $cdRecursoPrivilegio)
            ->delete();

        Db::table('ulms_recurso_privilegio')
            ->where('cd_recurso_privilegio', $cdRecursoPrivilegio)
            ->delete();
    }

    /**
     * @return array{0: null|int, 1: null|int}
     */
    private function codigos(): array
    {
        $cdRecurso = Db::table('ulms_recurso')->where('ds_chave', self::RECURSO)->value('cd_recurso');
        $cdPrivilegio = Db::table('ulms_privilegio')->where('ds_chave', self::PRIVILEGIO)->value('cd_privilegio');

        return [
            $cdRecurso === null ? null : (int) $cdRecurso,
            $cdPrivilegio === null ? null : (int) $cdPrivilegio,
        ];
    }
};
