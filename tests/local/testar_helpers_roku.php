<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/roku_xtream_categories.php';

/*
 * Teste exclusivamente local e offline.
 *
 * Não consulta DNS, não realiza chamadas cURL, não conecta ao PostgreSQL,
 * não utiliza dados reais e não deve ser usado no Render.
 */

$aprovadosHelperRoku = 0;
$falhasHelperRoku = 0;

function registrarTesteHelperRoku(string $nome, callable $teste): void
{
    global $aprovadosHelperRoku, $falhasHelperRoku;

    try {
        $teste();
        $aprovadosHelperRoku++;
        echo 'PASS: ', $nome, PHP_EOL;
    } catch (Throwable) {
        $falhasHelperRoku++;
        echo 'FAIL: ', $nome, PHP_EOL;
    }
}

function afirmarVerdadeiroHelperRoku(bool $valor): void
{
    if ($valor !== true) {
        throw new RuntimeException('Falha de asserção');
    }
}

function afirmarFalsoHelperRoku(bool $valor): void
{
    if ($valor !== false) {
        throw new RuntimeException('Falha de asserção');
    }
}

function afirmarIgualHelperRoku(mixed $esperado, mixed $obtido): void
{
    if ($obtido !== $esperado) {
        throw new RuntimeException('Falha de asserção');
    }
}

function afirmarNuloHelperRoku(mixed $valor): void
{
    if ($valor !== null) {
        throw new RuntimeException('Falha de asserção');
    }
}

function afirmarExcecaoHelperRoku(callable $acao, string $classeEsperada): Throwable
{
    try {
        $acao();
    } catch (Throwable $excecao) {
        if ($excecao instanceof $classeEsperada) {
            return $excecao;
        }

        throw new RuntimeException('Tipo de exceção inesperado');
    }

    throw new RuntimeException('Exceção esperada não lançada');
}

registrarTesteHelperRoku('PHP 8.2 ou superior', static function (): void {
    afirmarVerdadeiroHelperRoku(PHP_VERSION_ID >= 80200);
});

registrarTesteHelperRoku('extensão cURL carregada', static function (): void {
    afirmarVerdadeiroHelperRoku(extension_loaded('curl'));
});

registrarTesteHelperRoku('extensão PDO PostgreSQL carregada', static function (): void {
    afirmarVerdadeiroHelperRoku(extension_loaded('pdo_pgsql'));
});

registrarTesteHelperRoku('função cURL disponível', static function (): void {
    afirmarVerdadeiroHelperRoku(function_exists('curl_init'));
});

registrarTesteHelperRoku('classe CurlHandle disponível', static function (): void {
    afirmarVerdadeiroHelperRoku(class_exists('CurlHandle'));
});

registrarTesteHelperRoku('classe PDO disponível', static function (): void {
    afirmarVerdadeiroHelperRoku(class_exists('PDO'));
});

registrarTesteHelperRoku('driver PDO PostgreSQL disponível', static function (): void {
    afirmarVerdadeiroHelperRoku(in_array('pgsql', PDO::getAvailableDrivers(), true));
});

registrarTesteHelperRoku('filtro de faixa global disponível', static function (): void {
    afirmarVerdadeiroHelperRoku(defined('FILTER_FLAG_GLOBAL_RANGE'));
});

registrarTesteHelperRoku('função de lista disponível', static function (): void {
    afirmarVerdadeiroHelperRoku(function_exists('array_is_list'));
});

registrarTesteHelperRoku('função de dígitos disponível', static function (): void {
    afirmarVerdadeiroHelperRoku(function_exists('ctype_digit'));
});

registrarTesteHelperRoku('CIDR IPv4 positivo em loopback', static function (): void {
    afirmarVerdadeiroHelperRoku(ipPertenceCidrRoku('127.0.0.1', '127.0.0.0/8'));
});

