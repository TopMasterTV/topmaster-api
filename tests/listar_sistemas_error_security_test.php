<?php

declare(strict_types=1);

const LISTAR_SISTEMAS_ERROR_SECURITY_TEST_PASS = 'LISTAR_SISTEMAS_ERROR_SECURITY_TEST_PASS';
const LISTAR_SISTEMAS_ERROR_SECURITY_TEST_FAIL = 'LISTAR_SISTEMAS_ERROR_SECURITY_TEST_FAIL';

function listarSistemasSecurityAssert(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException(LISTAR_SISTEMAS_ERROR_SECURITY_TEST_FAIL);
    }
}

function listarSistemasSecurityTokenText(array $tokens): string
{
    $text = '';

    foreach ($tokens as $token) {
        $text .= is_array($token) ? $token[1] : $token;
    }

    return $text;
}

function listarSistemasSecurityLastCatchIndex(array $tokens): int
{
    $catchIndex = null;

    foreach ($tokens as $index => $token) {
        if (is_array($token) && $token[0] === T_CATCH) {
            $catchIndex = $index;
        }
    }

    listarSistemasSecurityAssert(is_int($catchIndex));

    return $catchIndex;
}

function listarSistemasSecurityRun(): void
{
    $productionFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'listar_sistemas.php';
    $source = file_get_contents($productionFile);

    listarSistemasSecurityAssert(is_string($source) && $source !== '');

    $tokens = token_get_all($source, TOKEN_PARSE);
    $catchIndex = listarSistemasSecurityLastCatchIndex($tokens);
    $beforeCatch = listarSistemasSecurityTokenText(array_slice($tokens, 0, $catchIndex));
    $catchSource = listarSistemasSecurityTokenText(array_slice($tokens, $catchIndex));

    listarSistemasSecurityAssert(str_contains($catchSource, 'http_response_code(500)'));
    listarSistemasSecurityAssert(str_contains($catchSource, 'LISTAR_SISTEMAS_INTERNAL_ERROR'));
    listarSistemasSecurityAssert(str_contains($catchSource, 'json_encode'));

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
    ] as $forbiddenCatchSink) {
        listarSistemasSecurityAssert(!str_contains($catchSource, $forbiddenCatchSink));
    }

    foreach ([
        'detail',
        'details',
        'exception',
        'trace',
        'debug',
        'sql',
        'query',
        'file',
        'line',
        'internal_message',
        'url',
        'username',
        'password',
        'cliente',
        'sistema',
    ] as $forbiddenCatchDetail) {
        listarSistemasSecurityAssert(
            !preg_match(
                '/["\']' . preg_quote($forbiddenCatchDetail, '/') . '["\']\s*=>/i',
                $catchSource
            )
        );
    }

    foreach ([
        '$_POST',
        'prepare',
        'execute',
        'JOIN',
        'ORDER BY',
        'fetchAll',
        'cliente',
        'sistemas',
        'json_encode',
    ] as $requiredFlowMarker) {
        listarSistemasSecurityAssert(str_contains($beforeCatch, $requiredFlowMarker));
    }

    listarSistemasSecurityAssert(
        preg_match('/["\']success["\']\s*=>\s*true/', $beforeCatch) === 1
    );
    listarSistemasSecurityAssert(
        preg_match('/["\']sistemas["\']\s*=>/', $beforeCatch) === 1
    );

    foreach ([
        'var_dump',
        'print_r',
        'phpinfo',
        'error_log',
        'getTraceAsString',
    ] as $forbiddenProductionPattern) {
        listarSistemasSecurityAssert(!str_contains($source, $forbiddenProductionPattern));
    }

    echo LISTAR_SISTEMAS_ERROR_SECURITY_TEST_PASS, PHP_EOL;
}

try {
    listarSistemasSecurityRun();
} catch (Throwable) {
    echo LISTAR_SISTEMAS_ERROR_SECURITY_TEST_FAIL, PHP_EOL;
    exit(1);
}
