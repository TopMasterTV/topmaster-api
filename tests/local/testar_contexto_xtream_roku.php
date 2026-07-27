<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/roku_obter_contexto_xtream.php';

$testes = [];
$aprovados = 0;
$falhas = 0;

function registrarTesteContextoXtreamRoku(
    string $nome,
    callable $teste,
    string $tipo = 'comportamental'
): void {
    global $testes;
    $testes[] = ['nome' => $nome, 'teste' => $teste, 'tipo' => $tipo];
}

function afirmarContextoXtreamRoku(bool $condicao): void
{
    if ($condicao !== true) {
        throw new RuntimeException('Falha de teste sanitizada');
    }
}

function afirmarIgualContextoXtreamRoku(mixed $esperado, mixed $atual): void
{
    afirmarContextoXtreamRoku($esperado === $atual);
}

function capturarExcecaoContextoXtreamRoku(
    callable $acao,
    string $classeEsperada
): Throwable {
    try {
        $acao();
    } catch (Throwable $e) {
        afirmarIgualContextoXtreamRoku($classeEsperada, $e::class);
        return $e;
    }

    throw new RuntimeException('Falha de teste sanitizada');
}

function contextoXtreamSintetico(): array
{
    return [
        'sistema_id' => 123,
        'cliente_id' => 45,
        'tipo_acesso' => 'xtream',
        'fornecedor_url' => 'https://provider.invalid',
        'usuario' => 'synthetic-user',
        'senha' => 'synthetic-password',
        'nome' => 'Sistema sintético',
        'm3u_url' => null,
        'status' => 'Active',
        'exp_date' => null,
        'vencimento' => null,
    ];
}

registrarTesteContextoXtreamRoku('método GET aceito', static function (): void {
    validarMetodoContextoXtreamRoku('GET');
    afirmarContextoXtreamRoku(true);
});

foreach (['POST', 'PUT', 'PATCH', 'DELETE', ''] as $metodo) {
    registrarTesteContextoXtreamRoku(
        'método rejeitado ' . ($metodo !== '' ? $metodo : 'vazio'),
        static function () use ($metodo): void {
            $e = capturarExcecaoContextoXtreamRoku(
                static fn () => validarMetodoContextoXtreamRoku($metodo),
                RokuContextoXtreamEndpointException::class
            );
            afirmarIgualContextoXtreamRoku(405, $e->getStatusHttp());
            afirmarIgualContextoXtreamRoku('METHOD_NOT_ALLOWED', $e->getCodigoPublico());
        }
    );
}

$consultasInvalidas = [
    [],
    ['sistema_id' => ''],
    ['sistema_id' => '0'],
    ['sistema_id' => '-1'],
    ['sistema_id' => '1.5'],
    ['sistema_id' => '123abc'],
    ['sistema_id' => '01'],
    ['sistema_id' => 123],
    ['sistema_id' => ['123']],
    ['sistema_id' => '123', 'cliente_id' => '45'],
    ['sistema_id' => '123', 'extra' => 'synthetic'],
    ['cliente_id' => '45'],
    ['sistema_id' => str_repeat('9', 19)],
];

foreach ($consultasInvalidas as $indice => $consulta) {
    registrarTesteContextoXtreamRoku(
        'sistema_id inválido ' . ($indice + 1),
        static function () use ($consulta): void {
            $e = capturarExcecaoContextoXtreamRoku(
                static fn () => extrairSistemaIdContextoXtreamRoku($consulta),
                RokuContextoXtreamEndpointException::class
            );
            afirmarIgualContextoXtreamRoku(400, $e->getStatusHttp());
            afirmarIgualContextoXtreamRoku('INVALID_REQUEST', $e->getCodigoPublico());
        }
    );
}

registrarTesteContextoXtreamRoku('sistema_id válido', static function (): void {
    afirmarIgualContextoXtreamRoku(
        123,
        extrairSistemaIdContextoXtreamRoku(['sistema_id' => '123'])
    );
});