registrarTesteHelperRoku('CIDR IPv4 negativo em loopback', static function (): void {
    afirmarFalsoHelperRoku(ipPertenceCidrRoku('8.8.8.8', '127.0.0.0/8'));
});

registrarTesteHelperRoku('CIDR IPv4 positivo em rede privada', static function (): void {
    afirmarVerdadeiroHelperRoku(ipPertenceCidrRoku('10.20.30.40', '10.0.0.0/8'));
});

registrarTesteHelperRoku('CIDR IPv6 positivo em loopback', static function (): void {
    afirmarVerdadeiroHelperRoku(ipPertenceCidrRoku('::1', '::1/128'));
});

registrarTesteHelperRoku('CIDR IPv6 positivo em faixa local', static function (): void {
    afirmarVerdadeiroHelperRoku(ipPertenceCidrRoku('fc00::1', 'fc00::/7'));
});

registrarTesteHelperRoku('CIDR IPv6 negativo em faixa local', static function (): void {
    afirmarFalsoHelperRoku(ipPertenceCidrRoku('2606:4700:4700::1111', 'fc00::/7'));
});

registrarTesteHelperRoku('CIDR inválido', static function (): void {
    afirmarFalsoHelperRoku(ipPertenceCidrRoku('8.8.8.8', 'cidr-invalido'));
});

registrarTesteHelperRoku('CIDR com famílias diferentes', static function (): void {
    afirmarFalsoHelperRoku(ipPertenceCidrRoku('8.8.8.8', 'fc00::/7'));
});

$hostsIpv4Ambiguos = [
    '127.1',
    '127.0.1',
    '2130706433',
    '0177.0.0.1',
    '0x7f.0.0.1',
    '0x7f000001',
];

foreach ($hostsIpv4Ambiguos as $indice => $host) {
    registrarTesteHelperRoku(
        'IPv4 ambíguo bloqueado ' . ($indice + 1),
        static function () use ($host): void {
            afirmarVerdadeiroHelperRoku(hostIpv4NumericoAmbiguoRoku($host));
        }
    );
}

$hostsNaoAmbiguos = [
    '8.8.8.8',
    '1.1.1.1',
    'exemplo.com',
    '123.exemplo.com',
];

foreach ($hostsNaoAmbiguos as $indice => $host) {
    registrarTesteHelperRoku(
        'Host não ambíguo ' . ($indice + 1),
        static function () use ($host): void {
            afirmarFalsoHelperRoku(hostIpv4NumericoAmbiguoRoku($host));
        }
    );
}

$enderecosPublicosPermitidos = [
    '8.8.8.8',
    '1.1.1.1',
    '2606:4700:4700::1111',
];

foreach ($enderecosPublicosPermitidos as $indice => $ip) {
    registrarTesteHelperRoku(
        'Endereço público permitido ' . ($indice + 1),
        static function () use ($ip): void {
            afirmarVerdadeiroHelperRoku(enderecoIpPublicoPermitidoRoku($ip));
        }
    );
}

$enderecosBloqueados = [
    '127.0.0.1',
    '10.0.0.1',
    '192.168.1.1',
    '169.254.1.1',
    '203.0.113.10',
    '::1',
    'fc00::1',
    'fe80::1',
    'fec0::1',
    '2002:7f00:1::',
    '3fff::1',
    '5f00::1',
];

foreach ($enderecosBloqueados as $indice => $ip) {
    registrarTesteHelperRoku(
        'Endereço especial bloqueado ' . ($indice + 1),
        static function () use ($ip): void {
            afirmarFalsoHelperRoku(enderecoIpPublicoPermitidoRoku($ip));
        }
    );
}

registrarTesteHelperRoku('URL literal na raiz', static function (): void {
    $resultado = validarUrlFornecedorRoku('https://8.8.8.8');
    afirmarIgualHelperRoku('https://8.8.8.8/player_api.php', $resultado['endpoint_url']);
});

