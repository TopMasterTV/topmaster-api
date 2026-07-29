<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/roku_audio_fallback_runtime.php';

final class RokuAudioFallbackRuntimeTestExecutor implements RokuAudioFallbackQueryExecutor
{
    /** @var list<array<string,mixed>|null> */
    public array $rows = [];
    /** @var list<array{sql:string,parameters:array<string,int|string>,types:array<string,int>}> */
    public array $calls = [];

    public function fetchOne(string $sql, array $parameters, array $parameterTypes): ?array
    {
        $this->calls[] = ['sql' => $sql, 'parameters' => $parameters, 'types' => $parameterTypes];
        return array_shift($this->rows);
    }
}

final class RokuAudioFallbackRuntimeTestTransport implements RokuTranscoderHttpTransport
{
    /** @var list<RokuTranscoderHttpRequest> */
    public array $requests = [];
    public string $status = 'created';

    public function send(RokuTranscoderHttpRequest $request): RokuTranscoderHttpResponse
    {
        $this->requests[] = $request;
        $id = '';
        if ($request->method === 'POST') {
            $decoded = json_decode($request->body, true, 32, JSON_THROW_ON_ERROR);
            $id = is_array($decoded) ? (string) ($decoded['internal_session_id'] ?? '') : '';
        } else {
            preg_match('#/internal/sessions/([A-Za-z0-9_-]+)#', $request->url, $match);
            $id = $match[1] ?? '';
        }
        $body = json_encode([
            'ok' => true,
            'session' => [
                'id' => $id,
                'status' => $this->status,
                'created_at' => '2026-07-29T12:00:00Z',
                'expires_at' => '2026-07-29T13:00:00Z',
                'last_access_at' => '2026-07-29T12:00:01Z',
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return new RokuTranscoderHttpResponse(
            $request->method === 'POST' ? 202 : 200,
            'application/json',
            $body
        );
    }
}

function roku_audio_fallback_runtime_test_require(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('TEST_FAILURE');
    }
}

function roku_audio_fallback_runtime_test_error(
    callable $operation,
    string $code,
    array $forbidden = []
): void {
    try {
        $operation();
    } catch (RokuAudioFallbackRuntimeException $exception) {
        roku_audio_fallback_runtime_test_require($exception->getMessage() === $code);
        foreach ($forbidden as $value) {
            roku_audio_fallback_runtime_test_require(
                !is_string($value) || $value === ''
                || !str_contains($exception->getMessage(), $value)
            );
        }
        return;
    } catch (Throwable) {
        throw new RuntimeException('TEST_FAILURE');
    }
    throw new RuntimeException('TEST_FAILURE');
}

/** @return array<string,string|null> */
function roku_audio_fallback_runtime_test_values(array $changes = []): array
{
    return array_replace([
        RokuAudioFallbackRuntimeConfigLoader::ENABLED => 'true',
        RokuAudioFallbackRuntimeConfigLoader::DERIVATION_SECRET =>
            'TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES',
        RokuAudioFallbackRuntimeConfigLoader::TTL_SECONDS => '3600',
        RokuAudioFallbackRuntimeConfigLoader::INTERNAL_URL =>
            'https://internal-transcoder.example.invalid',
        RokuAudioFallbackRuntimeConfigLoader::PUBLIC_URL =>
            'https://public-transcoder.example.invalid',
        RokuAudioFallbackRuntimeConfigLoader::HMAC_SECRET =>
            'TEST_ONLY_DO_NOT_USE__HMAC_SECRET_DIFFERENT_32_BYTES',
        RokuAudioFallbackRuntimeConfigLoader::CONNECT_TIMEOUT_MS => '2000',
        RokuAudioFallbackRuntimeConfigLoader::TOTAL_TIMEOUT_MS => '10000',
        RokuAudioFallbackRuntimeConfigLoader::MAX_RESPONSE_BYTES => '65536',
    ], $changes);
}

function roku_audio_fallback_runtime_test_load(array $changes = []): RokuAudioFallbackRuntimeConfig
{
    return (new RokuAudioFallbackRuntimeConfigLoader(
        new RokuAudioFallbackRuntimeArrayValueProvider(
            roku_audio_fallback_runtime_test_values($changes)
        )
    ))->load();
}

/** @return array<string,mixed> */
function roku_audio_fallback_runtime_test_context_row(): array
{
    return [
        'sistema_id' => '202',
        'cliente_id' => '101',
        'active' => 't',
        'access_type' => 'xtream',
        'base_url' => 'https://provider.example.invalid',
        'username' => 'TEST_ONLY_DO_NOT_USE_user',
        'password' => 'TEST_ONLY_DO_NOT_USE_password',
    ];
}

/** @return array<string,mixed> */
function roku_audio_fallback_runtime_test_session_row(
    string $id,
    string $hash,
    string $status = 'ready'
): array {
    return [
        'id' => '9001',
        'internal_session_id' => $id,
        'public_token_hash' => $hash,
        'cliente_id' => '101',
        'sistema_id' => '202',
        'stream_id' => 'synthetic_stream_1',
        'extensao_sanitizada' => 'mp4',
        'status' => $status,
        'fallback_kind' => 'vod_audio_stereo',
        'created_at' => '2026-07-29T12:00:00Z',
        'expires_at' => '2026-07-29T13:00:00Z',
        'last_access_at' => '2026-07-29T12:00:01Z',
        'started_at' => null,
        'ready_at' => null,
        'finished_at' => null,
        'cancelled_at' => null,
        'failure_code' => null,
        'tentativa' => '1',
    ];
}

try {
    $config = roku_audio_fallback_runtime_test_load();
    roku_audio_fallback_runtime_test_require(
        $config->isEnabled()
        && $config->getTtlSeconds() === 3600
        && $config->getConnectTimeoutMs() === 2000
        && $config->getTotalTimeoutMs() === 10000
        && $config->getMaxResponseBytes() === 65536
    );
    $defaults = roku_audio_fallback_runtime_test_values();
    unset(
        $defaults[RokuAudioFallbackRuntimeConfigLoader::CONNECT_TIMEOUT_MS],
        $defaults[RokuAudioFallbackRuntimeConfigLoader::TOTAL_TIMEOUT_MS],
        $defaults[RokuAudioFallbackRuntimeConfigLoader::MAX_RESPONSE_BYTES]
    );
    $defaultConfig = (new RokuAudioFallbackRuntimeConfigLoader(
        new RokuAudioFallbackRuntimeArrayValueProvider($defaults)
    ))->load();
    roku_audio_fallback_runtime_test_require(
        $defaultConfig->getConnectTimeoutMs() === 2000
        && $defaultConfig->getTotalTimeoutMs() === 10000
        && $defaultConfig->getMaxResponseBytes() === 65536
    );

    foreach (['true', 'TRUE', '1', 'false', 'FALSE', '0'] as $flag) {
        $flagConfig = roku_audio_fallback_runtime_test_load([
            RokuAudioFallbackRuntimeConfigLoader::ENABLED => $flag,
        ]);
        roku_audio_fallback_runtime_test_require(
            $flagConfig->isEnabled() === in_array($flag, ['true', 'TRUE', '1'], true)
        );
    }
    foreach (['', 'yes', 'no', 'on', 'off', ' true ', '2'] as $flag) {
        roku_audio_fallback_runtime_test_error(
            static fn () => roku_audio_fallback_runtime_test_load([
                RokuAudioFallbackRuntimeConfigLoader::ENABLED => $flag,
            ]),
            'ROKU_AUDIO_FALLBACK_RUNTIME_INVALID_CONFIG',
            [$flag]
        );
    }

    foreach ([
        [RokuAudioFallbackRuntimeConfigLoader::TTL_SECONDS, '60'],
        [RokuAudioFallbackRuntimeConfigLoader::TTL_SECONDS, '21600'],
        [RokuAudioFallbackRuntimeConfigLoader::CONNECT_TIMEOUT_MS, '100'],
        [RokuAudioFallbackRuntimeConfigLoader::CONNECT_TIMEOUT_MS, '10000'],
        [RokuAudioFallbackRuntimeConfigLoader::TOTAL_TIMEOUT_MS, '500'],
        [RokuAudioFallbackRuntimeConfigLoader::TOTAL_TIMEOUT_MS, '30000'],
        [RokuAudioFallbackRuntimeConfigLoader::MAX_RESPONSE_BYTES, '1024'],
        [RokuAudioFallbackRuntimeConfigLoader::MAX_RESPONSE_BYTES, '1048576'],
    ] as [$name, $value]) {
        $changes = [$name => $value];
        if ($name === RokuAudioFallbackRuntimeConfigLoader::TOTAL_TIMEOUT_MS && $value === '500') {
            $changes[RokuAudioFallbackRuntimeConfigLoader::CONNECT_TIMEOUT_MS] = '100';
        }
        roku_audio_fallback_runtime_test_load($changes);
    }
    foreach (['', '+60', '-60', '060', '6e1', '60.0', ' 60', '60x', str_repeat('9', 30)] as $bad) {
        roku_audio_fallback_runtime_test_error(
            static fn () => roku_audio_fallback_runtime_test_load([
                RokuAudioFallbackRuntimeConfigLoader::TTL_SECONDS => $bad,
            ]),
            'ROKU_AUDIO_FALLBACK_RUNTIME_INVALID_CONFIG',
            [$bad]
        );
    }
    foreach ([
        [RokuAudioFallbackRuntimeConfigLoader::TTL_SECONDS, '59'],
        [RokuAudioFallbackRuntimeConfigLoader::TTL_SECONDS, '21601'],
        [RokuAudioFallbackRuntimeConfigLoader::CONNECT_TIMEOUT_MS, '99'],
        [RokuAudioFallbackRuntimeConfigLoader::CONNECT_TIMEOUT_MS, '10001'],
        [RokuAudioFallbackRuntimeConfigLoader::TOTAL_TIMEOUT_MS, '499'],
        [RokuAudioFallbackRuntimeConfigLoader::TOTAL_TIMEOUT_MS, '30001'],
        [RokuAudioFallbackRuntimeConfigLoader::MAX_RESPONSE_BYTES, '1023'],
        [RokuAudioFallbackRuntimeConfigLoader::MAX_RESPONSE_BYTES, '1048577'],
    ] as [$name, $value]) {
        roku_audio_fallback_runtime_test_error(
            static fn () => roku_audio_fallback_runtime_test_load([$name => $value]),
            'ROKU_AUDIO_FALLBACK_RUNTIME_INVALID_CONFIG',
            [$value]
        );
    }
    roku_audio_fallback_runtime_test_error(
        static fn () => roku_audio_fallback_runtime_test_load([
            RokuAudioFallbackRuntimeConfigLoader::CONNECT_TIMEOUT_MS => '3000',
            RokuAudioFallbackRuntimeConfigLoader::TOTAL_TIMEOUT_MS => '2000',
        ]),
        'ROKU_AUDIO_FALLBACK_RUNTIME_INVALID_CONFIG'
    );

    $derivation = roku_audio_fallback_runtime_test_values()[
        RokuAudioFallbackRuntimeConfigLoader::DERIVATION_SECRET
    ];
    foreach ([
        [RokuAudioFallbackRuntimeConfigLoader::DERIVATION_SECRET, str_repeat('d', 31)],
        [RokuAudioFallbackRuntimeConfigLoader::HMAC_SECRET, str_repeat('h', 31)],
        [RokuAudioFallbackRuntimeConfigLoader::DERIVATION_SECRET, str_repeat('d', 4097)],
        [RokuAudioFallbackRuntimeConfigLoader::HMAC_SECRET, $derivation],
    ] as [$name, $value]) {
        roku_audio_fallback_runtime_test_error(
            static fn () => roku_audio_fallback_runtime_test_load([$name => $value]),
            'ROKU_AUDIO_FALLBACK_RUNTIME_INVALID_CONFIG',
            [$value]
        );
    }
    roku_audio_fallback_runtime_test_load([
        RokuAudioFallbackRuntimeConfigLoader::DERIVATION_SECRET =>
            ' ' . str_repeat('d', 31) . ' ',
        RokuAudioFallbackRuntimeConfigLoader::HMAC_SECRET =>
            str_repeat('h', 31) . "\0",
    ]);

    foreach ([
        'http://runtime.example.invalid',
        '/relative',
        'https://user@runtime.example.invalid',
        'https://runtime.example.invalid?q=1',
        'https://runtime.example.invalid#x',
        'https://runtime.example.invalid/path',
        'https://runtime%2eexample.invalid',
        "https://runtime.example.invalid\n",
        'https://runtime.example.invalid\\x',
        'https://provedorá.example.invalid',
        'https://' . str_repeat('a', 2040) . '.invalid',
    ] as $badUrl) {
        foreach ([
            RokuAudioFallbackRuntimeConfigLoader::INTERNAL_URL,
            RokuAudioFallbackRuntimeConfigLoader::PUBLIC_URL,
        ] as $name) {
            roku_audio_fallback_runtime_test_error(
                static fn () => roku_audio_fallback_runtime_test_load([$name => $badUrl]),
                'ROKU_AUDIO_FALLBACK_RUNTIME_INVALID_CONFIG',
                [$badUrl]
            );
        }
    }
    roku_audio_fallback_runtime_test_load([
        RokuAudioFallbackRuntimeConfigLoader::INTERNAL_URL =>
            'https://runtime.example.invalid:8443/',
        RokuAudioFallbackRuntimeConfigLoader::PUBLIC_URL =>
            'https://public.example.invalid:9443/',
    ]);

    foreach ([
        RokuAudioFallbackRuntimeConfigLoader::ENABLED,
        RokuAudioFallbackRuntimeConfigLoader::DERIVATION_SECRET,
        RokuAudioFallbackRuntimeConfigLoader::TTL_SECONDS,
        RokuAudioFallbackRuntimeConfigLoader::INTERNAL_URL,
        RokuAudioFallbackRuntimeConfigLoader::PUBLIC_URL,
        RokuAudioFallbackRuntimeConfigLoader::HMAC_SECRET,
    ] as $required) {
        $values = roku_audio_fallback_runtime_test_values();
        unset($values[$required]);
        roku_audio_fallback_runtime_test_error(
            static fn () => (new RokuAudioFallbackRuntimeConfigLoader(
                new RokuAudioFallbackRuntimeArrayValueProvider($values)
            ))->load(),
            'ROKU_AUDIO_FALLBACK_RUNTIME_MISSING_CONFIG'
        );
    }

    $requestId = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';
    $secret = roku_audio_fallback_runtime_test_values()[
        RokuAudioFallbackRuntimeConfigLoader::DERIVATION_SECRET
    ];
    $derived = RokuAudioFallbackIdempotency::derivar(
        101,
        202,
        'synthetic_stream_1',
        'mp4',
        $requestId,
        $secret
    );

    $createExecutor = new RokuAudioFallbackRuntimeTestExecutor();
    $createExecutor->rows = [null, roku_audio_fallback_runtime_test_context_row()];
    $createTransport = new RokuAudioFallbackRuntimeTestTransport();
    $clockCalls = 0;
    $clock = static function () use (&$clockCalls): int {
        $clockCalls++;
        return 1785326400;
    };
    $createService = RokuAudioFallbackRuntimeFactory::build(
        $config,
        $createExecutor,
        $createTransport,
        $clock
    );
    roku_audio_fallback_runtime_test_require(
        $createService instanceof RokuAudioFallbackService
        && $createExecutor->calls === []
        && $createTransport->requests === []
        && $clockCalls === 0
    );
    $created = $createService->createSession(
        101,
        202,
        'synthetic_stream_1',
        'mp4',
        $requestId
    );
    roku_audio_fallback_runtime_test_require(
        $created->getStatus() === 'preparing'
        && $created->getPlaybackUrl() === null
        && count($createExecutor->calls) === 2
        && count($createTransport->requests) === 1
        && $createTransport->requests[0]->method === 'POST'
        && str_contains($createExecutor->calls[1]['sql'], 'AND s.cliente_id = :cliente_id')
    );

    $statusExecutor = new RokuAudioFallbackRuntimeTestExecutor();
    $statusExecutor->rows = [
        roku_audio_fallback_runtime_test_session_row(
            $derived['internal_session_id'],
            $derived['public_token_hash']
        ),
    ];
    $statusTransport = new RokuAudioFallbackRuntimeTestTransport();
    $statusTransport->status = 'ready';
    $statusService = RokuAudioFallbackRuntimeFactory::build(
        $config,
        $statusExecutor,
        $statusTransport,
        static fn (): int => 1785326400
    );
    $status = $statusService->getStatus(
        101,
        $derived['internal_session_id'],
        $requestId
    );
    roku_audio_fallback_runtime_test_require(
        $status->getStatus() === 'ready'
        && is_string($status->getPlaybackUrl())
        && count($statusExecutor->calls) === 1
        && count($statusTransport->requests) === 1
        && $statusTransport->requests[0]->method === 'GET'
    );

    $cancelExecutor = new RokuAudioFallbackRuntimeTestExecutor();
    $cancelExecutor->rows = [
        roku_audio_fallback_runtime_test_session_row(
            $derived['internal_session_id'],
            $derived['public_token_hash']
        ),
    ];
    $cancelTransport = new RokuAudioFallbackRuntimeTestTransport();
    $cancelTransport->status = 'cancelled';
    $cancelService = RokuAudioFallbackRuntimeFactory::build(
        $config,
        $cancelExecutor,
        $cancelTransport,
        static fn (): int => 1785326400
    );
    $cancelled = $cancelService->cancelSession(101, $derived['internal_session_id']);
    roku_audio_fallback_runtime_test_require(
        $cancelled->getStatus() === 'cancelled'
        && $cancelled->getPlaybackUrl() === null
        && count($cancelExecutor->calls) === 1
        && count($cancelTransport->requests) === 1
        && $cancelTransport->requests[0]->method === 'DELETE'
    );

    $disabledExecutor = new RokuAudioFallbackRuntimeTestExecutor();
    $disabledTransport = new RokuAudioFallbackRuntimeTestTransport();
    $disabledClockCalls = 0;
    $disabled = RokuAudioFallbackRuntimeFactory::build(
        roku_audio_fallback_runtime_test_load([
            RokuAudioFallbackRuntimeConfigLoader::ENABLED => 'false',
        ]),
        $disabledExecutor,
        $disabledTransport,
        static function () use (&$disabledClockCalls): int {
            $disabledClockCalls++;
            return 1785326400;
        }
    );
    foreach ([
        static fn () => $disabled->createSession(101, 202, 'synthetic_stream_1', 'mp4', $requestId),
        static fn () => $disabled->getStatus(101, $derived['internal_session_id'], $requestId),
        static fn () => $disabled->cancelSession(101, $derived['internal_session_id']),
    ] as $operation) {
        try {
            $operation();
        } catch (RokuAudioFallbackServiceException $exception) {
            roku_audio_fallback_runtime_test_require(
                $exception->getMessage() === 'ROKU_AUDIO_FALLBACK_DISABLED'
            );
            continue;
        }
        throw new RuntimeException('TEST_FAILURE');
    }
    roku_audio_fallback_runtime_test_require(
        $disabledExecutor->calls === []
        && $disabledTransport->requests === []
        && $disabledClockCalls === 0
    );

    $reflection = new ReflectionClass(RokuAudioFallbackRuntimeConfig::class);
    roku_audio_fallback_runtime_test_require(
        $reflection->isFinal() && $reflection->isReadOnly()
    );
    foreach ($reflection->getProperties() as $property) {
        roku_audio_fallback_runtime_test_require(
            $property->isPrivate() && $property->isReadOnly()
        );
    }
    foreach ([
        'getDerivationSecret', 'getHmacSecret', 'toArray', 'jsonSerialize',
        '__toString', '__debugInfo', '__get', '__set',
    ] as $method) {
        roku_audio_fallback_runtime_test_require(!$reflection->hasMethod($method));
    }

    $source = file_get_contents(dirname(__DIR__) . '/roku_audio_fallback_runtime.php');
    roku_audio_fallback_runtime_test_require(is_string($source));
    foreach ([
        'getenv(', '$_ENV', '$_SERVER', 'putenv(', 'dotenv', '.env',
        'new PDO', 'DATABASE_URL', 'pg_connect', 'curl_init',
        'stream_socket_client', 'fsockopen', 'gethostbyname', 'dns_get_record',
        'checkdnsrr', 'exec(', 'shell_exec', 'passthru', 'proc_open', 'popen',
        'eval(', 'unserialize', 'error_log', 'var_dump', 'print_r', 'phpinfo',
    ] as $forbidden) {
        roku_audio_fallback_runtime_test_require(!str_contains($source, $forbidden));
    }

    fwrite(STDOUT, "ROKU_AUDIO_FALLBACK_RUNTIME_TEST_PASS\n");
    exit(0);
} catch (Throwable) {
    fwrite(STDOUT, "ROKU_AUDIO_FALLBACK_RUNTIME_TEST_FAIL\n");
    exit(1);
}
