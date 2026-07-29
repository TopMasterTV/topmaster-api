<?php

declare(strict_types=1);

const ATUALIZAR_STATUS_SISTEMAS_SECURITY_TEST_PASS = 'ATUALIZAR_STATUS_SISTEMAS_SECURITY_TEST_PASS';
const ATUALIZAR_STATUS_SISTEMAS_SECURITY_TEST_FAIL = 'ATUALIZAR_STATUS_SISTEMAS_SECURITY_TEST_FAIL';

function atualizarStatusSistemasSecurityAssert(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException(ATUALIZAR_STATUS_SISTEMAS_SECURITY_TEST_FAIL);
    }
}

function atualizarStatusSistemasSecurityCatchTokens(array $tokens): array
{
    $catchIndex = null;

    foreach ($tokens as $index => $token) {
        if (is_array($token) && $token[0] === T_CATCH) {
            $catchIndex = $index;
        }
    }

    atualizarStatusSistemasSecurityAssert($catchIndex !== null);

    return array_slice($tokens, $catchIndex);
}

function atualizarStatusSistemasSecurityTokenText(array $tokens): string
{
    $text = '';

    foreach ($tokens as $token) {
        $text .= is_array($token) ? $token[1] : $token;
    }

    return $text;
}

function atualizarStatusSistemasSecurityRun(): void
{
    $productionFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'atualizar_status_sistemas.php';
    $source = file_get_contents($productionFile);

    atualizarStatusSistemasSecurityAssert(is_string($source) && $source !== '');

    $tokens = token_get_all($source, TOKEN_PARSE);
    $catchSource = atualizarStatusSistemasSecurityTokenText(
        atualizarStatusSistemasSecurityCatchTokens($tokens)
    );

    atualizarStatusSistemasSecurityAssert(
        str_contains($catchSource, 'http_response_code(500)')
    );
    atualizarStatusSistemasSecurityAssert(
        str_contains($catchSource, '"ATUALIZAR_STATUS_SISTEMAS_INTERNAL_ERROR"')
    );
    atualizarStatusSistemasSecurityAssert(str_contains($catchSource, 'json_encode'));

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
        atualizarStatusSistemasSecurityAssert(
            !str_contains($catchSource, $forbiddenCatchSink)
        );
    }

    foreach ([
        'cliente_id',
        '$limit',
        'sistemas',
        'prepare',
        'bindValue',
        'execute',
        'fetchAll',
        'stream_context_create',
        'file_get_contents',
        'UPDATE',
        'atualizados',
        'falhas',
        'detalhes',
    ] as $requiredFlowMarker) {
        atualizarStatusSistemasSecurityAssert(
            str_contains($source, $requiredFlowMarker)
        );
    }

    foreach ([
        'var_dump',
        'print_r',
        'phpinfo',
        'error_log',
        'getTraceAsString',
    ] as $forbiddenProductionPattern) {
        atualizarStatusSistemasSecurityAssert(
            !str_contains($source, $forbiddenProductionPattern)
        );
    }

    foreach ([
        '/\$(?:usuario|senha)\s*=\s*["\'][^"\']+["\']/',
        '/["\']https?:\/\/[^"\']+["\']/i',
        '/["\'](?:Authorization|Bearer)\s*[: ]/i',
    ] as $forbiddenOperationalLiteral) {
        atualizarStatusSistemasSecurityAssert(
            preg_match($forbiddenOperationalLiteral, $source) === 0
        );
    }

    echo ATUALIZAR_STATUS_SISTEMAS_SECURITY_TEST_PASS, PHP_EOL;
}

try {
    atualizarStatusSistemasSecurityRun();
} catch (Throwable) {
    echo ATUALIZAR_STATUS_SISTEMAS_SECURITY_TEST_FAIL, PHP_EOL;
    exit(1);
}