registrarTesteContextoXtreamRoku(
    'cliente_id vem exclusivamente da autenticação',
    static function (): void {
        $ids = obterIdentificadoresContextoXtreamRoku(
            ['cliente_id' => 45],
            123
        );
        afirmarIgualContextoXtreamRoku(
            ['cliente_id' => 45, 'sistema_id' => 123],
            $ids
        );
    }
);

registrarTesteContextoXtreamRoku(
    'orquestração autentica antes de consultar',
    static function (): void {
        $ordem = [];
        $resposta = orquestrarContextoXtreamRoku(
            'GET',
            ['sistema_id' => '123'],
            static function () use (&$ordem): array {
                $ordem[] = 'autenticar';
                return ['cliente_id' => 45];
            },
            static function (int $clienteId, int $sistemaId) use (&$ordem): array {
                $ordem[] = 'consultar';
                afirmarIgualContextoXtreamRoku(45, $clienteId);
                afirmarIgualContextoXtreamRoku(123, $sistemaId);
                return contextoXtreamSintetico();
            }
        );
        afirmarIgualContextoXtreamRoku(['autenticar', 'consultar'], $ordem);
        afirmarIgualContextoXtreamRoku(true, $resposta['sucesso']);
    }
);

registrarTesteContextoXtreamRoku(
    'falha de autenticação impede consulta',
    static function (): void {
        $consultaExecutada = false;
        $e = capturarExcecaoContextoXtreamRoku(
            static fn () => orquestrarContextoXtreamRoku(
                'GET',
                ['sistema_id' => '123'],
                static function (): array {
                    throw new RokuAuthException(
                        401,
                        'INVALID_TOKEN',
                        'Sessão inválida'
                    );
                },
                static function () use (&$consultaExecutada): array {
                    $consultaExecutada = true;
                    return contextoXtreamSintetico();
                }
            ),
            RokuAuthException::class
        );
        afirmarIgualContextoXtreamRoku(401, $e->getStatusHttp());
        afirmarContextoXtreamRoku(!$consultaExecutada);
    }
);

registrarTesteContextoXtreamRoku(
    'consulta recebe IDs autenticado e validado simultaneamente',
    static function (): void {
        $argumentos = [];
        orquestrarContextoXtreamRoku(
            'GET',
            ['sistema_id' => '123'],
            static fn (): array => ['cliente_id' => 45],
            static function (int $clienteId, int $sistemaId) use (&$argumentos): array {
                $argumentos = [$clienteId, $sistemaId];
                return contextoXtreamSintetico();
            }
        );
        afirmarIgualContextoXtreamRoku([45, 123], $argumentos);
    }
);

registrarTesteContextoXtreamRoku(
    'cliente_id da requisição nunca chega à autenticação',
    static function (): void {
        $autenticacaoExecutada = false;
        capturarExcecaoContextoXtreamRoku(
            static fn () => orquestrarContextoXtreamRoku(
                'GET',
                ['sistema_id' => '123', 'cliente_id' => '99'],
                static function () use (&$autenticacaoExecutada): array {
                    $autenticacaoExecutada = true;
                    return ['cliente_id' => 45];
                },
                static fn (): array => contextoXtreamSintetico()
            ),
            RokuContextoXtreamEndpointException::class
        );
        afirmarContextoXtreamRoku(!$autenticacaoExecutada);
    }
);

registrarTesteContextoXtreamRoku(
    'sistema não encontrado preserva 404',
    static function (): void {
        $e = capturarExcecaoContextoXtreamRoku(
            static fn () => orquestrarContextoXtreamRoku(
                'GET',
                ['sistema_id' => '123'],
                static fn (): array => ['cliente_id' => 45],
                static function (): array {
                    throw new RokuSistemaException(
                        404,
                        'SYSTEM_NOT_FOUND',
                        'Sistema não encontrado'
                    );
                }
            ),
            RokuSistemaException::class
        );
        afirmarIgualContextoXtreamRoku(404, $e->getStatusHttp());
        afirmarIgualContextoXtreamRoku('SYSTEM_NOT_FOUND', $e->getCodigoPublico());
    }
);

