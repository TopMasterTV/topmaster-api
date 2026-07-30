<?php

declare(strict_types=1);

const ENQUETE_PUBLIC_READ_ENDPOINTS_ERROR_SECURITY_TEST_PASS = 'ENQUETE_PUBLIC_READ_ENDPOINTS_ERROR_SECURITY_TEST_PASS';
const ENQUETE_PUBLIC_READ_ENDPOINTS_ERROR_SECURITY_TEST_FAIL = 'ENQUETE_PUBLIC_READ_ENDPOINTS_ERROR_SECURITY_TEST_FAIL';

function enquetePublicReadAssert(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException(ENQUETE_PUBLIC_READ_ENDPOINTS_ERROR_SECURITY_TEST_FAIL);
    }
}

function enquetePublicReadTokenText(array $tokens): string
{
    $text = '';

    foreach ($tokens as $token) {
        $text .= is_array($token) ? $token[1] : $token;
    }

    return $text;
}

function enquetePublicReadLastCatchIndex(array $tokens): int
{
    $catchIndex = null;

    foreach ($tokens as $index => $token) {
        if (is_array($token) && $token[0] === T_CATCH) {
            $catchIndex = $index;
        }
    }

    enquetePublicReadAssert(is_int($catchIndex));

    return $catchIndex;
}

function enquetePublicReadValidate(
    string $file,
    string $publicCode,
    array $requiredFlowMarkers
): void {
    $source = file_get_contents($file);

    enquetePublicReadAssert(is_string($source) && $source !== '');

    $tokens = token_get_all($source, TOKEN_PARSE);
    $catchIndex = enquetePublicReadLastCatchIndex($tokens);
    $beforeCatch = enquetePublicReadTokenText(array_slice($tokens, 0, $catchIndex));
    $catchSource = enquetePublicReadTokenText(array_slice($tokens, $catchIndex));

    enquetePublicReadAssert(str_contains($catchSource, 'http_response_code(500)'));
    enquetePublicReadAssert(str_contains($catchSource, $publicCode));
    enquetePublicReadAssert(str_contains($catchSource, 'json_encode'));

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
        enquetePublicReadAssert(!str_contains($catchSource, $forbiddenCatchSink));
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
        'id',
        'campanha',
        'enquete',
        'respostas',
        'username',
        'password',
    ] as $forbiddenCatchDetail) {
        enquetePublicReadAssert(
            !preg_match(
                '/["\']' . preg_quote($forbiddenCatchDetail, '/') . '["\']\s*=>/i',
                $catchSource
            )
        );
    }

    foreach ($requiredFlowMarkers as $requiredFlowMarker) {
        enquetePublicReadAssert(str_contains($beforeCatch, $requiredFlowMarker));
    }

    enquetePublicReadAssert(substr_count($beforeCatch, 'json_encode') >= 1);
    enquetePublicReadAssert(str_contains($beforeCatch, 'prepare'));
    enquetePublicReadAssert(str_contains($beforeCatch, 'execute'));

    foreach ([
        'var_dump',
        'print_r',
        'phpinfo',
        'error_log',
        'getTraceAsString',
    ] as $forbiddenProductionPattern) {
        enquetePublicReadAssert(!str_contains($source, $forbiddenProductionPattern));
    }
}

function enquetePublicReadRun(): void
{
    $root = dirname(__DIR__);

    enquetePublicReadValidate(
        $root . DIRECTORY_SEPARATOR . 'listar_campanhas_enquete.php',
        'LISTAR_CAMPANHAS_ENQUETE_INTERNAL_ERROR',
        ['ORDER BY', 'fetch', 'campanhas']
    );
    enquetePublicReadValidate(
        $root . DIRECTORY_SEPARATOR . 'listar_enquetes.php',
        'LISTAR_ENQUETES_INTERNAL_ERROR',
        ['ORDER BY', 'LIMIT', 'fetch', 'enquetes']
    );
    enquetePublicReadValidate(
        $root . DIRECTORY_SEPARATOR . 'respostas_enquete.php',
        'RESPOSTAS_ENQUETE_INTERNAL_ERROR',
        ['JOIN', 'ORDER BY', 'fetch', 'respostas']
    );

    echo ENQUETE_PUBLIC_READ_ENDPOINTS_ERROR_SECURITY_TEST_PASS, PHP_EOL;
}

try {
    enquetePublicReadRun();
} catch (Throwable) {
    echo ENQUETE_PUBLIC_READ_ENDPOINTS_ERROR_SECURITY_TEST_FAIL, PHP_EOL;
    exit(1);
}
