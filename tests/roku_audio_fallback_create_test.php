<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/roku_audio_fallback_create.php';

final class RokuAudioFallbackCreateTestOperation
    implements RokuAudioFallbackCreateEndpointOperation
{
    /** @var list<array{int,int,string,string,string}> */
    public array $calls = [];
    public RokuAudioFallbackServiceResult|Throwable|null $result = null;

    public function createSession(
        int $clienteId,
        int $sistemaId,
        string $streamId,
        string $extension,
        string $requestId
    ): RokuAudioFallbackServiceResult {
        $this->calls[] = [
            $clienteId, $sistemaId, $streamId, $extension, $requestId,
        ];
        if ($this->result instanceof Throwable) {
            throw $this->result;
        }
        if (!$this->result instanceof RokuAudioFallbackServiceResult) {
            throw new RuntimeException('SYNTHETIC_INTERNAL_DETAIL');
        }
        return $this->result;
    }
}

final class RokuAudioFallbackCreateTestDependencies
    implements RokuAudioFallbackCreateEndpointDependencies
{
    public int $authCalls = 0;
    public int $operationCalls = 0;
    public int|Throwable $authentication = 101;

    public function __construct(public RokuAudioFallbackCreateTestOperation $fake)
    {
    }

    public function authenticate(): int
    {
        $this->authCalls++;
        if ($this->authentication instanceof Throwable) {
            throw $this->authentication;
        }
        return $this->authentication;
    }

    public function operation(): RokuAudioFallbackCreateEndpointOperation
    {
        $this->operationCalls++;
        return $this->fake;
    }
}

function roku_audio_fallback_create_test_require(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('TEST_FAILURE');
    }
}