registrarTesteHelperRoku('URL literal com caminho-base', static function (): void {
    $resultado = validarUrlFornecedorRoku('https://8.8.8.8/base');
    afirmarIgualHelperRoku('https://8.8.8.8/base/player_api.php', $resultado['endpoint_url']);
});

registrarTesteHelperRoku('URL literal sem duplicar endpoint', static function (): void {
    $resultado = validarUrlFornecedorRoku('https://8.8.8.8/base/player_api.php');
    afirmarIgualHelperRoku('https://8.8.8.8/base/player_api.php', $resultado['endpoint_url']);
});

registrarTesteHelperRoku('Metadados da URL literal', static function (): void {
    $resultado = validarUrlFornecedorRoku('https://8.8.8.8');
    afirmarIgualHelperRoku('https', $resultado['scheme']);
    afirmarIgualHelperRoku('8.8.8.8', $resultado['host']);
    afirmarIgualHelperRoku(443, $resultado['port']);
    afirmarIgualHelperRoku('8.8.8.8', $resultado['ip_resolvido']);
    afirmarVerdadeiroHelperRoku($resultado['host_eh_ip_literal']);
});

$urlsInvalidas = [
    'http://127.0.0.1',
    'http://127.1',
    'https://8.8.8.8/base?teste=1',
    'https://usuario:senha@8.8.8.8',
    'https://8.8.8.8/../player_api.php',
    'https://8.8.8.8/%2e%2e/player_api.php',
    'https://8.8.8.8/base%2Foutro',
    'https://8.8.8.8/base%5Coutro',
];

foreach ($urlsInvalidas as $indice => $url) {
    registrarTesteHelperRoku(
        'URL literal inválida bloqueada ' . ($indice + 1),
        static function () use ($url): void {
            afirmarExcecaoHelperRoku(
                static fn (): array => validarUrlFornecedorRoku($url),
                RokuXtreamException::class
            );
        }
    );
}

$casosCategoriaUrl = [
    ['url' => '', 'categoria' => 'URL_REJECTED'],
    ['url' => "https://host.invalid/\x01", 'categoria' => 'URL_REJECTED'],
    ['url' => 'http://[::1', 'categoria' => 'URL_REJECTED'],
    ['url' => 'ftp://host.invalid', 'categoria' => 'URL_SCHEME_REJECTED'],
    ['url' => 'https://user:pass@host.invalid', 'categoria' => 'URL_COMPONENT_REJECTED'],
    ['url' => 'https://host.invalid/base?query=1', 'categoria' => 'URL_COMPONENT_REJECTED'],
    ['url' => 'https://host.invalid/base#fragment', 'categoria' => 'URL_COMPONENT_REJECTED'],
    ['url' => 'https://localhost', 'categoria' => 'URL_REJECTED'],
    ['url' => 'https://host.invalid:70000', 'categoria' => 'URL_REJECTED'],
    ['url' => 'https://host.invalid/base%2Fother', 'categoria' => 'URL_COMPONENT_REJECTED'],
    ['url' => 'http://127.1', 'categoria' => 'URL_REJECTED'],
    ['url' => 'http://127.0.0.1', 'categoria' => 'SSRF_BLOCKED'],
    ['url' => 'https://-host.invalid', 'categoria' => 'URL_REJECTED'],
];

foreach ($casosCategoriaUrl as $indice => $caso) {
    registrarTesteHelperRoku(
        'Categoria sanitizada de URL ' . ($indice + 1),
        static function () use ($caso): void {
            $excecao = afirmarExcecaoHelperRoku(
                static fn (): array => validarUrlFornecedorRoku($caso['url']),
                RokuXtreamException::class
            );
            afirmarIgualHelperRoku(502, $excecao->getStatusHttp());
            afirmarIgualHelperRoku('PROVIDER_UNAVAILABLE', $excecao->getCodigoPublico());
            afirmarIgualHelperRoku(
                'Não foi possível acessar o sistema',
                $excecao->getMensagemPublica()
            );
            afirmarIgualHelperRoku($caso['categoria'], $excecao->getCategoriaInterna());
        }
    );
}

