<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/roku_audio_fallback_xtream_source_resolver.php';

final class RokuAudioFallbackXtreamSourceResolverTestProvider
    implements RokuAudioFallbackXtreamSystemContextProvider
{
    public ?RokuAudioFallbackXtreamSystemContext $context = null;
    public ?Throwable $failure = null;
    public int $calls = 0;
    /** @var list<array{0:int,1:int}> */
    public array $arguments = [];

    public function getOwnedXtreamContext(
        int $clienteId,
        int $sistemaId
    ): ?RokuAudioFallbackXtreamSystemContext {
        $this->calls++;
        $this->arguments[] = [$clienteId, $sistemaId];
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return $this->context;
    }
}

final class RokuAudioFallbackXtreamSourceResolverTestStore
    implements RokuAudioFallbackSessionStore
{
    public int $calls = 0;

    public function findByInternalSessionId(string $id): ?RokuAudioFallbackSessionRecord
    {
        $this->calls++;
        return null;
    }

    public function findOwnedByClient(
        string $id,
        int $clienteId
    ): ?RokuAudioFallbackSessionRecord {
        $this->calls++;
        return null;
    }
}

final class RokuAudioFallbackXtreamSourceResolverTestGateway
    implements RokuAudioFallbackTranscoderGateway
{
    /** @var list<array<int,mixed>> */
    public array $createCalls = [];

    public function createSession(
        string $internalSessionId,
        string $publicTokenHash,
        int $clienteId,
        int $sistemaId,
        string $streamId,
        string $sourceUrl,
        string $extension,
        string $expiresAt
    ): array {
        $this->createCalls[] = func_get_args();
        return [
            'id' => $internalSessionId,
            'status' => 'created',
            'created_at' => '2026-07-29T12:00:00Z',
            'expires_at' => $expiresAt,
            'last_access_at' => '2026-07-29T12:00:01Z',
        ];
    }

    public function getSessionStatus(string $internalSessionId): array
    {
        throw new RuntimeException('TEST_FAILURE');
    }

    public function cancelSession(string $internalSessionId): array
    {
        throw new RuntimeException('TEST_FAILURE');
    }
}

function roku_audio_fallback_xtream_source_resolver_test_require(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('TEST_FAILURE');
    }
}

function roku_audio_fallback_xtream_source_resolver_test_error(
    callable $operation,
    string $code,
    array $forbidden = []
): void {
    try {
        $operation();
    } catch (RokuAudioFallbackXtreamSourceResolverException $exception) {
        roku_audio_fallback_xtream_source_resolver_test_require(
            $exception->getMessage() === $code
        );
        foreach ($forbidden as $value) {
            roku_audio_fallback_xtream_source_resolver_test_require(
                !is_string($value)
                || $value === ''
                || !str_contains($exception->getMessage(), $value)
            );
        }
        return;
    } catch (Throwable) {
        throw new RuntimeException('TEST_FAILURE');
    }
    throw new RuntimeException('TEST_FAILURE');
}

function roku_audio_fallback_xtream_source_resolver_test_context(
    string $baseUrl = 'https://provider.example.invalid',
    string $username = 'synthetic/user',
    string $password = 'synthetic?password',
    bool $active = true,
    string $accessType = 'xtream',
    int $clienteId = 101,
    int $sistemaId = 202
): RokuAudioFallbackXtreamSystemContext {
    return new RokuAudioFallbackXtreamSystemContext(
        $clienteId,
        $sistemaId,
        $baseUrl,
        $username,
        $password,
        $active,
        $accessType
    );
}

