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
 * Concede GERENCIAR_PESSOA + DELETAR a todo perfil com ds_chave='ADMINISTRADOR', para que
 * DELETE /pessoas/{id} deixe de ser 403 permanente.
 *
 * lgin_perfil é por cliente: existe um ADMINISTRADOR por tenant (308 na base de dev). Os
 * outros privilégios de GERENCIAR_PESSOA (ACESSAR, ATUALIZAR, INSERIR,
 * VISUALIZAR_TODAS_PESSOAS_BUSCA) já estão concedidos aos 308 de forma uniforme, então
 * conceder DELETAR ao papel inteiro segue o mesmo provisionamento — e não a um cliente só.
 *
 * Depende de 2026_07_30_000000_adiciona_privilegio_deletar_em_gerenciar_pessoa, que cria o
 * par em ulms_recurso_privilegio.
 *
 * ATENÇÃO: AclService cacheia permissão por perfil no Redis (acl:perfil:{cd_perfil}, TTL
 * 24h). Depois de rodar, invalide o cache dos perfis afetados, senão a concessão só passa a
 * valer quando a chave expirar.
 */
return new class extends Migration {
    private const RECURSO = 'GERENCIAR_PESSOA';

    private const PRIVILEGIO = 'DELETAR';

    private const CHAVE_PERFIL = 'ADMINISTRADOR';

    public function up(): void
    {
        $cdRecursoPrivilegio = $this->cdRecursoPrivilegio();

        if ($cdRecursoPrivilegio === null) {
            return;
        }

        $agora = date('Y-m-d H:i:s');

        $cdPerfis = Db::table('lgin_perfil')
            ->where('ds_chave', self::CHAVE_PERFIL)
            ->whereNotExists(function ($query) use ($cdRecursoPrivilegio) {
                $query->select(Db::raw(1))
                    ->from('lgin_perfil_recurso_privilegio as lprp')
                    ->whereColumn('lprp.cd_perfil', 'lgin_perfil.cd_perfil')
                    ->where('lprp.cd_recurso_privilegio', $cdRecursoPrivilegio);
            })
            ->pluck('cd_perfil');

        foreach ($cdPerfis->chunk(200) as $lote) {
            $linhas = [];

            foreach ($lote as $cdPerfil) {
                $linhas[] = [
                    'cd_perfil' => (int) $cdPerfil,
                    'cd_recurso_privilegio' => $cdRecursoPrivilegio,
                    'dt_cadastro' => $agora,
                ];
            }

            Db::table('lgin_perfil_recurso_privilegio')->insert($linhas);
        }
    }

    public function down(): void
    {
        $cdRecursoPrivilegio = $this->cdRecursoPrivilegio();

        if ($cdRecursoPrivilegio === null) {
            return;
        }

        $cdPerfis = Db::table('lgin_perfil')
            ->where('ds_chave', self::CHAVE_PERFIL)
            ->pluck('cd_perfil')
            ->map(fn ($cdPerfil) => (int) $cdPerfil)
            ->all();

        Db::table('lgin_perfil_recurso_privilegio')
            ->where('cd_recurso_privilegio', $cdRecursoPrivilegio)
            ->whereIn('cd_perfil', $cdPerfis)
            ->delete();
    }

    private function cdRecursoPrivilegio(): ?int
    {
        $cdRecursoPrivilegio = Db::table('ulms_recurso_privilegio as urp')
            ->join('ulms_recurso as ur', 'ur.cd_recurso', '=', 'urp.cd_recurso')
            ->join('ulms_privilegio as up', 'up.cd_privilegio', '=', 'urp.cd_privilegio')
            ->where('ur.ds_chave', self::RECURSO)
            ->where('up.ds_chave', self::PRIVILEGIO)
            ->value('urp.cd_recurso_privilegio');

        return $cdRecursoPrivilegio === null ? null : (int) $cdRecursoPrivilegio;
    }
};