$casosDnsSinteticosComErro = [
    ['registros' => false, 'categoria' => 'DNS_NO_RESULTS'],
    ['registros' => [['type' => 'TXT']], 'categoria' => 'DNS_NO_RESULTS'],
    ['registros' => [['ip' => '127.0.0.1']], 'categoria' => 'DNS_NON_PUBLIC_IP'],
];

foreach ($casosDnsSinteticosComErro as $indice => $caso) {
    registrarTesteHelperRoku(
        'DNS sintético rejeitado ' . ($indice + 1),
        static function () use ($caso): void {
            $excecao = afirmarExcecaoHelperRoku(
                static fn (): array => resolverHostPublicoRoku(
                    'host.invalid',
                    static fn (string $host): mixed => $caso['registros']
                ),
                RokuXtreamException::class
            );
            afirmarIgualHelperRoku($caso['categoria'], $excecao->getCategoriaInterna());
        }
    );
}

registrarTesteHelperRoku('DNS sintético ordena IPv4 antes de IPv6', static function (): void {
    $enderecos = resolverHostPublicoRoku(
        'host.invalid',
        static fn (string $host): array => [
            ['ipv6' => '2606:4700:4700::1111'],
            ['ip' => '8.8.8.8'],
            ['ip' => '1.1.1.1'],
        ]
    );
    afirmarIgualHelperRoku(['1.1.1.1', '8.8.8.8', '2606:4700:4700::1111'], $enderecos);
});

$casosErroCurl = [
    'CURLE_COULDNT_RESOLVE_HOST' => 'CURL_DNS_ERROR',
    'CURLE_COULDNT_CONNECT' => 'CURL_CONNECTION_ERROR',
    'CURLE_SEND_ERROR' => 'CURL_CONNECTION_ERROR',
    'CURLE_RECV_ERROR' => 'CURL_CONNECTION_ERROR',
    'CURLE_GOT_NOTHING' => 'CURL_CONNECTION_ERROR',
    'CURLE_SSL_CONNECT_ERROR' => 'CURL_TLS_ERROR',
    'CURLE_PEER_FAILED_VERIFICATION' => 'CURL_TLS_ERROR',
    'CURLE_SSL_CERTPROBLEM' => 'CURL_TLS_ERROR',
    'CURLE_SSL_CIPHER' => 'CURL_TLS_ERROR',
];

foreach ($casosErroCurl as $nomeConstante => $categoria) {
    if (!defined($nomeConstante)) {
        continue;
    }

    registrarTesteHelperRoku(
        'Classificação cURL ' . $nomeConstante,
        static function () use ($nomeConstante, $categoria): void {
            afirmarIgualHelperRoku(
                $categoria,
                classificarErroCurlXtreamRoku((int) constant($nomeConstante))
            );
        }
    );
}

registrarTesteHelperRoku('Classificação cURL residual', static function (): void {
    afirmarIgualHelperRoku('CURL_OTHER_ERROR', classificarErroCurlXtreamRoku(PHP_INT_MAX));
});

$casosStatusHttp = [
    199 => 'PROVIDER_HTTP_NON_2XX',
    200 => null,
    299 => null,
    300 => 'PROVIDER_HTTP_NON_2XX',
    401 => 'PROVIDER_HTTP_NON_2XX',
    500 => 'PROVIDER_HTTP_NON_2XX',
];

foreach ($casosStatusHttp as $status => $categoria) {
    registrarTesteHelperRoku(
        'Classificação HTTP sintética ' . $status,
        static function () use ($status, $categoria): void {
            afirmarIgualHelperRoku(
                $categoria,
                classificarStatusHttpFornecedorRoku($status)
            );
        }
    );
}

