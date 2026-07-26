<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/roku_xtream_observability.php';

$aprovadosObservabilidadeXtreamRoku = 0;
$falhasObservabilidadeXtreamRoku = 0;

function registrarTesteObservabilidadeXtreamRoku(
    string $nome,
    callable $teste
): void {
    global $aprovadosObservabilidadeXtreamRoku;
    global $falhasObservabilidadeXtreamRoku;

    try {
        $teste();
        $aprovadosObservabilidadeXtreamRoku++;
        echo 'PASS ' . $nome . PHP_EOL;
    } catch (Throwable) {
        $falhasObservabilidadeXtreamRoku++;
        echo 'FAIL ' . $nome . PHP_EOL;
    }
}

function afirmarObservabilidadeXtreamRoku(bool $condicao): void
{
    if (!$condicao) {
        throw new RuntimeException('Falha de teste sanitizada');
    }
}

function afirmarIgualObservabilidadeXtreamRoku(mixed $esperado, mixed $atual): void
{
    afirmarObservabilidadeXtreamRoku($esperado === $atual);
}

registrarTesteObservabilidadeXtreamRoku('request ID seguro', static function (): void {
    $requestId = gerarRequestIdObservabilidadeXtreamRoku();
    afirmarObservabilidadeXtreamRoku(
        preg_match('/\A[0-9a-f]{32}\z/D', $requestId) === 1
    );
});

$codigosPermitidos = [
    'PROVIDER_UNAVAILABLE',
    'PROVIDER_TIMEOUT',
    'INVALID_PROVIDER_RESPONSE',
    'PROVIDER_RESPONSE_TOO_LARGE',
];

foreach ($codigosPermitidos as $codigo) {
    registrarTesteObservabilidadeXtreamRoku(
        'código permitido ' . $codigo,
        static function () use ($codigo): void {
            afirmarIgualObservabilidadeXtreamRoku(
                $codigo,
                normalizarCodigoPublicoObservabilidadeXtreamRoku($codigo)
            );
        }
    );
}

registrarTesteObservabilidadeXtreamRoku('código inesperado', static function (): void {
    afirmarIgualObservabilidadeXtreamRoku(
        'UNKNOWN_SAFE_CODE',
        normalizarCodigoPublicoObservabilidadeXtreamRoku("codigo\nmalicioso")
    );
});

$categoriasPermitidas = [
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
    'PROVIDER_HTTP_3XX',
    'PROVIDER_HTTP_400',
    'PROVIDER_HTTP_401',
    'PROVIDER_HTTP_403',
    'PROVIDER_HTTP_404',
    'PROVIDER_HTTP_429',
    'PROVIDER_HTTP_4XX_OTHER',
    'PROVIDER_HTTP_5XX',
    'PROVIDER_HTTP_OTHER',
    'CURL_OTHER_ERROR',
];

foreach ($categoriasPermitidas as $categoria) {
    registrarTesteObservabilidadeXtreamRoku(
        'categoria permitida ' . $categoria,
        static function () use ($categoria): void {
            afirmarIgualObservabilidadeXtreamRoku(
                $categoria,
                normalizarCategoriaInternaObservabilidadeXtreamRoku($categoria)
            );
        }
    );
}

registrarTesteObservabilidadeXtreamRoku('categoria inesperada', static function (): void {
    afirmarIgualObservabilidadeXtreamRoku(
        'UNKNOWN_SAFE_CATEGORY',
        normalizarCategoriaInternaObservabilidadeXtreamRoku("categoria\rmaliciosa")
    );
});

registrarTesteObservabilidadeXtreamRoku('status 502', static function (): void {
    afirmarIgualObservabilidadeXtreamRoku(
        502,
        normalizarStatusHttpObservabilidadeXtreamRoku(502)
    );
});

registrarTesteObservabilidadeXtreamRoku('status 504', static function (): void {
    afirmarIgualObservabilidadeXtreamRoku(
        504,
        normalizarStatusHttpObservabilidadeXtreamRoku(504)
    );
});

registrarTesteObservabilidadeXtreamRoku('status inesperado', static function (): void {
    afirmarIgualObservabilidadeXtreamRoku(
        502,
        normalizarStatusHttpObservabilidadeXtreamRoku(599)
    );
});

registrarTesteObservabilidadeXtreamRoku('duração normal', static function (): void {
    afirmarIgualObservabilidadeXtreamRoku(
        1500,
        calcularDuracaoMsObservabilidadeXtreamRoku(1_000_000_000, 2_500_000_000)
    );
});

registrarTesteObservabilidadeXtreamRoku('duração nunca negativa', static function (): void {
    afirmarIgualObservabilidadeXtreamRoku(
        0,
        calcularDuracaoMsObservabilidadeXtreamRoku(2_000_000_000, 1_000_000_000)
    );
});

$requestIdFixo = '0123456789abcdef0123456789abcdef';
$linhaEsperada = 'ROKU_XTREAM'
    . ' endpoint=roku_listar_categorias'
    . ' request_id=' . $requestIdFixo
    . ' status=502'
    . ' code=PROVIDER_UNAVAILABLE'
    . ' category=INVALID_RESPONSE'
    . ' duration_ms=123';
$linhaValida = montarLinhaObservabilidadeXtreamRoku(
    $requestIdFixo,
    502,
    'PROVIDER_UNAVAILABLE',
    'INVALID_RESPONSE',
    123
);

registrarTesteObservabilidadeXtreamRoku('formato exato', static function () use (
    $linhaEsperada,
    $linhaValida
): void {
    afirmarIgualObservabilidadeXtreamRoku($linhaEsperada, $linhaValida);
});