try {
    roku_audio_fallback_xtream_source_resolver_test_require(
        is_subclass_of(
            RokuAudioFallbackXtreamSourceResolver::class,
            RokuAudioFallbackSourceResolver::class
        )
    );

    foreach ([
        ['https://provider.example.invalid', 'mp4'],
        ['https://provider.example.invalid/', 'mov'],
        ['http://provider.example.invalid:8080/prefix', 'm4v'],
        ['https://provider.example.invalid/prefix/', 'mkv'],
    ] as [$baseUrl, $extension]) {
        $provider = new RokuAudioFallbackXtreamSourceResolverTestProvider();
        $provider->context = roku_audio_fallback_xtream_source_resolver_test_context($baseUrl);
        $resolved = (new RokuAudioFallbackXtreamSourceResolver($provider))
            ->resolve(101, 202, 'synthetic_stream_1', $extension);
        $parts = parse_url($resolved);
        roku_audio_fallback_xtream_source_resolver_test_require(
            $provider->calls === 1
            && $provider->arguments === [[101, 202]]
            && is_array($parts)
            && ($parts['scheme'] ?? null) === parse_url($baseUrl, PHP_URL_SCHEME)
            && ($parts['host'] ?? null) === 'provider.example.invalid'
            && !isset($parts['query'])
            && !isset($parts['fragment'])
            && str_ends_with($resolved, '.' . $extension)
            && substr_count($resolved, '.' . $extension) === 1
            && str_contains($resolved, '/movie/')
            && !str_contains($resolved, '//movie/')
        );
    }

    $reflection = new ReflectionClass(RokuAudioFallbackXtreamSystemContext::class);
    roku_audio_fallback_xtream_source_resolver_test_require(
        $reflection->isFinal() && $reflection->isReadOnly()
    );
    foreach ($reflection->getProperties() as $property) {
        roku_audio_fallback_xtream_source_resolver_test_require(
            $property->isPrivate() && $property->isReadOnly()
        );
    }
    foreach ([
        'getBaseUrl', 'getUsername', 'getPassword', 'toArray', 'jsonSerialize',
        '__toString', '__debugInfo', '__get', '__set',
    ] as $method) {
        roku_audio_fallback_xtream_source_resolver_test_require(
            !$reflection->hasMethod($method)
        );
    }

    foreach ([null, null] as $absentContext) {
        $provider = new RokuAudioFallbackXtreamSourceResolverTestProvider();
        $provider->context = $absentContext;
        roku_audio_fallback_xtream_source_resolver_test_error(
            static fn () => (new RokuAudioFallbackXtreamSourceResolver($provider))
                ->resolve(101, 202, 'synthetic_stream_1', 'mp4'),
            'ROKU_AUDIO_FALLBACK_SOURCE_NOT_FOUND'
        );
        roku_audio_fallback_xtream_source_resolver_test_require($provider->calls === 1);
    }

    $invalidArguments = [
        [0, 202, 'synthetic_stream_1', 'mp4'],
        [-1, 202, 'synthetic_stream_1', 'mp4'],
        [2147483648, 202, 'synthetic_stream_1', 'mp4'],
        ['101', 202, 'synthetic_stream_1', 'mp4'],
        [101.0, 202, 'synthetic_stream_1', 'mp4'],
        [true, 202, 'synthetic_stream_1', 'mp4'],
        [101, 0, 'synthetic_stream_1', 'mp4'],
        [101, -1, 'synthetic_stream_1', 'mp4'],
        [101, 2147483648, 'synthetic_stream_1', 'mp4'],
        [101, '202', 'synthetic_stream_1', 'mp4'],
        [101, 202.0, 'synthetic_stream_1', 'mp4'],
        [101, false, 'synthetic_stream_1', 'mp4'],
        [101, 202, '', 'mp4'],
        [101, 202, str_repeat('s', 513), 'mp4'],
        [101, 202, "bad\0stream", 'mp4'],
        [101, 202, "bad\rstream", 'mp4'],
        [101, 202, "bad\nstream", 'mp4'],
        [101, 202, 'https://direct.example.invalid/file', 'mp4'],
        [101, 202, '../stream', 'mp4'],
        [101, 202, 'stream/path', 'mp4'],
        [101, 202, 'stream?query', 'mp4'],
    ];
    foreach (['', '.mp4', 'MP4', 'm3u8', 'ts', 'php', 'mp4/path', 'mp4?x', 'mp4#x'] as $extension) {
        $invalidArguments[] = [101, 202, 'synthetic_stream_1', $extension];
    }
    foreach ($invalidArguments as $arguments) {
        $provider = new RokuAudioFallbackXtreamSourceResolverTestProvider();
        roku_audio_fallback_xtream_source_resolver_test_error(
            static fn () => (new RokuAudioFallbackXtreamSourceResolver($provider))
                ->resolve(...$arguments),
            'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_ARGUMENT',
            array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $arguments)
        );
        roku_audio_fallback_xtream_source_resolver_test_require($provider->calls === 0);
    }

    foreach ([
        [roku_audio_fallback_xtream_source_resolver_test_context(
            active: false
        ), 'ROKU_AUDIO_FALLBACK_SOURCE_INACTIVE'],
        [roku_audio_fallback_xtream_source_resolver_test_context(
            accessType: 'm3u'
        ), 'ROKU_AUDIO_FALLBACK_SOURCE_UNSUPPORTED'],
        [roku_audio_fallback_xtream_source_resolver_test_context(
            clienteId: 999
        ), 'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_CONTEXT'],
        [roku_audio_fallback_xtream_source_resolver_test_context(
            sistemaId: 999
        ), 'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_CONTEXT'],
    ] as [$context, $code]) {
        $provider = new RokuAudioFallbackXtreamSourceResolverTestProvider();
        $provider->context = $context;
        roku_audio_fallback_xtream_source_resolver_test_error(
            static fn () => (new RokuAudioFallbackXtreamSourceResolver($provider))
                ->resolve(101, 202, 'synthetic_stream_1', 'mp4'),
            $code
        );
    }

    foreach ([
        '', '/relative', 'ftp://provider.example.invalid',
        'https://', 'https://user@provider.example.invalid',
        'https://provider.example.invalid?q=1',
        'https://provider.example.invalid#x',
        "https://provider.example.invalid\n",
        'https://provider.example.invalid\\path',
        'https://provider%2eexample.invalid',
        'https://provider.example.invalid/../path',
        'https://provider.example.invalid:99999',
        'https://provedorá.example.invalid',
    ] as $invalidBase) {
        $provider = new RokuAudioFallbackXtreamSourceResolverTestProvider();
        $provider->context = roku_audio_fallback_xtream_source_resolver_test_context(
            baseUrl: $invalidBase
        );
        roku_audio_fallback_xtream_source_resolver_test_error(
            static fn () => (new RokuAudioFallbackXtreamSourceResolver($provider))
                ->resolve(101, 202, 'synthetic_stream_1', 'mp4'),
            'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_BASE_URL',
            [$invalidBase]
        );
    }

    foreach ([
        ['', 'synthetic-password'],
        [str_repeat('u', 1025), 'synthetic-password'],
        ["bad\nuser", 'synthetic-password'],
        ['synthetic-user', ''],
        ['synthetic-user', str_repeat('p', 1025)],
        ['synthetic-user', "bad\rpassword"],
    ] as [$username, $password]) {
        $provider = new RokuAudioFallbackXtreamSourceResolverTestProvider();
        $provider->context = roku_audio_fallback_xtream_source_resolver_test_context(
            username: $username,
            password: $password
        );
        roku_audio_fallback_xtream_source_resolver_test_error(
            static fn () => (new RokuAudioFallbackXtreamSourceResolver($provider))
                ->resolve(101, 202, 'synthetic_stream_1', 'mp4'),
            'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_CREDENTIALS',
            [$username, $password]
        );
    }

    $longProvider = new RokuAudioFallbackXtreamSourceResolverTestProvider();
    $longProvider->context = roku_audio_fallback_xtream_source_resolver_test_context(
        baseUrl: 'https://provider.example.invalid/' . str_repeat('p', 2000),
        username: str_repeat('u', 1024),
        password: str_repeat('p', 1024)
    );
    roku_audio_fallback_xtream_source_resolver_test_error(
        static fn () => (new RokuAudioFallbackXtreamSourceResolver($longProvider))
            ->resolve(101, 202, str_repeat('s', 512), 'mp4'),
        'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_URL'
    );

    $failedProvider = new RokuAudioFallbackXtreamSourceResolverTestProvider();
    $failedProvider->failure = new RuntimeException('SYNTHETIC_PROVIDER_DETAIL');
    roku_audio_fallback_xtream_source_resolver_test_error(
        static fn () => (new RokuAudioFallbackXtreamSourceResolver($failedProvider))
            ->resolve(101, 202, 'synthetic_stream_1', 'mp4'),
        'ROKU_AUDIO_FALLBACK_SOURCE_INTERNAL_FAILED',
        ['SYNTHETIC_PROVIDER_DETAIL']
    );

    $integrationProvider = new RokuAudioFallbackXtreamSourceResolverTestProvider();
    $integrationProvider->context = roku_audio_fallback_xtream_source_resolver_test_context();
    $integrationResolver = new RokuAudioFallbackXtreamSourceResolver($integrationProvider);
    $integrationStore = new RokuAudioFallbackXtreamSourceResolverTestStore();
    $integrationGateway = new RokuAudioFallbackXtreamSourceResolverTestGateway();
    $service = new RokuAudioFallbackService(
        $integrationStore,
        $integrationGateway,
        $integrationResolver,
        'TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES',
        'https://transcoder.example.invalid',
        3600,
        true,
        static fn (): int => 1785326400
    );
    $result = $service->createSession(
        101,
        202,
        'synthetic_stream_1',
        'mp4',
        'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8'
    );
    roku_audio_fallback_xtream_source_resolver_test_require(
        $result->getStatus() === 'preparing'
        && $result->getPlaybackUrl() === null
        && $integrationProvider->calls === 1
        && count($integrationGateway->createCalls) === 1
        && is_string($integrationGateway->createCalls[0][5])
    );

    $source = file_get_contents(
        dirname(__DIR__) . '/roku_audio_fallback_xtream_source_resolver.php'
    );
    roku_audio_fallback_xtream_source_resolver_test_require(is_string($source));
    foreach ([
        'getenv(', '$_ENV', '$_SERVER', 'putenv(', 'new PDO', 'pg_connect',
        'curl_init', 'stream_socket_client', 'fsockopen', 'gethostbyname',
        'dns_get_record', 'checkdnsrr', 'exec(', 'shell_exec', 'system(',
        'passthru', 'proc_open', 'popen', 'eval(', 'unserialize', 'error_log',
        'var_dump', 'print_r', 'phpinfo',
    ] as $forbidden) {
        roku_audio_fallback_xtream_source_resolver_test_require(
            !str_contains($source, $forbidden)
        );
    }

    fwrite(STDOUT, "ROKU_AUDIO_FALLBACK_XTREAM_SOURCE_RESOLVER_TEST_PASS\n");
    exit(0);
} catch (Throwable) {
    fwrite(STDOUT, "ROKU_AUDIO_FALLBACK_XTREAM_SOURCE_RESOLVER_TEST_FAIL\n");
    exit(1);
}
