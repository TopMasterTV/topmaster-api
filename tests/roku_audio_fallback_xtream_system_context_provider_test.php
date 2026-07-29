<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/roku_audio_fallback_xtream_system_context_provider.php';

final class RokuAudioFallbackXtreamProviderTestExecutor
    implements RokuAudioFallbackQueryExecutor
{
    /** @var array<string,mixed>|null */
    public ?array $row = null;
    public ?Throwable $failure = null;
    /** @var list<array{sql:string,parameters:array<string,int|string>,types:array<string,int>}> */
    public array $calls = [];

    public function fetchOne(string $sql, array $parameters, array $parameterTypes): ?array
    {
        $this->calls[] = [
            'sql' => $sql,
            'parameters' => $parameters,
            'types' => $parameterTypes,
        ];
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return $this->row;
    }
}

final class RokuAudioFallbackXtreamProviderTestStore implements RokuAudioFallbackSessionStore
{
    public function findByInternalSessionId(string $id): ?RokuAudioFallbackSessionRecord
    {
        return null;
    }

    public function findOwnedByClient(
        string $id,
        int $clienteId
    ): ?RokuAudioFallbackSessionRecord {
        return null;
    }
}

final class RokuAudioFallbackXtreamProviderTestGateway
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

function roku_audio_fallback_xtream_provider_test_require(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('TEST_FAILURE');
    }
}