registrarTesteObservabilidadeXtreamRoku('sete componentes', static function () use (
    $linhaValida
): void {
    afirmarIgualObservabilidadeXtreamRoku(7, count(explode(' ', $linhaValida)));
});

registrarTesteObservabilidadeXtreamRoku('sem quebra de linha', static function () use (
    $linhaValida
): void {
    afirmarObservabilidadeXtreamRoku(
        !str_contains($linhaValida, "\r") && !str_contains($linhaValida, "\n")
    );
});

registrarTesteObservabilidadeXtreamRoku('sem controles', static function () use (
    $linhaValida
): void {
    afirmarObservabilidadeXtreamRoku(
        preg_match('/[\x00-\x1F\x7F]/', $linhaValida) !== 1
    );
});

$valoresFicticiosProibidos = [
    'https://example.invalid/fornecedor',
    'usuario-ficticio',
    'senha-ficticia',
    str_repeat('a', 64),
    'Author' . 'ization Bearer ' . str_repeat('b', 64),
    '{"resposta":"bruta-ficticia"}',
    'Could not connect ficticio',
    'host.invalid',
    '192.0.2.10',
    'porta=65535',
    'token=ficticio',
    'headers=ficticios',
    'query=ficticia',
    'curl_error_ficticio',
    'errno=987654',
    'provider_http_status=599',
    'provider_status=301',
    'upstream_status=302',
    'exact_status=418',
    'redirect_status=307',
    'redirect_status=308',
    'provider_status=400',
    'provider_status=401',
    'provider_status=403',
    'provider_status=404',
    'provider_status=429',
    'provider_status=500',
    'provider_status=502',
    'Location=https://redirect.invalid/path',
];
$linhaComEntradasMaliciosas = montarLinhaObservabilidadeXtreamRoku(
    "id\nmalicioso",
    599,
    implode('|', $valoresFicticiosProibidos),
    implode('|', array_reverse($valoresFicticiosProibidos)),
    -10
);

registrarTesteObservabilidadeXtreamRoku('entradas maliciosas normalizadas', static function () use (
    $linhaComEntradasMaliciosas
): void {
    afirmarObservabilidadeXtreamRoku(
        str_contains($linhaComEntradasMaliciosas, 'UNKNOWN_SAFE_CODE')
        && str_contains($linhaComEntradasMaliciosas, 'UNKNOWN_SAFE_CATEGORY')
    );
});

foreach ($valoresFicticiosProibidos as $indice => $valorProibido) {
    registrarTesteObservabilidadeXtreamRoku(
        'valor fictício ausente ' . ($indice + 1),
        static function () use ($linhaComEntradasMaliciosas, $valorProibido): void {
            afirmarObservabilidadeXtreamRoku(
                !str_contains($linhaComEntradasMaliciosas, $valorProibido)
            );
        }
    );
}

registrarTesteObservabilidadeXtreamRoku('logger não lança', static function (): void {
    $resultado = emitirLinhaObservabilidadeXtreamRoku(
        '0123456789abcdef0123456789abcdef',
        504,
        'PROVIDER_TIMEOUT',
        'PROVIDER_TIMEOUT',
        15
    );
    afirmarObservabilidadeXtreamRoku(is_bool($resultado));
});

foreach (array_slice($categoriasPermitidas, 4) as $categoria) {
    registrarTesteObservabilidadeXtreamRoku(
        'formato granular ' . $categoria,
        static function () use ($requestIdFixo, $categoria): void {
            $linha = montarLinhaObservabilidadeXtreamRoku(
                $requestIdFixo,
                502,
                'PROVIDER_UNAVAILABLE',
                $categoria,
                0
            );
            $linhaEsperadaGranular = 'ROKU_XTREAM'
                . ' endpoint=roku_listar_categorias'
                . ' request_id=' . $requestIdFixo
                . ' status=502'
                . ' code=PROVIDER_UNAVAILABLE'
                . ' category=' . $categoria
                . ' duration_ms=0';
            afirmarIgualObservabilidadeXtreamRoku($linhaEsperadaGranular, $linha);
            afirmarIgualObservabilidadeXtreamRoku(7, count(explode(' ', $linha)));
            afirmarObservabilidadeXtreamRoku(
                str_contains($linha, ' status=502')
                && str_contains($linha, ' code=PROVIDER_UNAVAILABLE')
                && str_contains($linha, ' category=' . $categoria)
                && str_contains($linha, ' duration_ms=0')
                && !str_contains($linha, 'provider_status=')
                && !str_contains($linha, 'upstream_status=')
                && !str_contains($linha, 'exact_status=')
                && !str_contains($linha, 'redirect_status=')
                && !str_contains($linha, 'Location=')
            );
        }
    );
}

$conteudoHelper = (string) file_get_contents(
    dirname(__DIR__, 2) . '/roku_xtream_observability.php'
);
$referenciasOperacionaisProibidas = [
    'curl_' . 'exec',
    'dns_' . 'get_record',
    'new ' . 'PDO',
    'CURLOPT' . '_',
];

foreach ($referenciasOperacionaisProibidas as $referencia) {
    registrarTesteObservabilidadeXtreamRoku(
        'helper sem operação proibida ' . $referencia,
        static function () use ($conteudoHelper, $referencia): void {
            afirmarObservabilidadeXtreamRoku(
                !str_contains($conteudoHelper, $referencia)
            );
        }
    );
}

echo 'TOTAL PASS=' . $aprovadosObservabilidadeXtreamRoku
    . ' FAIL=' . $falhasObservabilidadeXtreamRoku
    . PHP_EOL;

exit($falhasObservabilidadeXtreamRoku === 0 ? 0 : 1);