registrarTesteContextoXtreamRoku(
    'sistema de outro cliente não encontrado',
    static function (): void {
        $contexto = contextoXtreamSintetico();
        $e = capturarExcecaoContextoXtreamRoku(
            static fn () => projetarContextoXtreamRoku($contexto, 99, 123),
            RokuSistemaException::class
        );
        afirmarIgualContextoXtreamRoku(404, $e->getStatusHttp());
        afirmarIgualContextoXtreamRoku('SYSTEM_NOT_FOUND', $e->getCodigoPublico());
    }
);

registrarTesteContextoXtreamRoku(
    'sistema divergente não encontrado',
    static function (): void {
        $contexto = contextoXtreamSintetico();
        $e = capturarExcecaoContextoXtreamRoku(
            static fn () => projetarContextoXtreamRoku($contexto, 45, 999),
            RokuSistemaException::class
        );
        afirmarIgualContextoXtreamRoku(404, $e->getStatusHttp());
    }
);

registrarTesteContextoXtreamRoku('tipo não Xtream rejeitado', static function (): void {
    $contexto = contextoXtreamSintetico();
    $contexto['tipo_acesso'] = 'm3u';
    $e = capturarExcecaoContextoXtreamRoku(
        static fn () => projetarContextoXtreamRoku($contexto, 45, 123),
        RokuContextoXtreamEndpointException::class
    );
    afirmarIgualContextoXtreamRoku(409, $e->getStatusHttp());
    afirmarIgualContextoXtreamRoku('SYSTEM_ACCESS_UNAVAILABLE', $e->getCodigoPublico());
});

foreach (
    [
        ['campo' => 'fornecedor_url', 'valor' => null, 'caso' => 'ausente'],
        ['campo' => 'fornecedor_url', 'valor' => '   ', 'caso' => 'vazio após trim'],
        ['campo' => 'usuario', 'valor' => null, 'caso' => 'ausente'],
        ['campo' => 'usuario', 'valor' => '   ', 'caso' => 'vazio após trim'],
        ['campo' => 'senha', 'valor' => null, 'caso' => 'ausente'],
        ['campo' => 'senha', 'valor' => '', 'caso' => 'vazia'],
        ['campo' => 'senha', 'valor' => '   ', 'caso' => 'vazia após trim'],
    ] as $casoIncompleto
) {
    registrarTesteContextoXtreamRoku(
        'contexto incompleto '
            . $casoIncompleto['campo']
            . ' '
            . $casoIncompleto['caso'],
        static function () use ($casoIncompleto): void {
            $contexto = contextoXtreamSintetico();
            $contexto[$casoIncompleto['campo']] = $casoIncompleto['valor'];
            $e = capturarExcecaoContextoXtreamRoku(
                static fn () => projetarContextoXtreamRoku($contexto, 45, 123),
                RokuContextoXtreamEndpointException::class
            );
            afirmarIgualContextoXtreamRoku(409, $e->getStatusHttp());
            afirmarIgualContextoXtreamRoku(
                'SYSTEM_ACCESS_UNAVAILABLE',
                $e->getCodigoPublico()
            );
        }
    );
}

registrarTesteContextoXtreamRoku(
    'projeção contém somente cinco campos',
    static function (): void {
        $projecao = projetarContextoXtreamRoku(
            contextoXtreamSintetico(),
            45,
            123
        );
        afirmarIgualContextoXtreamRoku(
            ['sistema_id', 'tipo_acesso', 'base_url', 'username', 'password'],
            array_keys($projecao)
        );
        afirmarIgualContextoXtreamRoku('xtream', $projecao['tipo_acesso']);
        afirmarContextoXtreamRoku(!array_key_exists('cliente_id', $projecao));
        afirmarContextoXtreamRoku(!array_key_exists('m3u_url', $projecao));
    }
);