function roku_audio_fallback_xtream_provider_test_error(
    callable $operation,
    string $expectedCode,
    array $forbidden = []
): void {
    try {
        $operation();
    } catch (RokuAudioFallbackXtreamSystemContextProviderException $exception) {
        roku_audio_fallback_xtream_provider_test_require(
            $exception->getMessage() === $expectedCode
        );
        foreach ($forbidden as $value) {
            roku_audio_fallback_xtream_provider_test_require(
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

/** @return array<string,mixed> */
function roku_audio_fallback_xtream_provider_test_row(array $changes = []): array
{
    return array_replace([
        'sistema_id' => '202',
        'cliente_id' => 101,
        'active' => 't',
        'access_type' => 'xtream',
        'base_url' => 'https://provider.example.invalid/prefix',
        'username' => 'TEST_ONLY_DO_NOT_USE_user',
        'password' => 'TEST_ONLY_DO_NOT_USE_password',
    ], $changes);
}

try {
    $executor = new RokuAudioFallbackXtreamProviderTestExecutor();
    $executor->row = roku_audio_fallback_xtream_provider_test_row();
    $provider = new RokuAudioFallbackQueryXtreamSystemContextProvider($executor);
    roku_audio_fallback_xtream_provider_test_require(
        $provider instanceof RokuAudioFallbackXtreamSystemContextProvider
    );
    $context = $provider->getOwnedXtreamContext(101, 202);
    roku_audio_fallback_xtream_provider_test_require(
        $context instanceof RokuAudioFallbackXtreamSystemContext
        && $context->getClienteId() === 101
        && $context->getSistemaId() === 202
        && $context->isActive()
        && $context->isXtream()
        && count($executor->calls) === 1
    );

    $call = $executor->calls[0];
    roku_audio_fallback_xtream_provider_test_require(
        str_contains($call['sql'], 'FROM public.sistemas AS s')
        && str_contains($call['sql'], 'LEFT JOIN public.modelos_sistemas AS m')
        && str_contains($call['sql'], 'ON m.id = s.modelo_id')
        && str_contains($call['sql'], 'WHERE s.id = :sistema_id')
        && str_contains($call['sql'], 'AND s.cliente_id = :cliente_id')
        && str_contains($call['sql'], 'LIMIT 1')
        && !str_contains($call['sql'], 'SELECT *')
        && substr_count($call['sql'], ':sistema_id') === 1
        && substr_count($call['sql'], ':cliente_id') === 1
    );
    foreach ([
        'nome_sistema', 'cliente.nome', 'email', 'telefone', 'token',
        'exp_date', 'vencimento', 'revendedor', 'observacoes',
    ] as $forbiddenColumn) {
        roku_audio_fallback_xtream_provider_test_require(
            !str_contains($call['sql'], $forbiddenColumn)
        );
    }
    foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'TRUNCATE ', 'ALTER ', 'DROP ', 'CREATE '] as $write) {
        roku_audio_fallback_xtream_provider_test_require(!str_contains($call['sql'], $write));
    }
    roku_audio_fallback_xtream_provider_test_require(
        $call['parameters'] === [':sistema_id' => 202, ':cliente_id' => 101]
        && $call['types'] === [
            ':sistema_id' => PDO::PARAM_INT,
            ':cliente_id' => PDO::PARAM_INT,
        ]
    );

    foreach ([
        roku_audio_fallback_xtream_provider_test_row([
            'sistema_id' => 202,
            'cliente_id' => '101',
            'active' => true,
            'base_url' => 'http://provider.example.invalid:8080',
        ]),
        roku_audio_fallback_xtream_provider_test_row(['active' => false]),
        roku_audio_fallback_xtream_provider_test_row(['active' => 'f']),
    ] as $validRow) {
        $validExecutor = new RokuAudioFallbackXtreamProviderTestExecutor();
        $validExecutor->row = $validRow;
        $validContext = (new RokuAudioFallbackQueryXtreamSystemContextProvider($validExecutor))
            ->getOwnedXtreamContext(101, 202);
        roku_audio_fallback_xtream_provider_test_require(
            $validContext instanceof RokuAudioFallbackXtreamSystemContext
        );
    }

    foreach ([null, null] as $absentRow) {
        $absentExecutor = new RokuAudioFallbackXtreamProviderTestExecutor();
        $absentExecutor->row = $absentRow;
        roku_audio_fallback_xtream_provider_test_require(
            (new RokuAudioFallbackQueryXtreamSystemContextProvider($absentExecutor))
                ->getOwnedXtreamContext(101, 202) === null
            && count($absentExecutor->calls) === 1
        );
    }

    foreach ([
        [0, 202], [-1, 202], [2147483648, 202],
        [101, 0], [101, -1], [101, 2147483648],
    ] as [$clienteId, $sistemaId]) {
        $invalidExecutor = new RokuAudioFallbackXtreamProviderTestExecutor();
        roku_audio_fallback_xtream_provider_test_error(
            static fn () => (new RokuAudioFallbackQueryXtreamSystemContextProvider(
                $invalidExecutor
            ))->getOwnedXtreamContext($clienteId, $sistemaId),
            'ROKU_AUDIO_FALLBACK_XTREAM_PROVIDER_INVALID_ARGUMENT'
        );
        roku_audio_fallback_xtream_provider_test_require($invalidExecutor->calls === []);
    }
    foreach ([
        ['101', 202], [101.0, 202], [true, 202],
        [101, '202'], [101, 202.0], [101, false],
    ] as $invalidTypes) {
        roku_audio_fallback_xtream_provider_test_error(
            static fn () => $provider->getOwnedXtreamContext(...$invalidTypes),
            'ROKU_AUDIO_FALLBACK_XTREAM_PROVIDER_INVALID_ARGUMENT',
            ['provider.example.invalid']
        );
    }

    $baseRow = roku_audio_fallback_xtream_provider_test_row();
    $invalidRows = [];
    foreach (array_keys($baseRow) as $column) {
        $missing = $baseRow;
        unset($missing[$column]);
        $invalidRows[] = $missing;
    }
    foreach ([
        ['cliente_id', ''], ['cliente_id', '+101'], ['cliente_id', '1e2'],
        ['cliente_id', ' 101'], ['cliente_id', '2147483648'],
        ['cliente_id', 101.0], ['cliente_id', true],
        ['sistema_id', '0202'], ['sistema_id', []],
        ['active', 1], ['active', 'true'], ['active', null],
        ['access_type', null], ['access_type', []],
        ['base_url', null], ['base_url', false],
        ['username', null], ['username', []],
        ['password', null], ['password', new stdClass()],
        ['cliente_id', 999], ['sistema_id', 999],
    ] as [$field, $value]) {
        $invalidRows[] = roku_audio_fallback_xtream_provider_test_row([$field => $value]);
    }
    foreach ($invalidRows as $invalidRow) {
        $invalidExecutor = new RokuAudioFallbackXtreamProviderTestExecutor();
        $invalidExecutor->row = $invalidRow;
        roku_audio_fallback_xtream_provider_test_error(
            static fn () => (new RokuAudioFallbackQueryXtreamSystemContextProvider(
                $invalidExecutor
            ))->getOwnedXtreamContext(101, 202),
            'ROKU_AUDIO_FALLBACK_XTREAM_PROVIDER_INVALID_ROW',
            [
                'provider.example.invalid', 'TEST_ONLY_DO_NOT_USE_user',
                'TEST_ONLY_DO_NOT_USE_password', 'SELECT', '101', '202',
            ]
        );
        roku_audio_fallback_xtream_provider_test_require(
            count($invalidExecutor->calls) === 1
        );
    }

    $failedExecutor = new RokuAudioFallbackXtreamProviderTestExecutor();
    $failedExecutor->failure = new RuntimeException('SYNTHETIC_DATABASE_DETAIL');
    roku_audio_fallback_xtream_provider_test_error(
        static fn () => (new RokuAudioFallbackQueryXtreamSystemContextProvider(
            $failedExecutor
        ))->getOwnedXtreamContext(101, 202),
        'ROKU_AUDIO_FALLBACK_XTREAM_PROVIDER_DATABASE_FAILED',
        ['SYNTHETIC_DATABASE_DETAIL', 'SELECT', '101', '202']
    );

    $resolverExecutor = new RokuAudioFallbackXtreamProviderTestExecutor();
    $resolverExecutor->row = roku_audio_fallback_xtream_provider_test_row();
    $resolver = new RokuAudioFallbackXtreamSourceResolver(
        new RokuAudioFallbackQueryXtreamSystemContextProvider($resolverExecutor)
    );
    $resolved = $resolver->resolve(101, 202, 'synthetic_stream_1', 'mp4');
    roku_audio_fallback_xtream_provider_test_require(
        count($resolverExecutor->calls) === 1
        && str_contains($resolved, '/movie/')
        && str_ends_with($resolved, '.mp4')
    );

    $serviceExecutor = new RokuAudioFallbackXtreamProviderTestExecutor();
    $serviceExecutor->row = roku_audio_fallback_xtream_provider_test_row();
    $serviceGateway = new RokuAudioFallbackXtreamProviderTestGateway();
    $service = new RokuAudioFallbackService(
        new RokuAudioFallbackXtreamProviderTestStore(),
        $serviceGateway,
        new RokuAudioFallbackXtreamSourceResolver(
            new RokuAudioFallbackQueryXtreamSystemContextProvider($serviceExecutor)
        ),
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
    roku_audio_fallback_xtream_provider_test_require(
        $result->getStatus() === 'preparing'
        && $result->getPlaybackUrl() === null
        && count($serviceExecutor->calls) === 1
        && count($serviceGateway->createCalls) === 1
        && is_string($serviceGateway->createCalls[0][5])
    );

    $reflection = new ReflectionClass(
        RokuAudioFallbackQueryXtreamSystemContextProvider::class
    );
    foreach ($reflection->getProperties() as $property) {
        roku_audio_fallback_xtream_provider_test_require(
            $property->isPrivate()
            && $property->getName() === 'executor'
        );
    }
    foreach (['getRow', 'getContext', 'getCredentials', 'toArray', '__get'] as $method) {
        roku_audio_fallback_xtream_provider_test_require(!$reflection->hasMethod($method));
    }

    $source = file_get_contents(
        dirname(__DIR__) . '/roku_audio_fallback_xtream_system_context_provider.php'
    );
    roku_audio_fallback_xtream_provider_test_require(is_string($source));
    foreach ([
        'new PDO', 'getenv(', '$_ENV', '$_SERVER', 'putenv(', 'pg_connect',
        'curl_init', 'stream_socket_client', 'fsockopen', 'gethostbyname',
        'dns_get_record', 'checkdnsrr', 'exec(', 'shell_exec', 'passthru',
        'proc_open', 'popen', 'eval(', 'unserialize', 'error_log', 'var_dump',
        'print_r', 'phpinfo', 'SELECT *',
    ] as $forbidden) {
        roku_audio_fallback_xtream_provider_test_require(
            !str_contains($source, $forbidden)
        );
    }

    fwrite(
        STDOUT,
        "ROKU_AUDIO_FALLBACK_XTREAM_SYSTEM_CONTEXT_PROVIDER_TEST_PASS\n"
    );
    exit(0);
} catch (Throwable) {
    fwrite(
        STDOUT,
        "ROKU_AUDIO_FALLBACK_XTREAM_SYSTEM_CONTEXT_PROVIDER_TEST_FAIL\n"
    );
    exit(1);
}
