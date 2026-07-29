<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/roku_audio_fallback_idempotency.php';

// PHP_8_2_VECTOR_EXECUTION_PENDING:
// executar em PHP 8.2; commits de endpoints permanecem bloqueados ate PASS.
// A ausencia de runtime local nao valida esta execucao.

function roku_audio_fallback_test_exigir(bool $condicao): void
{
    if (!$condicao) {
        throw new RuntimeException('ROKU_AUDIO_FALLBACK_TEST_ASSERTION_FAILED');
    }
}

function roku_audio_fallback_test_exigir_erro(
    callable $operacao,
    string $codigoEsperado,
    ?string $valorProibido = null
): void {
    try {
        $operacao();
    } catch (RokuAudioFallbackIdempotencyException $erro) {
        roku_audio_fallback_test_exigir($erro->getCodigoPublico() === $codigoEsperado);
        roku_audio_fallback_test_exigir($erro->getMessage() === $codigoEsperado);

        if ($valorProibido !== null && $valorProibido !== '') {
            roku_audio_fallback_test_exigir(
                !str_contains($erro->getMessage(), $valorProibido)
            );
        }

        return;
    }

    throw new RuntimeException('ROKU_AUDIO_FALLBACK_TEST_EXPECTED_ERROR');
}

try {
    $segredoTeste = 'TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES';
    $vetores = [
        [
            'cliente_id' => 101,
            'sistema_id' => 202,
            'stream_id' => 'movie-alpha-001',
            'extension_input' => 'mp4',
            'extension' => 'mp4',
            'request_id' => 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8',
            'canonical' => "TOPMASTER_ROKU_AUDIO_FALLBACK\n"
                . "v1\n101\n202\nmovie-alpha-001\nmp4\n"
                . "AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8\n"
                . 'vod_audio_stereo',
            'internal_session_id' =>
                'raf_DNPjGP_HXj2RqWkDe0eKFDAKR5O40281CMWwfuZ4WeY',
            'public_token' => 'ku6IyXAocc72nwN_q_SqKzaHoKh0kddbjbxJpso5PMA',
            'public_token_hash' =>
                '762c6db79a9ef04613ce60d396e426c4eac1ab006bf3103880982b081411122f',
        ],
        [
            'cliente_id' => 303,
            'sistema_id' => 404,
            'stream_id' => 'vod_Beta-987654321',
            'extension_input' => 'mkv',
            'extension' => 'mkv',
            'request_id' => 'ICEiIyQlJicoKSorLC0uLzAxMjM0NTY3ODk6Ozw9Pj8',
            'canonical' => "TOPMASTER_ROKU_AUDIO_FALLBACK\n"
                . "v1\n303\n404\nvod_Beta-987654321\nmkv\n"
                . "ICEiIyQlJicoKSorLC0uLzAxMjM0NTY3ODk6Ozw9Pj8\n"
                . 'vod_audio_stereo',
            'internal_session_id' =>
                'raf_B3PdnsCw0plQKkrMBsjwfkyBPPge_39R2uNMgH5uhSI',
            'public_token' => '48Eb-p95dIgtMsVE63S9L4ehTp8Bwd2FYYgF_G6WdAQ',
            'public_token_hash' =>
                '9c5f2e5e046a802cd8fa8a869ab3b19fa7a06f864012b5541921161b0d68c3da',
        ],
    ];

    foreach ($vetores as $vetor) {
        $resultado = RokuAudioFallbackIdempotency::derivar(
            $vetor['cliente_id'],
            $vetor['sistema_id'],
            $vetor['stream_id'],
            $vetor['extension_input'],
            $vetor['request_id'],
            $segredoTeste
        );
        $repeticao = RokuAudioFallbackIdempotency::derivar(
            $vetor['cliente_id'],
            $vetor['sistema_id'],
            $vetor['stream_id'],
            $vetor['extension_input'],
            $vetor['request_id'],
            $segredoTeste
        );

        roku_audio_fallback_test_exigir(
            array_keys($resultado) === [
                'canonical',
                'internal_session_id',
                'public_token',
                'public_token_hash',
                'extension',
            ]
        );
        roku_audio_fallback_test_exigir($resultado['canonical'] === $vetor['canonical']);
        roku_audio_fallback_test_exigir($resultado['extension'] === $vetor['extension']);
        roku_audio_fallback_test_exigir(
            $resultado['internal_session_id'] === $vetor['internal_session_id']
        );
        roku_audio_fallback_test_exigir(
            $resultado['public_token'] === $vetor['public_token']
        );
        roku_audio_fallback_test_exigir(
            $resultado['public_token_hash'] === $vetor['public_token_hash']
        );
        roku_audio_fallback_test_exigir($resultado === $repeticao);
        roku_audio_fallback_test_exigir(
            strlen($resultado['internal_session_id']) === 47
        );
        roku_audio_fallback_test_exigir(strlen($resultado['public_token']) === 43);
        roku_audio_fallback_test_exigir(strlen($resultado['public_token_hash']) === 64);
        roku_audio_fallback_test_exigir(
            preg_match(
                '/\Araf_[A-Za-z0-9_-]{43}\z/D',
                $resultado['internal_session_id']
            ) === 1
        );
        roku_audio_fallback_test_exigir(
            preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $resultado['public_token']) === 1
        );
        roku_audio_fallback_test_exigir(
            preg_match('/\A[0-9a-f]{64}\z/D', $resultado['public_token_hash']) === 1
        );
        roku_audio_fallback_test_exigir(
            !str_ends_with($resultado['canonical'], "\n")
        );
        roku_audio_fallback_test_exigir(
            substr_count($resultado['canonical'], "\n") === 7
        );
        roku_audio_fallback_test_exigir(
            RokuAudioFallbackIdempotency::compararPublicTokenHash(
                $resultado['public_token_hash'],
                $vetor['public_token_hash']
            )
        );
    }

    $requestIdValido = $vetores[0]['request_id'];
    $argumentosValidos = [101, 202, 'movie-alpha-001', 'mp4', $requestIdValido];

    $casosRequestId = [
        ['', ''],
        [str_repeat('A', 42), str_repeat('A', 42)],
        [str_repeat('A', 44), str_repeat('A', 44)],
        [substr($requestIdValido, 0, 42) . '=', '='],
        [substr($requestIdValido, 0, 42) . '*', '*'],
        [substr($requestIdValido, 0, 41) . 'aa', 'aa'],
        [substr($requestIdValido, 0, 42) . '9', '9'],
    ];

    foreach ($casosRequestId as [$requestIdInvalido, $sentinela]) {
        roku_audio_fallback_test_exigir_erro(
            static fn() => RokuAudioFallbackIdempotency::validarRequestId(
                $requestIdInvalido
            ),
            'ROKU_AUDIO_FALLBACK_INVALID_REQUEST_ID',
            $sentinela
        );
    }

    roku_audio_fallback_test_exigir_erro(
        static fn() => RokuAudioFallbackIdempotency::validarRequestId(
            str_repeat('A', 42) . "\u{00E9}"
        ),
        'ROKU_AUDIO_FALLBACK_INVALID_REQUEST_ID'
    );
    roku_audio_fallback_test_exigir_erro(
        static fn() => RokuAudioFallbackIdempotency::validarRequestId(123),
        'ROKU_AUDIO_FALLBACK_INVALID_REQUEST_ID'
    );

    foreach ([0, -1, 2147483648, 1.0, '1'] as $clienteInvalido) {
        roku_audio_fallback_test_exigir_erro(
            static fn() => RokuAudioFallbackIdempotency::canonicalizar(
                $clienteInvalido,
                $argumentosValidos[1],
                $argumentosValidos[2],
                $argumentosValidos[3],
                $argumentosValidos[4]
            ),
            'ROKU_AUDIO_FALLBACK_INVALID_CLIENT_ID'
        );
    }

    foreach ([0, -1, 2147483648, 1.0, '1'] as $sistemaInvalido) {
        roku_audio_fallback_test_exigir_erro(
            static fn() => RokuAudioFallbackIdempotency::canonicalizar(
                $argumentosValidos[0],
                $sistemaInvalido,
                $argumentosValidos[2],
                $argumentosValidos[3],
                $argumentosValidos[4]
            ),
            'ROKU_AUDIO_FALLBACK_INVALID_SYSTEM_ID'
        );
    }

    $streamsInvalidos = [
        '',
        str_repeat('s', 513),
        "stream\0id",
        "stream\rid",
        "stream\nid",
        'https://synthetic.invalid/media',
        '//synthetic.invalid/media',
        " \t ",
    ];

    foreach ($streamsInvalidos as $streamInvalido) {
        roku_audio_fallback_test_exigir_erro(
            static fn() => RokuAudioFallbackIdempotency::canonicalizar(
                $argumentosValidos[0],
                $argumentosValidos[1],
                $streamInvalido,
                $argumentosValidos[3],
                $argumentosValidos[4]
            ),
            'ROKU_AUDIO_FALLBACK_INVALID_STREAM_ID',
            $streamInvalido
        );
    }

    $extensoesInvalidas = [
        '',
        'm3u8',
        'avi',
        '.mp4',
        'path/mp4',
        'mp4?x',
        ' mp4',
        "mp4\n",
    ];

    foreach ($extensoesInvalidas as $extensaoInvalida) {
        roku_audio_fallback_test_exigir_erro(
            static fn() => RokuAudioFallbackIdempotency::normalizarExtensao(
                $extensaoInvalida
            ),
            'ROKU_AUDIO_FALLBACK_INVALID_EXTENSION',
            $extensaoInvalida
        );
    }

    roku_audio_fallback_test_exigir(
        RokuAudioFallbackIdempotency::normalizarExtensao('MP4') === 'mp4'
    );

    foreach (['', str_repeat('s', 31), 123] as $segredoInvalido) {
        roku_audio_fallback_test_exigir_erro(
            static fn() => RokuAudioFallbackIdempotency::derivar(
                $argumentosValidos[0],
                $argumentosValidos[1],
                $argumentosValidos[2],
                $argumentosValidos[3],
                $argumentosValidos[4],
                $segredoInvalido
            ),
            'ROKU_AUDIO_FALLBACK_INVALID_DERIVATION_SECRET'
        );
    }

    foreach (
        [
            ['', $vetores[0]['public_token_hash']],
            [str_repeat('a', 64), strtoupper(str_repeat('a', 64))],
            [str_repeat('a', 63), str_repeat('a', 64)],
        ] as [$hashDerivadoInvalido, $hashPersistidoInvalido]
    ) {
        roku_audio_fallback_test_exigir_erro(
            static fn() => RokuAudioFallbackIdempotency::compararPublicTokenHash(
                $hashDerivadoInvalido,
                $hashPersistidoInvalido
            ),
            'ROKU_AUDIO_FALLBACK_INVALID_ARGUMENT'
        );
    }

    roku_audio_fallback_test_exigir(
        !RokuAudioFallbackIdempotency::compararPublicTokenHash(
            $vetores[0]['public_token_hash'],
            str_repeat('a', 64)
        )
    );

    $base = RokuAudioFallbackIdempotency::derivar(
        101,
        202,
        'movie-alpha-001',
        'mp4',
        $vetores[0]['request_id'],
        $segredoTeste
    );
    $variacoes = [
        [102, 202, 'movie-alpha-001', 'mp4', $vetores[0]['request_id'], $segredoTeste],
        [101, 203, 'movie-alpha-001', 'mp4', $vetores[0]['request_id'], $segredoTeste],
        [101, 202, 'movie-alpha-002', 'mp4', $vetores[0]['request_id'], $segredoTeste],
        [101, 202, 'movie-alpha-001', 'mov', $vetores[0]['request_id'], $segredoTeste],
        [101, 202, 'movie-alpha-001', 'mp4', $vetores[1]['request_id'], $segredoTeste],
        [101, 202, 'movie-alpha-001', 'mp4', $vetores[0]['request_id'], $segredoTeste . 'x'],
    ];

    foreach ($variacoes as $variacao) {
        $alterado = RokuAudioFallbackIdempotency::derivar(...$variacao);
        roku_audio_fallback_test_exigir(
            $alterado['internal_session_id'] !== $base['internal_session_id']
        );
        roku_audio_fallback_test_exigir(
            $alterado['public_token'] !== $base['public_token']
        );
        roku_audio_fallback_test_exigir(
            $alterado['public_token_hash'] !== $base['public_token_hash']
        );
    }

    roku_audio_fallback_test_exigir(
        $base['internal_session_id'] !== $base['public_token']
    );

    foreach (['movie-alpha-001', '101', '202', 'mp4'] as $valorCanonico) {
        roku_audio_fallback_test_exigir(
            !str_contains($base['internal_session_id'], $valorCanonico)
        );
        roku_audio_fallback_test_exigir(
            !str_contains($base['public_token'], $valorCanonico)
        );
        roku_audio_fallback_test_exigir(
            !str_contains($base['public_token_hash'], $valorCanonico)
        );
    }

    echo "ROKU_AUDIO_FALLBACK_IDEMPOTENCY_TEST_PASS\n";
    exit(0);
} catch (Throwable) {
    echo "ROKU_AUDIO_FALLBACK_IDEMPOTENCY_TEST_FAIL\n";
    exit(1);
}
