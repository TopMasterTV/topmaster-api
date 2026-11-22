<?php

declare(strict_types=1);

$raiz = dirname(__DIR__);
$falhas = [];

function verificarContratoAutomacao(bool $condicao, string $mensagem): void
{
    global $falhas;
    if (!$condicao) {
        $falhas[] = $mensagem;
        fwrite(STDERR, "FALHA: {$mensagem}\n");
        return;
    }
    fwrite(STDOUT, "OK: {$mensagem}\n");
}

function fonteContratoAutomacao(string $caminho): string
{
    global $raiz;
    $fonte = file_get_contents($raiz . DIRECTORY_SEPARATOR . $caminho);
    if (!is_string($fonte)) {
        throw new RuntimeException("Arquivo ausente: {$caminho}");
    }
    return $fonte;
}

$migration = fonteContratoAutomacao('migrations/013_criar_atualizacao_automatica_config.sql');
$repository = fonteContratoAutomacao('atualizacao_automatica_config_repository.php');
$endpoints = [
    fonteContratoAutomacao('consultar_atualizacao_automatica.php'),
    fonteContratoAutomacao('ativar_atualizacao_automatica.php'),
    fonteContratoAutomacao('desativar_atualizacao_automatica.php'),
];

verificarContratoAutomacao(
    str_contains($migration, 'BEGIN;')
        && str_contains($migration, 'COMMIT;')
        && str_contains($migration, 'CREATE TABLE IF NOT EXISTS atualizacao_automatica_config')
        && str_contains($migration, 'ON CONFLICT (id) DO NOTHING'),
    'migration e transacional e idempotente'
);
foreach (['ativado_em', 'desativado_em', 'proxima_execucao', 'atualizado_em'] as $campo) {
    verificarContratoAutomacao(
        preg_match('/\b' . $campo . '\s+TIMESTAMPTZ\b/i', $migration) === 1,
        "{$campo} usa TIMESTAMPTZ"
    );
}
verificarContratoAutomacao(
    str_contains($migration, 'CHECK (id = 1)')
        && str_contains($migration, 'NOT ativada')
        && str_contains($migration, 'proxima_execucao IS NULL'),
    'migration restringe singleton e consistencia do estado'
);
verificarContratoAutomacao(
    preg_match('/\b(?:clientes|sistemas)\b/i', $migration) !== 1,
    'migration nao referencia clientes ou sistemas'
);
verificarContratoAutomacao(
    preg_match('/\b(?:DROP|TRUNCATE|DELETE)\b/i', $migration) !== 1,
    'migration nao contem operacoes destrutivas'
);

foreach ($endpoints as $endpoint) {
    verificarContratoAutomacao(
        str_contains($endpoint, "header('Cache-Control: no-store')")
            && str_contains($endpoint, "!== 'POST'")
            && str_contains($endpoint, "header('Allow: POST')"),
        'endpoint aceita somente POST e desabilita cache'
    );
    verificarContratoAutomacao(
        str_contains($endpoint, 'autenticarTokenAdministrativo($pdo)')
            && str_contains($endpoint, "!== 'master'")
            && str_contains($endpoint, "'code' => 'FORBIDDEN'"),
        'endpoint usa Bearer administrativo e restringe a Master'
    );
    verificarContratoAutomacao(
        str_contains($endpoint, "'success'")
            && str_contains($endpoint, "'code'")
            && str_contains($endpoint, "'message'"),
        'endpoint mantem envelope JSON padronizado'
    );
    verificarContratoAutomacao(
        !str_contains($endpoint, 'getMessage()')
            && !str_contains($endpoint, 'getTrace')
            && !str_contains($endpoint, 'error_log'),
        'endpoint nao expoe excecoes internas'
    );
}

$todaFonte = $migration . $repository . implode('', $endpoints);
foreach ([
    'file_get_contents(', 'curl_', 'stream_context_create(', 'player_api.php',
    'atualizar_status_sistemas.php', 'atualizar_sistemas_cliente.php',
] as $chamadaProibida) {
    verificarContratoAutomacao(
        !str_contains($todaFonte, $chamadaProibida),
        "implementacao nao usa {$chamadaProibida}"
    );
}
verificarContratoAutomacao(
    !str_contains($repository, '$_POST')
        && !str_contains($repository, '$_GET')
        && !str_contains($repository, 'DateTimeImmutable("now')
        && !str_contains($repository, "DateTimeImmutable('now"),
    'repositorio nao aceita horario do aplicativo nem usa relogio PHP'
);

exit($falhas === [] ? 0 : 1);