$casosIdCategoria = [
    ['esperado' => '0', 'valor' => 0],
    ['esperado' => '123', 'valor' => 123],
    ['esperado' => '001', 'valor' => '001'],
    ['esperado' => '45', 'valor' => ' 45 '],
    ['esperado' => null, 'valor' => -1],
    ['esperado' => null, 'valor' => ''],
    ['esperado' => null, 'valor' => '12a'],
    ['esperado' => null, 'valor' => 12.5],
    ['esperado' => null, 'valor' => true],
    ['esperado' => null, 'valor' => null],
    ['esperado' => null, 'valor' => str_repeat('1', 33)],
];

foreach ($casosIdCategoria as $indice => $caso) {
    registrarTesteHelperRoku(
        'Normalização de ID ' . ($indice + 1),
        static function () use ($caso): void {
            afirmarIgualHelperRoku(
                $caso['esperado'],
                normalizarIdCategoriaXtreamRoku($caso['valor'])
            );
        }
    );
}

$casosNomeCategoria = [
    ['esperado' => 'Filmes', 'valor' => ' Filmes '],
    ['esperado' => 'Ação e Aventura', 'valor' => 'Ação e Aventura'],
    ['esperado' => "Filmes\tAção", 'valor' => "Filmes\tAção"],
    ['esperado' => null, 'valor' => "Filmes\nAção"],
    ['esperado' => null, 'valor' => "Filmes\rAção"],
    ['esperado' => null, 'valor' => "Filmes\0Ação"],
    ['esperado' => null, 'valor' => "\xC3\x28"],
    ['esperado' => null, 'valor' => " \t "],
    ['esperado' => str_repeat('A', 500), 'valor' => str_repeat('A', 500)],
    ['esperado' => null, 'valor' => str_repeat('A', 501)],
    ['esperado' => '<b>Filmes</b>', 'valor' => '<b>Filmes</b>'],
];

foreach ($casosNomeCategoria as $indice => $caso) {
    registrarTesteHelperRoku(
        'Normalização de nome ' . ($indice + 1),
        static function () use ($caso): void {
            afirmarIgualHelperRoku(
                $caso['esperado'],
                normalizarNomeCategoriaXtreamRoku($caso['valor'])
            );
        }
    );
}

$casosParentId = [
    ['esperado' => '10', 'valor' => '10'],
    ['esperado' => '0', 'valor' => 0],
    ['esperado' => null, 'valor' => 'inválido'],
    ['esperado' => null, 'valor' => null],
];

foreach ($casosParentId as $indice => $caso) {
    registrarTesteHelperRoku(
        'Normalização de parent_id ' . ($indice + 1),
        static function () use ($caso): void {
            afirmarIgualHelperRoku(
                $caso['esperado'],
                normalizarParentIdCategoriaXtreamRoku($caso['valor'])
            );
        }
    );
}

registrarTesteHelperRoku('Estrutura pública da exceção Xtream', static function (): void {
    $excecao = new RokuXtreamException(
        502,
        'CODIGO_FICTICIO',
        'Mensagem fictícia',
        'INVALID_RESPONSE'
    );

    afirmarIgualHelperRoku(502, $excecao->getStatusHttp());
    afirmarIgualHelperRoku('CODIGO_FICTICIO', $excecao->getCodigoPublico());
    afirmarIgualHelperRoku('Mensagem fictícia', $excecao->getMensagemPublica());
    afirmarIgualHelperRoku('INVALID_RESPONSE', $excecao->getCategoriaInterna());
});

registrarTesteHelperRoku('Categoria interna inválida na exceção', static function (): void {
    afirmarExcecaoHelperRoku(
        static fn (): RokuXtreamException => new RokuXtreamException(
            502,
            'CODIGO_FICTICIO',
            'Mensagem fictícia',
            'CATEGORIA_NAO_PERMITIDA'
        ),
        InvalidArgumentException::class
    );
});

echo PHP_EOL;
echo 'Resumo: ', $aprovadosHelperRoku, ' aprovados, ', $falhasHelperRoku, ' falhas', PHP_EOL;

exit($falhasHelperRoku === 0 ? 0 : 1);
