<?php

declare(strict_types=1);

$raiz = dirname(__DIR__);
$migration = file_get_contents($raiz . '/migrations/015_criar_atualizacao_automatica_execucoes.sql');
$executor = file_get_contents($raiz . '/atualizacao_automatica_executor.php');
$entrada = file_get_contents($raiz . '/executar_atualizacao_automatica.php');
$configuracao = file_get_contents($raiz . '/atualizacao_automatica_config_repository.php');

function exigirContratoExecutor(bool $condicao, string $mensagem): void
{
    if (!$condicao) {
        throw new RuntimeException('FALHA: ' . $mensagem);
    }
    fwrite(STDOUT, 'OK: ' . $mensagem . PHP_EOL);
}

exigirContratoExecutor(
    str_contains($migration, 'BEGIN;')
        && str_contains($migration, 'atualizacao_automatica_ciclos')
        && str_contains($migration, 'atualizacao_automatica_resultados')
        && str_contains($migration, 'atualizacao_automatica_pendencias')
        && str_contains($migration, 'cliente_id')
        && str_contains($migration, 'sistema_id'),
    'migration registra ciclos e resultados por cliente e sistema'
);
exigirContratoExecutor(
    str_contains($executor, 'FOR UPDATE')
        && str_contains($executor, 'ON CONFLICT (versao_ciclo,agendado_para) DO NOTHING')
        && str_contains($executor, "status='falhou'")
        && str_contains($executor, "INTERVAL '90 minutes'"),
    'reserva evita duplicidade e recupera ciclo interrompido'
);
exigirContratoExecutor(
    str_contains($configuracao, "date_trunc(")
        && str_contains($configuracao, "+ INTERVAL '1 hour'")
        && str_contains($configuracao, 'versao_ciclo = config.versao_ciclo + 1'),
    'reativacao agenda somente a proxima hora cheia em nova versao'
);
exigirContratoExecutor(
    str_contains($executor, 's.vencimento IS NULL OR s.vencimento<=CURRENT_DATE')
        && str_contains($executor, 'elegivel_novamente_em<=clock_timestamp()'),
    'consulta seleciona somente sistemas elegiveis e evita repeticao'
);
exigirContratoExecutor(
    str_contains($executor, 'private readonly int $limiteClientes = 25')
        && str_contains($executor, 'array_slice($clienteIds, 0, $this->limiteClientes)')
        && !str_contains($executor, 'limitesPorProvedor'),
    'executor limita clientes distintos sem limite separado por provedor'
);
exigirContratoExecutor(
    str_contains($executor, "8=>'IJ'")
        && str_contains($executor, "21=>'#'")
        && str_contains($executor, "'America/Sao_Paulo'"),
    'executor contem grade aprovada e usa horario de Brasilia'
);
exigirContratoExecutor(
    str_contains($entrada, "PHP_SAPI !== 'cli'")
        && !str_contains($entrada, 'atualizar_status_sistemas.php'),
    'entrada e exclusiva para CLI e nao reutiliza worker em massa'
);
