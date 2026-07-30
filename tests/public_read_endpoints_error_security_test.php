<?php

declare(strict_types=1);

const PUBLIC_READ_ENDPOINTS_ERROR_SECURITY_TEST_PASS = 'PUBLIC_READ_ENDPOINTS_ERROR_SECURITY_TEST_PASS';
const PUBLIC_READ_ENDPOINTS_ERROR_SECURITY_TEST_FAIL = 'PUBLIC_READ_ENDPOINTS_ERROR_SECURITY_TEST_FAIL';

function publicReadEndpointsAssert(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException(PUBLIC_READ_ENDPOINTS_ERROR_SECURITY_TEST_FAIL);
    }
}

function publicReadEndpointsTokenText(array $tokens): string
{
    $text = '';

    foreach ($tokens as $token) {
        $text .= is_array($token) ? $token[1] : $token;
    }

    return $text;
}

function publicReadEndpointsLastCatchIndex(array $tokens): int
{
    $catchIndex = null;

    foreach ($tokens as $index => $token) {
        if (is_array($token) && $token[0] === T_CATCH) {
            $catchIndex = $index;
        }
    }

    publicReadEndpointsAssert(is_int($catchIndex));

    return $catchIndex;
}

function publicReadEndpointsValidate(
    string $file,
    string $publicCode,
    array $requiredFlowMarkers
): void {
    $source = file_get_contents($file);

    publicReadEndpointsAssert(is_string($source) && $source !== '');

    $tokens = token_get_all($source, TOKEN_PARSE);
    $catchIndex = publicReadEndpointsLastCatchIndex($tokens);
    $beforeCatch = publicReadEndpointsTokenText(array_slice($tokens, 0, $catchIndex));
    $catchSource = publicReadEndpointsTokenText(array_slice($tokens, $catchIndex));

    publicReadEndpointsAssert(str_contains($catchSource, 'http_response_code(500)'));
    publicReadEndpointsAssert(str_contains($catchSource, $publicCode));
    publicReadEndpointsAssert(str_contains($catchSource, 'json_encode'));

    foreach ([
        'getMessage',
        'getTrace',
        'getTraceAsString',
        'getFile',
        'getLine',
        'var_dump',
        'print_r',
        'phpinfo',
        'error_log',
        'curl_error',
    ] as $forbiddenCatchSink) {
        publicReadEndpointsAssert(!str_contains($catchSource, $forbiddenCatchSink));
    }

    foreach ([
        'detail',
        'exception',
        'trace',
        'debug',
        'prepare',
        'execute',
        'PDO',
        'username',
        'password',
        'Authorization',
        'Bearer',
    ] as $forbiddenCatchDetail) {
        publicReadEndpointsAssert(
            !preg_match(
                '/["\']' . preg_quote($forbiddenCatchDetail, '/') . '["\']\s*=>/i',
                $catchSource
            )
        );
    }

    foreach ($requiredFlowMarkers as $requiredFlowMarker) {
        publicReadEndpointsAssert(str_contains($beforeCatch, $requiredFlowMarker));
    }

    publicReadEndpointsAssert(substr_count($beforeCatch, 'json_encode') >= 1);
    publicReadEndpointsAssert(str_contains($beforeCatch, 'prepare'));
    publicReadEndpointsAssert(str_contains($beforeCatch, 'execute'));

    foreach ([
        'var_dump',
        'print_r',
        'phpinfo',
        'error_log',
        'getTraceAsString',
    ] as $forbiddenProductionPattern) {
        publicReadEndpointsAssert(!str_contains($source, $forbiddenProductionPattern));
    }
}

function publicReadEndpointsRun(): void
{
    $root = dirname(__DIR__);

    publicReadEndpointsValidate(
        $root . DIRECTORY_SEPARATOR . 'buscar_app_update_ativa.php',
        'BUSCAR_APP_UPDATE_ATIVA_INTERNAL_ERROR',
        ['$_GET', 'ORDER BY', 'LIMIT', 'fetch', 'tem_atualizacao']
    );
    publicReadEndpointsValidate(
        $root . DIRECTORY_SEPARATOR . 'buscar_aviso_cliente_ativo.php',
        'BUSCAR_AVISO_CLIENTE_ATIVO_INTERNAL_ERROR',
        ['ORDER BY', 'LIMIT', 'fetch', 'aviso']
    );
    publicReadEndpointsValidate(
        $root . DIRECTORY_SEPARATOR . 'get_config_app.php',
        'GET_CONFIG_APP_INTERNAL_ERROR',
        ['LIMIT', 'fetch', 'configuracoes']
    );

    echo PUBLIC_READ_ENDPOINTS_ERROR_SECURITY_TEST_PASS, PHP_EOL;
}

try {
    publicReadEndpointsRun();
} catch (Throwable) {
    echo PUBLIC_READ_ENDPOINTS_ERROR_SECURITY_TEST_FAIL, PHP_EOL;
    exit(1);
}
