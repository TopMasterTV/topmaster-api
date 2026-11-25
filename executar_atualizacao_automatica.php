<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/atualizacao_automatica_executor.php';

$executor = new AtualizacaoAutomaticaExecutor(
    new AtualizacaoAutomaticaPdoStore($pdo),
    new AtualizacaoAutomaticaXtreamUpdater($pdo),
);

fwrite(
    STDOUT,
    json_encode($executor->executarUmCiclo(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . PHP_EOL
);
