<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/atualizacao_automatica_config_repository.php';

final class AtualizacaoAutomaticaDatabaseFake implements AtualizacaoAutomaticaDatabase
{
    /** @var list<array<string,mixed>|null> */
    public array $resultados = [];
    /** @var list<array{sql:string,parametros:array<string,int|string|null>}> */
    public array $consultas = [];
    public bool $emTransacao = false;
    public int $inicios = 0;
    public int $commits = 0;
    public int $rollbacks = 0;
    public ?Throwable $falha = null;

    public function beginTransaction(): void
    {
        $this->inicios++;
        $this->emTransacao = true;
    }

    public function commit(): void
    {
        $this->commits++;
        $this->emTransacao = false;
    }

    public function rollBack(): void
    {
        $this->rollbacks++;
        $this->emTransacao = false;
    }

    public function inTransaction(): bool
    {
        return $this->emTransacao;
    }

    public function fetchOne(string $sql, array $parametros = []): ?array
    {
        $this->consultas[] = compact('sql', 'parametros');
        if ($this->falha !== null) {
            throw $this->falha;
        }
        return array_shift($this->resultados);
    }
}

function exigirAtualizacaoAutomatica(bool $condicao, string $mensagem): void
{
    if (!$condicao) {
        throw new RuntimeException("FALHA: {$mensagem}");
    }
    fwrite(STDOUT, "OK: {$mensagem}\n");
}

/** @return array<string,mixed> */
function estadoAtualizacaoAutomatica(array $mudancas = []): array
{
    return array_replace([
        'ativada' => false,
        'ativado_em' => null,
        'desativado_em' => null,
        'proxima_execucao' => null,
        'versao_ciclo' => '0',
        'atualizado_em' => '2026-11-21 12:00:00+00',
        'atualizado_por_admin_id' => null,
    ], $mudancas);
}

$consultaDb = new AtualizacaoAutomaticaDatabaseFake();
$consultaDb->resultados[] = estadoAtualizacaoAutomatica();
$consulta = (new AtualizacaoAutomaticaConfigRepository($consultaDb))->consultar();
exigirAtualizacaoAutomatica($consulta['ativada'] === false, 'consulta devolve estado persistido');
exigirAtualizacaoAutomatica(
    $consulta['fuso_horario'] === 'America/Sao_Paulo'
        && str_ends_with((string) $consulta['atualizado_em'], '-03:00'),
    'horarios sao devolvidos inequivocamente em America/Sao_Paulo'
);

$ativaDb = new AtualizacaoAutomaticaDatabaseFake();
$ativaDb->resultados = [
    estadoAtualizacaoAutomatica(),
    estadoAtualizacaoAutomatica([
        'ativada' => true,
        'ativado_em' => '2026-11-21 12:10:00+00',
        'proxima_execucao' => '2026-11-21 13:00:00+00',
        'versao_ciclo' => '1',
        'atualizado_em' => '2026-11-21 12:10:00+00',
        'atualizado_por_admin_id' => '7',
    ]),
];
$ativacao = (new AtualizacaoAutomaticaConfigRepository($ativaDb))->ativar(7);
exigirAtualizacaoAutomatica($ativacao['alterada'] === true, 'ativacao altera estado desativado');
exigirAtualizacaoAutomatica(
    $ativaDb->inicios === 1 && $ativaDb->commits === 1 && $ativaDb->rollbacks === 0,
    'ativacao confirma uma transacao'
);
exigirAtualizacaoAutomatica(
    count($ativaDb->consultas) === 2
        && str_contains($ativaDb->consultas[0]['sql'], 'FOR UPDATE'),
    'ativacao bloqueia a linha singleton antes de alterar'
);
$sqlAtivacao = $ativaDb->consultas[1]['sql'];
exigirAtualizacaoAutomatica(
    substr_count($sqlAtivacao, 'clock_timestamp()') === 1
        && str_contains($sqlAtivacao, "AT TIME ZONE 'America/Sao_Paulo'")
        && str_contains($sqlAtivacao, "date_trunc(")
        && str_contains($sqlAtivacao, "+ INTERVAL '1 hour'"),
    'ativacao captura um relogio PostgreSQL e calcula a proxima hora cheia'
);
exigirAtualizacaoAutomatica(
    str_contains($sqlAtivacao, 'versao_ciclo = config.versao_ciclo + 1'),
    'nova ativacao incrementa a versao do ciclo'
);