/** @param array<string,mixed> $changes */
function roku_audio_fallback_create_test_body(array $changes = []): string
{
    return json_encode(array_replace([
        'sistema_id' => 202,
        'stream_id' => 'synthetic_stream_1',
        'extension' => 'mp4',
        'request_id' => 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8',
    ], $changes), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

function roku_audio_fallback_create_test_request(
    mixed $method = 'POST',
    mixed $contentType = 'application/json',
    mixed $body = null,
    mixed $contentLength = null,
    array $query = [],
    ?int &$reads = null
): RokuAudioFallbackCreateEndpointRequest {
    $body ??= roku_audio_fallback_create_test_body();
    return new RokuAudioFallbackCreateEndpointRequest(
        $method,
        $contentType,
        $contentLength,
        $query,
        static function () use ($body, &$reads): mixed {
            if ($reads !== null) {
                $reads++;
            }
            return $body;
        }
    );
}

function roku_audio_fallback_create_test_subject(
    ?RokuAudioFallbackCreateTestOperation &$operation = null,
    ?RokuAudioFallbackCreateTestDependencies &$dependencies = null,
    string $status = 'preparing',
    ?string $playbackUrl = null
): RokuAudioFallbackCreateEndpointHandler {
    $operation = new RokuAudioFallbackCreateTestOperation();
    $operation->result = new RokuAudioFallbackServiceResult(
        'raf_TEST_ONLY_PUBLIC_SESSION_ID_1234567890123',
        $status,
        '2026-07-29T13:00:00Z',
        $playbackUrl
    );
    $dependencies = new RokuAudioFallbackCreateTestDependencies($operation);
    return new RokuAudioFallbackCreateEndpointHandler();
}

try {
    $handler = roku_audio_fallback_create_test_subject($operation, $dependencies);
    $requestId = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';
    $response = $handler->handle(
        roku_audio_fallback_create_test_request(),
        $dependencies
    );
    roku_audio_fallback_create_test_require(
        $response->getStatusHttp() === 202
        && $response->getBody()['ok'] === true
        && array_keys($response->getBody()['session']) === [
            'id', 'status', 'expires_at',
        ]
        && $operation->calls === [[101, 202, 'synthetic_stream_1', 'mp4', $requestId]]
        && $dependencies->authCalls === 1
        && $dependencies->operationCalls === 1
    );
    $handler = roku_audio_fallback_create_test_subject($operation, $dependencies);
    $reordered = '{"request_id":"' . $requestId . '","extension":"mp4",'
        . '"stream_id":"synthetic_stream_1","sistema_id":202}';
    roku_audio_fallback_create_test_require(
        $handler->handle(
            roku_audio_fallback_create_test_request(
                'POST',
                'application/json',
                $reordered
            ),
            $dependencies
        )->getStatusHttp() === 202
    );

    foreach (['GET', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', '', null] as $method) {
        $handler = roku_audio_fallback_create_test_subject($operation, $dependencies);
        $reads = 0;
        $response = $handler->handle(
            roku_audio_fallback_create_test_request(
                $method,
                'application/json',
                null,
                null,
                [],
                $reads
            ),
            $dependencies
        );
        roku_audio_fallback_create_test_require(
            $response->getStatusHttp() === 405
            && in_array('Allow: POST', $response->getHeaders(), true)
            && $reads === 0
            && $dependencies->authCalls === 0
            && $dependencies->operationCalls === 0
            && $operation->calls === []
        );
    }

    foreach ([
        null, '', 'text/plain', 'application/x-www-form-urlencoded',
        'multipart/form-data', 'application/json,text/plain',
        'application/json; charset=latin1',
    ] as $contentType) {
        $handler = roku_audio_fallback_create_test_subject($operation, $dependencies);
        $reads = 0;
        $response = $handler->handle(
            roku_audio_fallback_create_test_request(
                'POST',
                $contentType,
                null,
                null,
                [],
                $reads
            ),
            $dependencies
        );
        roku_audio_fallback_create_test_require(
            $response->getStatusHttp() === 415
            && $reads === 0
            && $dependencies->authCalls === 0
        );
    }
    foreach ([
        'application/json',
        'Application/JSON',
        'application/json; charset=utf-8',
        'application/json;charset="utf-8"',
    ] as $contentType) {
        $handler = roku_audio_fallback_create_test_subject($operation, $dependencies);
        roku_audio_fallback_create_test_require(
            $handler->handle(
                roku_audio_fallback_create_test_request('POST', $contentType),
                $dependencies
            )->getStatusHttp() === 202
        );
    }

    $invalidBodies = [
        ['', 400], ['   ', 400], ['{', 400], ['[]', 400], ['"x"', 400],
        ['1', 400], ['null', 400], [str_repeat('x', 16385), 413],
        ['{"sistema_id":202,"stream_id":"x","extension":"mp4"}', 400],
        ['{"sistema_id":202,"stream_id":"x","extension":"mp4","request_id":"'
            . $requestId . '","extra":1}', 400],
        ['{"sistema_id":202,"sistema_id":203,"stream_id":"x","extension":"mp4",'
            . '"request_id":"' . $requestId . '"}', 400],
        ["{\"sistema_id\":202,\"stream_id\":\"x\\u0000\",\"extension\":\"mp4\","
            . "\"request_id\":\"$requestId\"}", 400],
    ];
    foreach ($invalidBodies as [$body, $status]) {
        $handler = roku_audio_fallback_create_test_subject($operation, $dependencies);
        $response = $handler->handle(
            roku_audio_fallback_create_test_request('POST', 'application/json', $body),
            $dependencies
        );
        roku_audio_fallback_create_test_require(
            $response->getStatusHttp() === $status
            && $dependencies->authCalls === 0
            && $operation->calls === []
        );
    }

    foreach ([
        ['cliente_id', 999],
        ['source_url', 'https://source.example.invalid'],
        ['url', 'https://source.example.invalid'],
        ['username', 'TEST_ONLY'],
        ['password', 'TEST_ONLY'],
        ['token', 'TEST_ONLY'],
        ['public_token_hash', str_repeat('a', 64)],
    ] as [$key, $value]) {
        $handler = roku_audio_fallback_create_test_subject($operation, $dependencies);
        $response = $handler->handle(
            roku_audio_fallback_create_test_request(
                'POST',
                'application/json',
                roku_audio_fallback_create_test_body([$key => $value])
            ),
            $dependencies
        );
        roku_audio_fallback_create_test_require(
            $response->getStatusHttp() === 400
            && $dependencies->authCalls === 0
            && $operation->calls === []
        );
    }

    $fieldCases = [
        ['sistema_id', [0, -1, 2147483648, '202', 202.0, true, null]],
        ['stream_id', [
            '', str_repeat('s', 513), "a\0b", "a\rb", "a\nb",
            'https://source.example.invalid', 'a/b', 'a\\b', 'a?b', 'a#b',
            '..', 123,
        ]],
        ['extension', ['MP4', '.mp4', 'm3u8', 'ts', '', 'a/mp4', 'mp4?x', 1]],
        ['request_id', [
            '', str_repeat('A', 42), str_repeat('A', 44),
            substr($requestId, 0, 42) . '=', substr($requestId, 0, 42) . '*',
            substr($requestId, 0, 42) . ' ', 1,
        ]],
    ];
    foreach ($fieldCases as [$field, $values]) {
        foreach ($values as $value) {
            $handler = roku_audio_fallback_create_test_subject($operation, $dependencies);
            $response = $handler->handle(
                roku_audio_fallback_create_test_request(
                    'POST',
                    'application/json',
                    roku_audio_fallback_create_test_body([$field => $value])
                ),
                $dependencies
            );
            roku_audio_fallback_create_test_require(
                $response->getStatusHttp() === 400
                && $dependencies->authCalls === 0
                && $operation->calls === []
            );
        }
    }
    foreach (['mp4', 'mov', 'm4v', 'mkv'] as $extension) {
        $handler = roku_audio_fallback_create_test_subject($operation, $dependencies);
        $response = $handler->handle(
            roku_audio_fallback_create_test_request(
                'POST',
                'application/json',
                roku_audio_fallback_create_test_body(['extension' => $extension])
            ),
            $dependencies
        );
        roku_audio_fallback_create_test_require(
            $response->getStatusHttp() === 202
            && $operation->calls[0][3] === $extension
        );
    }

    foreach ([0, -1, 2147483648] as $invalidClient) {
        $handler = roku_audio_fallback_create_test_subject($operation, $dependencies);
        $dependencies->authentication = $invalidClient;
        $response = $handler->handle(
            roku_audio_fallback_create_test_request(),
            $dependencies
        );
        roku_audio_fallback_create_test_require(
            $response->getStatusHttp() === 500
            && $dependencies->operationCalls === 0
            && $operation->calls === []
        );
    }
    $handler = roku_audio_fallback_create_test_subject($operation, $dependencies);
    $dependencies->authentication = new RokuAudioFallbackCreateEndpointException(
        401,
        'AUTH_REQUIRED'
    );
    roku_audio_fallback_create_test_require(
        $handler->handle(
            roku_audio_fallback_create_test_request(),
            $dependencies
        )->getStatusHttp() === 401
        && $dependencies->operationCalls === 0
    );

    foreach ([
        ['preparing', null, 202],
        ['ready', 'https://public.example.invalid/media/test/index.m3u8', 200],
        ['cancelled', null, 200],
        ['failed', null, 200],
        ['expired', null, 200],
    ] as [$status, $url, $http]) {
        $handler = roku_audio_fallback_create_test_subject(
            $operation,
            $dependencies,
            $status,
            $url
        );
        $response = $handler->handle(
            roku_audio_fallback_create_test_request(),
            $dependencies
        );
        $session = $response->getBody()['session'];
        roku_audio_fallback_create_test_require(
            $response->getStatusHttp() === $http
            && array_keys($session) === (
                $url === null
                    ? ['id', 'status', 'expires_at']
                    : ['id', 'status', 'expires_at', 'playback_url']
            )
            && !array_key_exists('request_id', $session)
            && !array_key_exists('cliente_id', $session)
            && !array_key_exists('public_token_hash', $session)
        );
    }

    $errorCases = [
        'ROKU_AUDIO_FALLBACK_INVALID_ARGUMENT' => [400, 'INVALID_REQUEST'],
        'ROKU_AUDIO_FALLBACK_DISABLED' => [503, 'FALLBACK_DISABLED'],
        'ROKU_AUDIO_FALLBACK_NOT_FOUND' => [404, 'FALLBACK_SOURCE_NOT_FOUND'],
        'ROKU_AUDIO_FALLBACK_SOURCE_FAILED' => [404, 'FALLBACK_SOURCE_NOT_FOUND'],
        'ROKU_AUDIO_FALLBACK_CONFLICT' => [409, 'FALLBACK_CONFLICT'],
        'ROKU_AUDIO_FALLBACK_CAPACITY_EXCEEDED' => [429, 'FALLBACK_CAPACITY_EXCEEDED'],
        'ROKU_AUDIO_FALLBACK_TRANSCODER_UNAVAILABLE' => [503, 'FALLBACK_UNAVAILABLE'],
        'ROKU_AUDIO_FALLBACK_RESULT_INDETERMINATE' => [503, 'FALLBACK_UNAVAILABLE'],
        'ROKU_AUDIO_FALLBACK_UPSTREAM_REJECTED' => [409, 'FALLBACK_UPSTREAM_REJECTED'],
        'ROKU_AUDIO_FALLBACK_UPSTREAM_INVALID_RESPONSE' => [500, 'INTERNAL_ERROR'],
        'ROKU_AUDIO_FALLBACK_INTERNAL_FAILED' => [500, 'INTERNAL_ERROR'],
    ];
    foreach ($errorCases as $serviceCode => [$http, $publicCode]) {
        $handler = roku_audio_fallback_create_test_subject($operation, $dependencies);
        $operation->result = new RokuAudioFallbackServiceException($serviceCode);
        $response = $handler->handle(
            roku_audio_fallback_create_test_request(),
            $dependencies
        );
        $encoded = json_encode($response->getBody(), JSON_THROW_ON_ERROR);
        roku_audio_fallback_create_test_require(
            $response->getStatusHttp() === $http
            && $response->getBody() === [
                'ok' => false,
                'error' => ['code' => $publicCode],
            ]
            && !str_contains($encoded, $serviceCode)
            && !str_contains($encoded, $requestId)
        );
    }
    $handler = roku_audio_fallback_create_test_subject($operation, $dependencies);
    $operation->result = new RuntimeException('SYNTHETIC_PRIVATE_DETAIL');
    $response = $handler->handle(
        roku_audio_fallback_create_test_request(),
        $dependencies
    );
    roku_audio_fallback_create_test_require(
        $response->getStatusHttp() === 500
        && !str_contains(
            json_encode($response->getBody(), JSON_THROW_ON_ERROR),
            'SYNTHETIC_PRIVATE_DETAIL'
        )
    );

    $source = file_get_contents(dirname(__DIR__) . '/roku_audio_fallback_create.php');
    roku_audio_fallback_create_test_require(is_string($source));
    foreach ([
        'new PDO', 'DATABASE_URL', 'pgsql:', 'pg_connect', '.env', 'putenv',
        'curl_init', 'hash_hmac', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE FROM',
        'gethostbyname', 'dns_get_record', 'checkdnsrr', 'exec(', 'system(',
        'proc_open', 'eval(', 'unserialize', 'var_dump', 'print_r', 'phpinfo',
    ] as $forbidden) {
        roku_audio_fallback_create_test_require(!str_contains($source, $forbidden));
    }
    roku_audio_fallback_create_test_require(
        substr_count($source, 'autenticarTokenRoku(') === 1
        && substr_count($source, 'RokuAudioFallbackProductionBootstrap') === 1
        && str_contains($source, 'realpath($script) === __FILE__')
    );

    fwrite(STDOUT, "ROKU_AUDIO_FALLBACK_CREATE_ENDPOINT_TEST_PASS\n");
    exit(0);
} catch (Throwable) {
    fwrite(STDOUT, "ROKU_AUDIO_FALLBACK_CREATE_ENDPOINT_TEST_FAIL\n");
    exit(1);
}
