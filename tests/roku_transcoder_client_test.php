<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/roku_transcoder_client.php';

final class RokuTranscoderClientTestTransport implements RokuTranscoderHttpTransport
{
    /** @var list<RokuTranscoderHttpRequest> */
    public array $requests = [];
    /** @var list<RokuTranscoderHttpResponse|Throwable> */
    public array $results = [];

    public function send(RokuTranscoderHttpRequest $request): RokuTranscoderHttpResponse
    {
        $this->requests[] = $request;
        $result = array_shift($this->results);
        if ($result instanceof Throwable) {
            throw $result;
        }
        if (!$result instanceof RokuTranscoderHttpResponse) {
            throw new RuntimeException('SYNTHETIC_TRANSPORT_FAILURE');
        }
        return $result;
    }
}

function roku_transcoder_client_test_require(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('TEST_FAILURE');
    }
}

function roku_transcoder_client_test_error(callable $operation, string $code, array $forbidden = []): void
{
    try {
        $operation();
    } catch (RokuTranscoderClientException $exception) {
        roku_transcoder_client_test_require($exception->getMessage() === $code);
        foreach ($forbidden as $value) {
            roku_transcoder_client_test_require(
                !is_string($value) || $value === '' || !str_contains($exception->getMessage(), $value)
            );
        }
        return;
    } catch (Throwable) {
        throw new RuntimeException('TEST_FAILURE');
    }
    throw new RuntimeException('TEST_FAILURE');
}

function roku_transcoder_client_test_response(
    string $id,
    string $status,
    int $httpStatus = 200
): RokuTranscoderHttpResponse {
    $body = json_encode([
        'ok' => true,
        'session' => [
            'id' => $id,
            'status' => $status,
            'created_at' => '2026-07-29T12:00:00Z',
            'expires_at' => '2026-07-29T13:00:00Z',
            'last_access_at' => '2026-07-29T12:00:01Z',
        ],
    ], JSON_THROW_ON_ERROR);
    return new RokuTranscoderHttpResponse($httpStatus, 'application/json; charset=utf-8', $body);
}

