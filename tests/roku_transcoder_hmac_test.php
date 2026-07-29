<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/roku_transcoder_hmac.php';

function roku_transcoder_hmac_test_require(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('TEST_FAILURE');
    }
}

function roku_transcoder_hmac_test_error(callable $operation, string $expectedCode): void
{
    try {
        $operation();
    } catch (RokuTranscoderHmacException $exception) {
        roku_transcoder_hmac_test_require($exception->getMessage() === $expectedCode);
        return;
    } catch (Throwable) {
        throw new RuntimeException('TEST_FAILURE');
    }

    throw new RuntimeException('TEST_FAILURE');
}

try {
    $secret = 'TEST_ONLY_DO_NOT_USE__TRANSCODER_HMAC_SECRET_32_BYTES';
    $vectors = [
        [
            'method' => 'post',
            'path' => '/internal/sessions',
            'timestamp' => 1700000001,
            'nonce' => 'nonce_TEST_ONLY_00000000000000000001',
            'body' => '{"kind":"synthetic_create","attempt":1}',
            'body_hash' => 'ba3c6858f673d9fd38f6fefbbe3376258b923e4f444d5228f79d242bc4ce8eca',
            'canonical' => "POST\n/internal/sessions\n1700000001\nnonce_TEST_ONLY_00000000000000000001\nba3c6858f673d9fd38f6fefbbe3376258b923e4f444d5228f79d242bc4ce8eca",
            'signature' => 'e6a1126922b9935925391149c2296994f69cdb3a573d835853d7c403af0b3801',
        ],
        [
            'method' => 'GET',
            'path' => '/internal/sessions/synthetic_session_A1/status',
            'timestamp' => 1700000002,
            'nonce' => 'nonce_TEST_ONLY_00000000000000000002',
            'body' => '',
            'body_hash' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'canonical' => "GET\n/internal/sessions/synthetic_session_A1/status\n1700000002\nnonce_TEST_ONLY_00000000000000000002\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
            'signature' => 'ce917a340015551d3d7e5cf8a1a74f022195cb73e6b7957e52529cd6907fe258',
        ],
        [
            'method' => 'DELETE',
            'path' => '/internal/sessions/synthetic_session_B2',
            'timestamp' => 1700000003,
            'nonce' => 'nonce_TEST_ONLY_00000000000000000003',
            'body' => '',
            'body_hash' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'canonical' => "DELETE\n/internal/sessions/synthetic_session_B2\n1700000003\nnonce_TEST_ONLY_00000000000000000003\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
            'signature' => 'cca9e12548bc3b752c5fbe57ee4a17f56b65bb0a64b6c53b41e243bbe9662b26',
        ],
    ];

    foreach ($vectors as $vector) {
        $result = RokuTranscoderHmac::sign(
            $vector['method'],
            $vector['path'],
            $vector['timestamp'],
            $vector['nonce'],
            $vector['body'],
            $secret
        );
        $canonical = RokuTranscoderHmac::canonicalize(
            $vector['method'],
            $vector['path'],
            $vector['timestamp'],
            $vector['nonce'],
            $vector['body']
        );

        roku_transcoder_hmac_test_require($result['body_hash'] === $vector['body_hash']);
        roku_transcoder_hmac_test_require($canonical === $vector['canonical']);
        roku_transcoder_hmac_test_require(substr_count($canonical, "\n") === 4);
        roku_transcoder_hmac_test_require(!str_ends_with($canonical, "\n"));
        roku_transcoder_hmac_test_require($result['signature'] === $vector['signature']);
        roku_transcoder_hmac_test_require(preg_match('/\A[0-9a-f]{64}\z/', $result['signature']) === 1);
        roku_transcoder_hmac_test_require($result['headers'] === [
            'X-TopMaster-Timestamp' => (string) $vector['timestamp'],
            'X-TopMaster-Nonce' => $vector['nonce'],
            'X-TopMaster-Signature' => $vector['signature'],
        ]);
        roku_transcoder_hmac_test_require(
            RokuTranscoderHmac::verify(
                $vector['method'],
                $vector['path'],
                $vector['timestamp'],
                $vector['nonce'],
                $vector['body'],
                $secret,
                $vector['signature']
            )
        );
        roku_transcoder_hmac_test_require(
            RokuTranscoderHmac::sign(
                $vector['method'],
                $vector['path'],
                $vector['timestamp'],
                $vector['nonce'],
                $vector['body'],
                $secret
            ) === $result
        );
    }

    $post = $vectors[0];
    $invalidMethodCases = ['', 1, 'PUT', 'PATCH', 'HEAD', 'OPTIONS'];
    foreach ($invalidMethodCases as $method) {
        roku_transcoder_hmac_test_error(
            static fn () => RokuTranscoderHmac::sign(
                $method,
                $post['path'],
                $post['timestamp'],
                $post['nonce'],
                $post['body'],
                $secret
            ),
            'ROKU_TRANSCODER_HMAC_INVALID_METHOD'
        );
    }

    $invalidPaths = [
        '',
        'internal/sessions',
        'https://example.invalid/internal/sessions',
        '/internal/sessions?x=1',
        '/internal/sessions#fragment',
        '/internal/%73essions',
        '/internal//sessions',
        '/internal/../sessions',
        '/internal/sessions/short/status',
        '/internal/sessions/' . str_repeat('a', 129) . '/status',
        '/internal/sessions/invalid.id.value/status',
        '/media/synthetic/index.m3u8',
        '/internal/sessions/synthetic_session_A1',
        '/internal/sessions/synthetic_session_A1/status/extra',
    ];
    foreach ($invalidPaths as $path) {
        roku_transcoder_hmac_test_error(
            static fn () => RokuTranscoderHmac::sign(
                'GET',
                $path,
                $post['timestamp'],
                $post['nonce'],
                '',
                $secret
            ),
            'ROKU_TRANSCODER_HMAC_INVALID_PATH'
        );
    }
    foreach ([
        ['GET', '/internal/sessions', ''],
        ['POST', '/internal/sessions/synthetic_session_A1/status', '{}'],
        ['DELETE', '/internal/sessions/synthetic_session_A1/status', ''],
    ] as $case) {
        roku_transcoder_hmac_test_error(
            static fn () => RokuTranscoderHmac::sign(
                $case[0],
                $case[1],
                $post['timestamp'],
                $post['nonce'],
                $case[2],
                $secret
            ),
            'ROKU_TRANSCODER_HMAC_INVALID_PATH'
        );
    }

    foreach ([0, -1, 1.0, '1700000001'] as $timestamp) {
        roku_transcoder_hmac_test_error(
            static fn () => RokuTranscoderHmac::sign(
                'POST',
                $post['path'],
                $timestamp,
                $post['nonce'],
                $post['body'],
                $secret
            ),
            'ROKU_TRANSCODER_HMAC_INVALID_TIMESTAMP'
        );
    }

    foreach (['', str_repeat('a', 15), str_repeat('a', 129), 'invalid nonce value', 'invalid=padding000', 'unicode_ç_00000000', "control_\n_0000000"] as $nonce) {
        roku_transcoder_hmac_test_error(
            static fn () => RokuTranscoderHmac::sign(
                'POST',
                $post['path'],
                $post['timestamp'],
                $nonce,
                $post['body'],
                $secret
            ),
            'ROKU_TRANSCODER_HMAC_INVALID_NONCE'
        );
    }

    foreach ([
        ['POST', '/internal/sessions', ''],
        ['POST', '/internal/sessions', str_repeat('a', 65537)],
        ['GET', '/internal/sessions/synthetic_session_A1/status', 'x'],
        ['DELETE', '/internal/sessions/synthetic_session_A1', 'x'],
        ['POST', '/internal/sessions', null],
    ] as $case) {
        roku_transcoder_hmac_test_error(
            static fn () => RokuTranscoderHmac::sign(
                $case[0],
                $case[1],
                $post['timestamp'],
                $post['nonce'],
                $case[2],
                $secret
            ),
            'ROKU_TRANSCODER_HMAC_INVALID_BODY'
        );
    }

    foreach (['', str_repeat('a', 31), null] as $invalidSecret) {
        roku_transcoder_hmac_test_error(
            static fn () => RokuTranscoderHmac::sign(
                'POST',
                $post['path'],
                $post['timestamp'],
                $post['nonce'],
                $post['body'],
                $invalidSecret
            ),
            'ROKU_TRANSCODER_HMAC_INVALID_SECRET'
        );
    }

    foreach (['', str_repeat('a', 63), str_repeat('a', 65), str_repeat('A', 64), str_repeat('g', 64)] as $signature) {
        roku_transcoder_hmac_test_error(
            static fn () => RokuTranscoderHmac::verify(
                'POST',
                $post['path'],
                $post['timestamp'],
                $post['nonce'],
                $post['body'],
                $secret,
                $signature
            ),
            'ROKU_TRANSCODER_HMAC_INVALID_SIGNATURE'
        );
    }
    roku_transcoder_hmac_test_require(!RokuTranscoderHmac::verify(
        'POST',
        $post['path'],
        $post['timestamp'],
        $post['nonce'],
        $post['body'],
        $secret,
        str_repeat('0', 64)
    ));

    $baseline = RokuTranscoderHmac::sign('POST', $post['path'], $post['timestamp'], $post['nonce'], $post['body'], $secret);
    $changes = [
        RokuTranscoderHmac::sign('POST', $post['path'], $post['timestamp'] + 1, $post['nonce'], $post['body'], $secret),
        RokuTranscoderHmac::sign('POST', $post['path'], $post['timestamp'], 'nonce_TEST_ONLY_00000000000000000999', $post['body'], $secret),
        RokuTranscoderHmac::sign('POST', $post['path'], $post['timestamp'], $post['nonce'], $post['body'] . ' ', $secret),
        RokuTranscoderHmac::sign('POST', $post['path'], $post['timestamp'], $post['nonce'], '{"attempt":1,"kind":"synthetic_create"}', $secret),
        RokuTranscoderHmac::sign('POST', $post['path'], $post['timestamp'], $post['nonce'], $post['body'], $secret . 'x'),
    ];
    foreach ($changes as $changed) {
        roku_transcoder_hmac_test_require($changed['signature'] !== $baseline['signature']);
    }

    $status = $vectors[1];
    $cancel = RokuTranscoderHmac::sign(
        'DELETE',
        '/internal/sessions/synthetic_session_A1',
        $status['timestamp'],
        $status['nonce'],
        '',
        $secret
    );
    roku_transcoder_hmac_test_require($cancel['signature'] !== $status['signature']);
    roku_transcoder_hmac_test_require(!RokuTranscoderHmac::verify(
        'DELETE',
        '/internal/sessions/synthetic_session_A1',
        $status['timestamp'],
        $status['nonce'],
        '',
        $secret,
        $status['signature']
    ));

    $nonceOne = RokuTranscoderHmac::generateNonce();
    $nonceTwo = RokuTranscoderHmac::generateNonce();
    foreach ([$nonceOne, $nonceTwo] as $nonce) {
        roku_transcoder_hmac_test_require(strlen($nonce) === 43);
        roku_transcoder_hmac_test_require(preg_match('/\A[A-Za-z0-9_-]{43}\z/', $nonce) === 1);
        $decoded = base64_decode(strtr($nonce, '-_', '+/') . '=', true);
        roku_transcoder_hmac_test_require(is_string($decoded) && strlen($decoded) === 32);
        roku_transcoder_hmac_test_require(
            rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') === $nonce
        );
    }
    roku_transcoder_hmac_test_require($nonceOne !== $nonceTwo);

    fwrite(STDOUT, "ROKU_TRANSCODER_HMAC_TEST_PASS\n");
    exit(0);
} catch (Throwable) {
    fwrite(STDOUT, "ROKU_TRANSCODER_HMAC_TEST_FAIL\n");
    exit(1);
}