registrarTesteContextoXtreamRoku(
    'orquestração projeta envelope e cinco campos',
    static function (): void {
        $resposta = orquestrarContextoXtreamRoku(
            'GET',
            ['sistema_id' => '123'],
            static fn (): array => ['cliente_id' => 45, 'token_id' => 999],
            static fn (): array => contextoXtreamSintetico()
        );
        afirmarIgualContextoXtreamRoku(['sucesso', 'data'], array_keys($resposta));
        afirmarIgualContextoXtreamRoku(
            ['sistema_id', 'tipo_acesso', 'base_url', 'username', 'password'],
            array_keys($resposta['data'])
        );
        afirmarContextoXtreamRoku(!array_key_exists('cliente_id', $resposta['data']));
        afirmarContextoXtreamRoku(!array_key_exists('token_id', $resposta['data']));
        afirmarContextoXtreamRoku(!array_key_exists('m3u_url', $resposta['data']));
        afirmarContextoXtreamRoku(!array_key_exists('nome', $resposta['data']));
    }
);

registrarTesteContextoXtreamRoku('cabeçalhos privados', static function (): void {
    afirmarIgualContextoXtreamRoku(
        [
            'Content-Type: application/json; charset=utf-8',
            'Cache-Control: no-store, max-age=0',
            'Pragma: no-cache',
            'X-Content-Type-Options: nosniff',
        ],
        obterCabecalhosContextoXtreamRoku()
    );
});

registrarTesteContextoXtreamRoku(
    'mensagens de erro não contêm sentinelas',
    static function (): void {
        $sentinelas = [
            'provider.invalid',
            'synthetic-user',
            'synthetic-password',
            'Bearer',
            'Authorization',
        ];
        $contexto = contextoXtreamSintetico();
        $contexto['tipo_acesso'] = 'm3u';
        $e = capturarExcecaoContextoXtreamRoku(
            static fn () => projetarContextoXtreamRoku($contexto, 45, 123),
            RokuContextoXtreamEndpointException::class
        );

        foreach ($sentinelas as $sentinela) {
            afirmarContextoXtreamRoku(
                !str_contains($e->getMensagemPublica(), $sentinela)
            );
        }
    }
);

$conteudoEndpoint = (string) file_get_contents(
    dirname(__DIR__, 2) . '/roku_obter_contexto_xtream.php'
);

registrarTesteContextoXtreamRoku(
    'endpoint reutiliza autenticação e isolamento',
    static function () use ($conteudoEndpoint): void {
        afirmarContextoXtreamRoku(
            str_contains($conteudoEndpoint, 'autenticarTokenRoku($pdo)')
            && str_contains($conteudoEndpoint, 'obterContextoSistemaRoku(')
            && str_contains($conteudoEndpoint, "\$identificadores['cliente_id']")
            && str_contains($conteudoEndpoint, "\$identificadores['sistema_id']")
        );
    },
    'textual'
);

registrarTesteContextoXtreamRoku(
    'endpoint não acessa fornecedor nem DNS',
    static function () use ($conteudoEndpoint): void {
        foreach (
            [
                'requisitarJsonXtreamRoku',
                'obterCategoriasXtreamRoku',
                'curl_exec',
                'dns_get_record',
                'gethostbyname',
            ] as $referencia
        ) {
            afirmarContextoXtreamRoku(!str_contains($conteudoEndpoint, $referencia));
        }
    },
    'textual'
);

registrarTesteContextoXtreamRoku(
    'endpoint não registra credenciais',
    static function () use ($conteudoEndpoint): void {
        foreach (['error_log', 'print_r', 'var_dump', 'trigger_error'] as $referencia) {
            afirmarContextoXtreamRoku(!str_contains($conteudoEndpoint, $referencia));
        }
    },
    'textual'
);

$comportamentais = 0;
$textuais = 0;

foreach ($testes as $teste) {
    try {
        $teste['teste']();
        $aprovados++;
        if ($teste['tipo'] === 'textual') {
            $textuais++;
        } else {
            $comportamentais++;
        }
        echo 'PASS: ' . $teste['nome'] . PHP_EOL;
    } catch (Throwable) {
        $falhas++;
        echo 'FAIL: ' . $teste['nome'] . PHP_EOL;
    }
}

echo "Resumo: {$aprovados} aprovados, {$falhas} falhas" . PHP_EOL;
echo "Tipos: {$comportamentais} comportamentais, {$textuais} textuais" . PHP_EOL;
exit($falhas === 0 ? 0 : 1);