try {
    $baseUrl = 'https://transcoder.example.invalid/';
    $secret = 'TEST_ONLY_DO_NOT_USE__TRANSCODER_CLIENT_SECRET_32_BYTES';
    $id = 'synthetic_session_A1';
    $hash = str_repeat('a', 64);
    $sourceUrl = 'https://source.example.invalid/movie/synthetic.mp4';
    $timestamps = [1700000101, 1700000102, 1700000103, 1700000104];
    $nonces = [
        'nonce_TEST_ONLY_CLIENT_0000000000000001',
        'nonce_TEST_ONLY_CLIENT_0000000000000002',
        'nonce_TEST_ONLY_CLIENT_0000000000000003',
        'nonce_TEST_ONLY_CLIENT_0000000000000004',
    ];
    $clockCalls = 0;
    $nonceCalls = 0;
    $transport = new RokuTranscoderClientTestTransport();
    $client = new RokuTranscoderClient(
        $baseUrl,
        $secret,
        $transport,
        2000,
        10000,
        65536,
        static function () use (&$clockCalls, $timestamps): int {
            return $timestamps[$clockCalls++];
        },
        static function () use (&$nonceCalls, $nonces): string {
            return $nonces[$nonceCalls++];
        }
    );

    $transport->results[] = roku_transcoder_client_test_response($id, 'created', 202);
    $created = $client->createSession(
        $id,
        $hash,
        101,
        202,
        'synthetic_stream_1',
        $sourceUrl,
        'mp4',
        '2026-07-29T13:00:00Z'
    );
    $createRequest = $transport->requests[0];
    roku_transcoder_client_test_require($createRequest->method === 'POST');
    roku_transcoder_client_test_require(
        $createRequest->url === 'https://transcoder.example.invalid/internal/sessions'
    );
    roku_transcoder_client_test_require(!str_ends_with($createRequest->body, "\n"));
    $expectedBody = '{"internal_session_id":"synthetic_session_A1","public_token_hash":"'
        . $hash
        . '","cliente_id":101,"sistema_id":202,"stream_id":"synthetic_stream_1",'
        . '"source_url":"https://source.example.invalid/movie/synthetic.mp4",'
        . '"extension":"mp4","expires_at":"2026-07-29T13:00:00Z"}';
    roku_transcoder_client_test_require($createRequest->body === $expectedBody);
    roku_transcoder_client_test_require($createRequest->headers['Content-Type'] === 'application/json');
    roku_transcoder_client_test_require($createRequest->headers['Accept'] === 'application/json');
    roku_transcoder_client_test_require($createRequest->headers['Accept-Encoding'] === 'identity');
    roku_transcoder_client_test_require(
        RokuTranscoderHmac::verify(
            'POST',
            '/internal/sessions',
            1700000101,
            $nonces[0],
            $createRequest->body,
            $secret,
            $createRequest->headers['X-TopMaster-Signature']
        )
    );
    roku_transcoder_client_test_require($created === [
        'id' => $id,
        'status' => 'created',
        'created_at' => '2026-07-29T12:00:00Z',
        'expires_at' => '2026-07-29T13:00:00Z',
        'last_access_at' => '2026-07-29T12:00:01Z',
    ]);
    roku_transcoder_client_test_require(!array_key_exists('source_url', $created));
    roku_transcoder_client_test_require(!array_key_exists('public_token_hash', $created));

    $transport->results[] = roku_transcoder_client_test_response($id, 'ready');
    $status = $client->getStatus($id);
    $statusRequest = $transport->requests[1];
    roku_transcoder_client_test_require($statusRequest->method === 'GET');
    roku_transcoder_client_test_require(
        $statusRequest->url === 'https://transcoder.example.invalid/internal/sessions/'
            . $id . '/status'
    );
    roku_transcoder_client_test_require($statusRequest->body === '');
    roku_transcoder_client_test_require(!array_key_exists('Content-Type', $statusRequest->headers));
    roku_transcoder_client_test_require($status['status'] === 'ready');

    $transport->results[] = roku_transcoder_client_test_response($id, 'cancelled');
    $cancelled = $client->cancelSession($id);
    $cancelRequest = $transport->requests[2];
    roku_transcoder_client_test_require($cancelRequest->method === 'DELETE');
    roku_transcoder_client_test_require($cancelRequest->body === '');
    roku_transcoder_client_test_require($cancelled['status'] === 'cancelled');

    $transport->results[] = roku_transcoder_client_test_response($id, 'cancelled');
    roku_transcoder_client_test_require($client->cancelSession($id)['status'] === 'cancelled');
    roku_transcoder_client_test_require($clockCalls === 4 && $nonceCalls === 4);
    $signatures = array_map(
        static fn (RokuTranscoderHttpRequest $request): string =>
            $request->headers['X-TopMaster-Signature'],
        $transport->requests
    );
    roku_transcoder_client_test_require(count(array_unique($signatures)) === 4);
    roku_transcoder_client_test_require(
        count(array_unique(array_map(
            static fn (RokuTranscoderHttpRequest $request): string =>
                $request->headers['X-TopMaster-Nonce'],
            $transport->requests
        ))) === 4
    );

    $factory = static fn (
        mixed $url = 'https://transcoder.example.invalid',
        mixed $key = 'TEST_ONLY_DO_NOT_USE__TRANSCODER_CLIENT_SECRET_32_BYTES',
        mixed $connect = 2000,
        mixed $total = 10000,
        mixed $limit = 65536
    ): RokuTranscoderClient => new RokuTranscoderClient(
        $url,
        $key,
        new RokuTranscoderClientTestTransport(),
        $connect,
        $total,
        $limit,
        static fn (): int => 1700000200,
        static fn (): string => 'nonce_TEST_ONLY_CLIENT_0000000000000099'
    );

    foreach ([
        '', 'http://transcoder.example.invalid', '/relative',
        'https://user@transcoder.example.invalid', 'https://transcoder.example.invalid?q=1',
        'https://transcoder.example.invalid#x', 'https://transcoder.example.invalid/path',
        'https://transcoder%2eexample.invalid', "https://transcoder.example.invalid\n",
        'https://:443', 'https://transcoder.example.invalid:99999',
        'https://tést.example.invalid',
    ] as $invalidUrl) {
        roku_transcoder_client_test_error(
            static fn () => $factory($invalidUrl),
            'ROKU_TRANSCODER_CLIENT_INVALID_BASE_URL',
            [$invalidUrl]
        );
    }
    foreach ([
        ['', 2000, 10000, 65536],
        [str_repeat('x', 31), 2000, 10000, 65536],
        [$secret, 99, 10000, 65536],
        [$secret, 10001, 10001, 65536],
        [$secret, 2000, 499, 65536],
        [$secret, 2000, 30001, 65536],
        [$secret, 2000, 1000, 65536],
        [$secret, 2000, 10000, 1023],
        [$secret, 2000, 10000, 1048577],
        [$secret, 2000.0, 10000, 65536],
    ] as $config) {
        roku_transcoder_client_test_error(
            static fn () => $factory(
                'https://transcoder.example.invalid',
                $config[0],
                $config[1],
                $config[2],
                $config[3]
            ),
            'ROKU_TRANSCODER_CLIENT_INVALID_CONFIG',
            [$config[0]]
        );
    }

    $invalidCreateCases = [
        ['short', $hash, 1, 1, 's', $sourceUrl, 'mp4', '2026-07-29T13:00:00Z'],
        [$id, strtoupper($hash), 1, 1, 's', $sourceUrl, 'mp4', '2026-07-29T13:00:00Z'],
        [$id, $hash, 0, 1, 's', $sourceUrl, 'mp4', '2026-07-29T13:00:00Z'],
        [$id, $hash, 1, 2147483648, 's', $sourceUrl, 'mp4', '2026-07-29T13:00:00Z'],
        [$id, $hash, '1', 1, 's', $sourceUrl, 'mp4', '2026-07-29T13:00:00Z'],
        [$id, $hash, 1, 1, '', $sourceUrl, 'mp4', '2026-07-29T13:00:00Z'],
        [$id, $hash, 1, 1, str_repeat('s', 513), $sourceUrl, 'mp4', '2026-07-29T13:00:00Z'],
        [$id, $hash, 1, 1, "bad\nstream", $sourceUrl, 'mp4', '2026-07-29T13:00:00Z'],
        [$id, $hash, 1, 1, 'https://stream.invalid', $sourceUrl, 'mp4', '2026-07-29T13:00:00Z'],
        [$id, $hash, 1, 1, 's', 'relative/source', 'mp4', '2026-07-29T13:00:00Z'],
        [$id, $hash, 1, 1, 's', 'https://user@source.example.invalid/a', 'mp4', '2026-07-29T13:00:00Z'],
        [$id, $hash, 1, 1, 's', 'https://source.example.invalid/a#x', 'mp4', '2026-07-29T13:00:00Z'],
        [$id, $hash, 1, 1, 's', "https://source.example.invalid/\n", 'mp4', '2026-07-29T13:00:00Z'],
        [$id, $hash, 1, 1, 's', str_repeat('x', 4097), 'mp4', '2026-07-29T13:00:00Z'],
        [$id, $hash, 1, 1, 's', $sourceUrl, 'm3u8', '2026-07-29T13:00:00Z'],
        [$id, $hash, 1, 1, 's', $sourceUrl, 'MP4', '2026-07-29T13:00:00Z'],
        [$id, $hash, 1, 1, 's', $sourceUrl, 'mp4', 'not-a-time'],
    ];
    foreach ($invalidCreateCases as $case) {
        $invalidTransport = new RokuTranscoderClientTestTransport();
        $invalidClient = new RokuTranscoderClient(
            'https://transcoder.example.invalid',
            $secret,
            $invalidTransport
        );
        roku_transcoder_client_test_error(
            static fn () => $invalidClient->createSession(...$case),
            str_starts_with((string) $case[0], 'short')
                ? 'ROKU_TRANSCODER_CLIENT_INVALID_ARGUMENT'
                : 'ROKU_TRANSCODER_CLIENT_INVALID_PAYLOAD',
            [$sourceUrl, $secret]
        );
        roku_transcoder_client_test_require($invalidTransport->requests === []);
    }
    foreach (['short', str_repeat('a', 129), 'invalid.id.value'] as $invalidId) {
        roku_transcoder_client_test_error(
            static fn () => $factory()->getStatus($invalidId),
            'ROKU_TRANSCODER_CLIENT_INVALID_ARGUMENT',
            [$invalidId]
        );
    }

    $errorCases = [
        [401, 'ROKU_TRANSCODER_CLIENT_UNAUTHORIZED'],
        [403, 'ROKU_TRANSCODER_CLIENT_FORBIDDEN'],
        [404, 'ROKU_TRANSCODER_CLIENT_NOT_FOUND'],
        [409, 'ROKU_TRANSCODER_CLIENT_CONFLICT'],
        [429, 'ROKU_TRANSCODER_CLIENT_CAPACITY_EXCEEDED'],
        [500, 'ROKU_TRANSCODER_CLIENT_UPSTREAM_FAILED'],
        [302, 'ROKU_TRANSCODER_CLIENT_UPSTREAM_REJECTED'],
        [400, 'ROKU_TRANSCODER_CLIENT_UPSTREAM_REJECTED'],
    ];
    foreach ($errorCases as [$httpStatus, $expectedCode]) {
        $fake = new RokuTranscoderClientTestTransport();
        $fake->results[] = new RokuTranscoderHttpResponse(
            $httpStatus,
            'application/json',
            '{"error":"SYNTHETIC_REMOTE_ERROR","ok":false}'
        );
        $subject = new RokuTranscoderClient(
            'https://transcoder.example.invalid',
            $secret,
            $fake,
            2000,
            10000,
            65536,
            static fn (): int => 1700000300,
            static fn (): string => 'nonce_TEST_ONLY_CLIENT_0000000000000088'
        );
        roku_transcoder_client_test_error(
            static fn () => $subject->getStatus($id),
            $expectedCode,
            ['SYNTHETIC_REMOTE_ERROR', $secret]
        );
    }

    $invalidResponses = [
        new RokuTranscoderHttpResponse(200, null, '{}'),
        new RokuTranscoderHttpResponse(200, 'text/html', '<html>synthetic</html>'),
        new RokuTranscoderHttpResponse(200, 'application/json', ''),
        new RokuTranscoderHttpResponse(200, 'application/json', '{'),
        new RokuTranscoderHttpResponse(200, 'application/json', '{"ok":true}'),
        new RokuTranscoderHttpResponse(202, 'application/json', '{"ok":true,"session":{}}'),
        new RokuTranscoderHttpResponse(
            200,
            'application/json',
            '{"ok":true,"session":{"id":"synthetic_session_A1","status":"unknown",'
                . '"created_at":"2026-07-29T12:00:00Z","expires_at":"2026-07-29T13:00:00Z",'
                . '"last_access_at":"2026-07-29T12:00:01Z"}}'
        ),
        new RokuTranscoderHttpResponse(
            200,
            'application/json',
            '{"ok":true,"session":{"id":"synthetic_session_A1","status":1,'
                . '"created_at":"2026-07-29T12:00:00Z","expires_at":"2026-07-29T13:00:00Z",'
                . '"last_access_at":"2026-07-29T12:00:01Z"}}'
        ),
        new RokuTranscoderHttpResponse(
            200,
            'application/json',
            str_repeat('{"nested":', 33) . 'null' . str_repeat('}', 33)
        ),
        new RokuTranscoderHttpResponse(200, 'application/json', str_repeat('x', 65537)),
    ];
    foreach ($invalidResponses as $index => $invalidResponse) {
        $fake = new RokuTranscoderClientTestTransport();
        $fake->results[] = $invalidResponse;
        $subject = new RokuTranscoderClient(
            'https://transcoder.example.invalid',
            $secret,
            $fake,
            2000,
            10000,
            65536,
            static fn (): int => 1700000400,
            static fn (): string => 'nonce_TEST_ONLY_CLIENT_0000000000000077'
        );
        $expected = match ($index) {
            0, 1 => 'ROKU_TRANSCODER_CLIENT_INVALID_CONTENT_TYPE',
            9 => 'ROKU_TRANSCODER_CLIENT_RESPONSE_TOO_LARGE',
            default => 'ROKU_TRANSCODER_CLIENT_INVALID_RESPONSE',
        };
        roku_transcoder_client_test_error(
            static fn () => $subject->getStatus($id),
            $expected,
            [$invalidResponse->body, $secret]
        );
    }

    foreach ([
        new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_TIMEOUT'),
        new RuntimeException('SYNTHETIC_RAW_TRANSPORT_ERROR'),
    ] as $transportError) {
        $fake = new RokuTranscoderClientTestTransport();
        $fake->results[] = $transportError;
        $subject = new RokuTranscoderClient(
            'https://transcoder.example.invalid',
            $secret,
            $fake,
            2000,
            10000,
            65536,
            static fn (): int => 1700000500,
            static fn (): string => 'nonce_TEST_ONLY_CLIENT_0000000000000066'
        );
        roku_transcoder_client_test_error(
            static fn () => $subject->getStatus($id),
            $transportError instanceof RokuTranscoderClientException
                ? 'ROKU_TRANSCODER_CLIENT_TIMEOUT'
                : 'ROKU_TRANSCODER_CLIENT_TRANSPORT_FAILED',
            ['SYNTHETIC_RAW_TRANSPORT_ERROR', $secret]
        );
    }

    $source = file_get_contents(dirname(__DIR__) . '/roku_transcoder_client.php');
    roku_transcoder_client_test_require(is_string($source));
    foreach ([
        'CURLOPT_FOLLOWLOCATION => false',
        'CURLOPT_MAXREDIRS => 0',
        'CURLOPT_SSL_VERIFYPEER => true',
        'CURLOPT_SSL_VERIFYHOST => 2',
        'CURL_SSLVERSION_TLSv1_2',
        "CURLOPT_PROXY => ''",
        "CURLOPT_NOPROXY => '*'",
        'CURLOPT_WRITEFUNCTION',
    ] as $requiredSource) {
        roku_transcoder_client_test_require(str_contains($source, $requiredSource));
    }
    roku_transcoder_client_test_require(!str_contains($source, 'sleep('));
    roku_transcoder_client_test_require(!str_contains($source, 'usleep('));

    fwrite(STDOUT, "ROKU_TRANSCODER_CLIENT_TEST_PASS\n");
    exit(0);
} catch (Throwable) {
    fwrite(STDOUT, "ROKU_TRANSCODER_CLIENT_TEST_FAIL\n");
    exit(1);
}
