<?php

declare(strict_types=1);

const ROKU_XTREAM_REQUEST_ID_FALLBACK = '00000000000000000000000000000000';

function gerarRequestIdObservabilidadeXtreamRoku(): string
{
    try {
        $requestId = bin2hex(random_bytes(16));

        if (preg_match('/\A[0-9a-f]{32}\z/D', $requestId) === 1) {
            return $requestId;
        }
    } catch (Throwable) {
        // O fallback fixo evita que uma falha de entropia altere a resposta pública.
    }

    return ROKU_XTREAM_REQUEST_ID_FALLBACK;
}

function normalizarCodigoPublicoObservabilidadeXtreamRoku(string $codigo): string
{
    $permitidos = [
        'PROVIDER_UNAVAILABLE',
        'PROVIDER_TIMEOUT',
        'INVALID_PROVIDER_RESPONSE',
        'PROVIDER_RESPONSE_TOO_LARGE',
    ];

    return in_array($codigo, $permitidos, true)
        ? $codigo
        : 'UNKNOWN_SAFE_CODE';
}

function normalizarCategoriaInternaObservabilidadeXtreamRoku(string $categoria): string
{
    $permitidas = [
        'PROVIDER_UNAVAILABLE',
        'PROVIDER_TIMEOUT',
        'INVALID_RESPONSE',
        'RESPONSE_TOO_LARGE',
        'URL_REJECTED',
        'URL_SCHEME_REJECTED',
        'URL_COMPONENT_REJECTED',
        'DNS_NO_RESULTS',
        'DNS_NON_PUBLIC_IP',
        'SSRF_BLOCKED',
        'CURL_DNS_ERROR',
        'CURL_CONNECTION_ERROR',
        'CURL_TLS_ERROR',
        'PROVIDER_HTTP_NON_2XX',
        'CURL_OTHER_ERROR',
    ];

    return in_array($categoria, $permitidas, true)
        ? $categoria
        : 'UNKNOWN_SAFE_CATEGORY';
}

function normalizarStatusHttpObservabilidadeXtreamRoku(int $statusHttp): int
{
    return in_array($statusHttp, [502, 504], true) ? $statusHttp : 502;
}

function calcularDuracaoMsObservabilidadeXtreamRoku(
    int $inicioNanos,
    ?int $fimNanos = null
): int {
    $inicioSeguro = max(0, $inicioNanos);
    $fimSeguro = max(0, $fimNanos ?? hrtime(true));

    if ($fimSeguro <= $inicioSeguro) {
        return 0;
    }

    $duracaoNanos = $fimSeguro - $inicioSeguro;
    $duracaoMs = intdiv($duracaoNanos, 1_000_000);

    return min(PHP_INT_MAX, max(0, $duracaoMs));
}

function montarLinhaObservabilidadeXtreamRoku(
    string $requestId,
    int $statusHttp,
    string $codigoPublico,
    string $categoriaInterna,
    int $duracaoMs
): string {
    $requestIdSeguro = preg_match('/\A[0-9a-f]{32}\z/D', $requestId) === 1
        ? $requestId
        : ROKU_XTREAM_REQUEST_ID_FALLBACK;
    $statusSeguro = normalizarStatusHttpObservabilidadeXtreamRoku($statusHttp);
    $codigoSeguro = normalizarCodigoPublicoObservabilidadeXtreamRoku($codigoPublico);
    $categoriaSegura = normalizarCategoriaInternaObservabilidadeXtreamRoku($categoriaInterna);
    $duracaoSegura = min(PHP_INT_MAX, max(0, $duracaoMs));

    return 'ROKU_XTREAM'
        . ' endpoint=roku_listar_categorias'
        . ' request_id=' . $requestIdSeguro
        . ' status=' . $statusSeguro
        . ' code=' . $codigoSeguro
        . ' category=' . $categoriaSegura
        . ' duration_ms=' . $duracaoSegura;
}

function emitirLinhaObservabilidadeXtreamRoku(
    string $requestId,
    int $statusHttp,
    string $codigoPublico,
    string $categoriaInterna,
    int $duracaoMs
): bool {
    try {
        $linha = montarLinhaObservabilidadeXtreamRoku(
            $requestId,
            $statusHttp,
            $codigoPublico,
            $categoriaInterna,
            $duracaoMs
        );

        error_log($linha);

        return true;
    } catch (Throwable) {
        return false;
    }
}
