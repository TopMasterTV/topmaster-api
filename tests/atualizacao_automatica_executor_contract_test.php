<?php

declare(strict_types=1);

$raiz = dirname(__DIR__);
$migration = file_get_contents($raiz . '/migrations/015_criar_atualizacao_automatica_execucoes.sql');
$executor = file_get_contents($raiz . '/atualizacao_automatica_executor.php');
$entrada = file_get_contents($raiz . '/executar_atualizacao_automatica.php');

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
        && str_contains($migration, 'cliente_id')
        && str_contains($migration, 'sistema_id'),
    'migration registra ciclos e resultados por cliente e sistema'
);
exigirContratoExecutor(
    str_contains($executor, 's.vencimento IS NULL OR s.vencimento <= CURRENT_DATE')
        && str_contains($executor, 'elegivel_novamente_em <= clock_timestamp()'),
    'consulta seleciona somente sistemas elegiveis e evita repeticao'
);
exigirContratoExecutor(
    str_contains($executor, "['GP' => 5, '*' => 25]")
        && str_contains($executor, 'aplicarLimites'),
    'executor limita chamadas por provedor com protecao especifica para GP'
);
exigirContratoExecutor(
    str_contains($entrada, "PHP_SAPI !== 'cli'")
        && !str_contains($entrada, 'atualizar_status_sistemas.php'),
    'entrada e exclusiva para CLI e nao reutiliza worker em massa'
);