$jaAtivaDb = new AtualizacaoAutomaticaDatabaseFake();
$jaAtivaDb->resultados[] = estadoAtualizacaoAutomatica([
    'ativada' => 't',
    'ativado_em' => '2026-11-21 12:10:00+00',
    'proxima_execucao' => '2026-11-21 13:00:00+00',
    'versao_ciclo' => '4',
]);
$jaAtiva = (new AtualizacaoAutomaticaConfigRepository($jaAtivaDb))->ativar(7);
exigirAtualizacaoAutomatica(
    $jaAtiva['alterada'] === false
        && count($jaAtivaDb->consultas) === 1
        && $jaAtiva['estado']['versao_ciclo'] === 4,
    'ativacao repetida preserva programacao e versao'
);

$desativaDb = new AtualizacaoAutomaticaDatabaseFake();
$desativaDb->resultados = [
    estadoAtualizacaoAutomatica([
        'ativada' => true,
        'ativado_em' => '2026-11-21 12:10:00+00',
        'proxima_execucao' => '2026-11-21 13:00:00+00',
        'versao_ciclo' => '2',
    ]),
    estadoAtualizacaoAutomatica([
        'ativado_em' => '2026-11-21 12:10:00+00',
        'desativado_em' => '2026-11-21 12:30:00+00',
        'versao_ciclo' => '2',
        'atualizado_em' => '2026-11-21 12:30:00+00',
        'atualizado_por_admin_id' => '7',
    ]),
];
$desativacao = (new AtualizacaoAutomaticaConfigRepository($desativaDb))->desativar(7);
exigirAtualizacaoAutomatica(
    $desativacao['alterada'] === true
        && $desativacao['estado']['proxima_execucao'] === null
        && str_contains($desativaDb->consultas[1]['sql'], 'proxima_execucao = NULL'),
    'desativacao descarta a proxima execucao'
);
exigirAtualizacaoAutomatica(
    !str_contains($desativaDb->consultas[1]['sql'], 'versao_ciclo ='),
    'desativacao nao cria uma nova versao de ciclo'
);

$jaInativaDb = new AtualizacaoAutomaticaDatabaseFake();
$jaInativaDb->resultados[] = estadoAtualizacaoAutomatica([
    'ativado_em' => '2026-11-21 12:10:00+00',
    'desativado_em' => '2026-11-21 12:30:00+00',
    'versao_ciclo' => '2',
]);
$jaInativa = (new AtualizacaoAutomaticaConfigRepository($jaInativaDb))->desativar(7);
exigirAtualizacaoAutomatica(
    $jaInativa['alterada'] === false && count($jaInativaDb->consultas) === 1,
    'desativacao repetida nao altera timestamps'
);

$falhaDb = new AtualizacaoAutomaticaDatabaseFake();
$falhaDb->falha = new RuntimeException('falha sintetica');
try {
    (new AtualizacaoAutomaticaConfigRepository($falhaDb))->ativar(7);
    exigirAtualizacaoAutomatica(false, 'falha deveria ser propagada');
} catch (RuntimeException $e) {
    exigirAtualizacaoAutomatica(
        $e->getMessage() === 'falha sintetica'
            && $falhaDb->rollbacks === 1
            && $falhaDb->commits === 0,
        'falha durante alteracao executa rollback'
    );
}

// A expressao SQL testada acima equivale a truncar e somar uma hora.
$fuso = new DateTimeZone('America/Sao_Paulo');
foreach ([
    ['2026-11-21 09:10:00', '2026-11-21 10:00:00'],
    ['2026-11-21 10:00:00', '2026-11-21 11:00:00'],
] as [$entrada, $esperada]) {
    $referencia = new DateTimeImmutable($entrada, $fuso);
    $calculada = $referencia->setTime((int) $referencia->format('H'), 0)->modify('+1 hour');
    exigirAtualizacaoAutomatica(
        $calculada->format('Y-m-d H:i:s') === $esperada,
        "calculo de {$entrada} resulta em {$esperada}"
    );
}
