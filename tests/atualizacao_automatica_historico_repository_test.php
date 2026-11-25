<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/atualizacao_automatica_historico_repository.php';

final class HistoricoDatabaseFake implements AtualizacaoAutomaticaHistoricoDatabase
{
    /** @var list<array<string,mixed>|null> */
    public array $unicos = [];
    /** @var list<list<array<string,mixed>>> */
    public array $listas = [];
    /** @var list<array{tipo:string,sql:string,parametros:array<string,int|string|null>}> */
    public array $consultas = [];

    public function fetchOne(string $sql, array $parametros = []): ?array
    {
        $this->consultas[] = ['tipo' => 'one', 'sql' => $sql, 'parametros' => $parametros];
        return array_shift($this->unicos);
    }

    public function fetchAll(string $sql, array $parametros = []): array
    {
        $this->consultas[] = ['tipo' => 'all', 'sql' => $sql, 'parametros' => $parametros];
        return array_shift($this->listas) ?? [];
    }
}

function okHistorico(bool $condicao, string $mensagem): void
{
    if (!$condicao) {
        throw new RuntimeException("FALHA: {$mensagem}");
    }
    fwrite(STDOUT, "OK: {$mensagem}\n");
}

/** @return array<string,mixed> */
function cicloHistorico(int $id, array $mudancas = []): array
{
    return array_replace([
        'id' => (string) $id,
        'versao_ciclo' => '2',
        'agendado_para' => '2026-11-25 03:00:00+00',
        'iniciado_em' => '2026-11-25 03:00:01+00',
        'finalizado_em' => '2026-11-25 03:02:00+00',
        'status' => 'concluido',
        'tipo' => 'grade',
        'grupo' => 'A',
        'total_elegiveis' => '3',
        'total_processados' => '2',
        'total_sistemas_consultados' => '3',
        'total_sucessos' => '2',
        'total_falhas' => '1',
        'total_pendencias_atuais' => '1',
    ], $mudancas);
}

$vazio = new HistoricoDatabaseFake();
$vazio->unicos[] = ['total' => '0'];
$vazio->listas[] = [];
$listaVazia = (new AtualizacaoAutomaticaHistoricoRepository($vazio))->listar();
okHistorico(
    $listaVazia['ciclos'] === []
        && $listaVazia['paginacao'] === ['pagina' => 1, 'limite' => 20, 'total' => 0, 'total_paginas' => 0],
    'historico vazio retorna lista e paginacao consistentes'
);

$multiplo = new HistoricoDatabaseFake();
$multiplo->unicos[] = ['total' => '3'];
$multiplo->listas[] = [cicloHistorico(3), cicloHistorico(2, ['tipo' => 'recuperacao', 'grupo' => '#'])];
$pagina = (new AtualizacaoAutomaticaHistoricoRepository($multiplo))->listar(2, 2);
okHistorico(
    count($pagina['ciclos']) === 2
        && $pagina['paginacao']['total_paginas'] === 2
        && $pagina['ciclos'][0]['tipo'] === 'regular'
        && $pagina['ciclos'][1]['tipo'] === 'recuperacao',
    'multiplos ciclos sao normalizados com tipo regular e recuperacao'
);
okHistorico(
    $multiplo->consultas[1]['parametros'] === [':limite' => 2, ':offset' => 2]
        && str_contains($multiplo->consultas[1]['sql'], 'LIMIT CAST(:limite AS INTEGER)')
        && str_contains($multiplo->consultas[1]['sql'], 'OFFSET CAST(:offset AS INTEGER)'),
    'paginacao usa limite e offset parametrizados'
);

foreach ([[0, 20], [1000001, 20], [1, 0], [1, 51]] as [$paginaInvalida, $limiteInvalido]) {
    try {
        (new AtualizacaoAutomaticaHistoricoRepository(new HistoricoDatabaseFake()))
            ->listar($paginaInvalida, $limiteInvalido);
        okHistorico(false, 'paginacao invalida deveria falhar');
    } catch (InvalidArgumentException) {
        okHistorico(true, 'paginacao invalida e rejeitada');
    }
}

$ausente = new HistoricoDatabaseFake();
$ausente->unicos[] = null;
okHistorico(
    (new AtualizacaoAutomaticaHistoricoRepository($ausente))->detalhar(999) === null,
    'ciclo inexistente retorna ausente sem fabricar dados'
);

$detalheDb = new HistoricoDatabaseFake();
$detalheDb->unicos[] = cicloHistorico(7);
$detalheDb->listas[] = [
    [
        'cliente_id' => '10', 'cliente_nome' => 'Cliente Ficticio',
        'sistema_id' => '100', 'sistema_nome' => 'Sistema A', 'provedor' => 'Provedor Ficticio',
        'status' => 'atualizado', 'vencimento_anterior' => '2026-11-20',
        'vencimento_novo' => '2026-12-20', 'codigo_falha' => null,
        'detalhe_falha' => null, 'verificado_em' => '2026-11-25 03:01:00+00',
    ],
    [
        'cliente_id' => '10', 'cliente_nome' => 'Cliente Ficticio',
        'sistema_id' => '101', 'sistema_nome' => 'Sistema B', 'provedor' => 'Provedor Ficticio',
        'status' => 'falha', 'vencimento_anterior' => '2026-11-20',
        'vencimento_novo' => null, 'codigo_falha' => 'UPDATER_EXCEPTION',
        'detalhe_falha' => 'Falha operacional', 'verificado_em' => '2026-11-25 03:01:30+00',
    ],
];
$detalheDb->listas[] = [[
    'cliente_id' => '10', 'cliente_nome' => 'Cliente Ficticio', 'grupo_origem' => 'A',
    'motivo' => 'falha', 'criada_em' => '2026-11-25 03:01:30+00',
    'atualizada_em' => '2026-11-25 03:01:30+00',
]];
$detalhe = (new AtualizacaoAutomaticaHistoricoRepository($detalheDb))->detalhar(7);
okHistorico(
    $detalhe !== null
        && count($detalhe['clientes']) === 1
        && count($detalhe['clientes'][0]['sistemas']) === 2,
    'cliente com varios sistemas aparece uma unica vez'
);
okHistorico(
    $detalhe['clientes'][0]['sistemas'][1]['codigo_falha'] === 'UPDATER_EXCEPTION'
        && $detalhe['pendencias_atuais'][0]['motivo'] === 'falha',
    'falhas e pendencias reais sao expostas no detalhe'
);
okHistorico(
    array_column($detalheDb->consultas, 'parametros') === [
        [':ciclo_id' => 7], [':ciclo_id' => 7], [':ciclo_id' => 7],
    ],
    'ciclo especifico usa somente parametro preparado'
);

$serializado = json_encode($detalhe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
okHistorico(
    is_string($serializado)
        && preg_match('/senha|token|m3u_url|url_padrao|usuario/i', $serializado) !== 1,
    'resposta nao inclui credenciais tokens ou URLs internas'
);
