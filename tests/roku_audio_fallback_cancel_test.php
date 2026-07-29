<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/roku_audio_fallback_cancel.php';

final class RokuAudioFallbackCancelTestOperation
    implements RokuAudioFallbackCancelEndpointOperation
{
    /** @var list<array{int,string}> */
    public array $calls = [];
    public RokuAudioFallbackServiceResult|Throwable|null $result = null;

    public function cancelSession(
        int $clienteId,
        string $internalSessionId
    ): RokuAudioFallbackServiceResult {
        $this->calls[] = [$clienteId, $internalSessionId];
        if ($this->result instanceof Throwable) {
            throw $this->result;
        }
        if (!$this->result instanceof RokuAudioFallbackServiceResult) {
            throw new RuntimeException('SYNTHETIC_PRIVATE_DETAIL');
        }
        return $this->result;
    }
}

final class RokuAudioFallbackCancelTestDependencies
    implements RokuAudioFallbackCancelEndpointDependencies
{
    public int $authCalls = 0;
    public int $operationCalls = 0;
    public int|Throwable $authentication = 101;

    public function __construct(public RokuAudioFallbackCancelTestOperation $fake)
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

    public function operation(): RokuAudioFallbackCancelEndpointOperation
    {
        $this->operationCalls++;
        return $this->fake;
    }
}

function roku_audio_fallback_cancel_test_require(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('TEST_FAILURE');
    }
}

/** @param array<string,mixed> $changes */
function roku_audio_fallback_cancel_test_body(array $changes = []): string
{
    return json_encode(array_replace([
        'internal_session_id' =>
            'raf_AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8',
    ], $changes), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function roku_audio_fallback_cancel_test_request(
    mixed $method = 'POST',
    mixed $contentType = 'application/json',
    mixed $body = null,
    mixed $contentLength = null,
    array $query = [],
    ?int &$reads = null
): RokuAudioFallbackCancelEndpointRequest {
    $body ??= roku_audio_fallback_cancel_test_body();
    return new RokuAudioFallbackCancelEndpointRequest(
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

function roku_audio_fallback_cancel_test_subject(
    ?RokuAudioFallbackCancelTestOperation &$operation = null,
    ?RokuAudioFallbackCancelTestDependencies &$dependencies = null,
    string $status = 'cancelled',
    ?string $playbackUrl = null,
    ?string $id = null,
    string $expiresAt = '2026-07-29T13:00:00Z'
): RokuAudioFallbackCancelEndpointHandler {
    $id ??= 'raf_AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';
    $operation = new RokuAudioFallbackCancelTestOperation();
    $operation->result = new RokuAudioFallbackServiceResult(
        $id,
        $status,
        $expiresAt,
        $playbackUrl
    );
    $dependencies = new RokuAudioFallbackCancelTestDependencies($operation);
    return new RokuAudioFallbackCancelEndpointHandler();
}

try {
    $id = 'raf_AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';
    $handler = roku_audio_fallback_cancel_test_subject($operation, $dependencies);
    $response = $handler->handle(
        roku_audio_fallback_cancel_test_request(),
        $dependencies
    );
    roku_audio_fallback_cancel_test_require(
        $response->getStatusHttp() === 200
        && $response->getBody() === [
            'ok' => true,
            'session' => [
                'id' => $id,
                'status' => 'cancelled',
                'expires_at' => '2026-07-29T13:00:00Z',
            ],
        ]
        && $operation->calls === [[101, $id]]
        && $dependencies->authCalls === 1
        && $dependencies->operationCalls === 1
    );
    $baseBody = roku_audio_fallback_cancel_test_body();
    $bodyAtLimit = $baseBody . str_repeat(' ', 16384 - strlen($baseBody));
    $handler = roku_audio_fallback_cancel_test_subject($operation, $dependencies);
    roku_audio_fallback_cancel_test_require(
        strlen($bodyAtLimit) === 16384
        && $handler->handle(
            roku_audio_fallback_cancel_test_request(
                'POST',
                'application/json',
                $bodyAtLimit
            ),
            $dependencies
        )->getStatusHttp() === 200
    );

    foreach (['GET', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', '', null] as $method) {
        $handler = roku_audio_fallback_cancel_test_subject($operation, $dependencies);
        $reads = 0;
        $response = $handler->handle(
            roku_audio_fallback_cancel_test_request(
                $method,
                'application/json',
                null,
                null,
                [],
                $reads
            ),
            $dependencies
        );
        roku_audio_fallback_cancel_test_require(
            $response->getStatusHttp() === 405
            && in_array('Allow: POST', $response->getHeaders(), true)
            && $reads === 0
            && $dependencies->authCalls === 0
            && $dependencies->operationCalls === 0
        );
    }
    foreach ([
        null, '', 'text/plain', 'application/x-www-form-urlencoded',
        'multipart/form-data', 'application/json,text/plain',
        'application/json; charset=latin1',
    ] as $contentType) {
        $handler = roku_audio_fallback_cancel_test_subject($operation, $dependencies);
        $reads = 0;
        $response = $handler->handle(
            roku_audio_fallback_cancel_test_request(
                'POST',
                $contentType,
                null,
                null,
                [],
                $reads
            ),
            $dependencies
        );
        roku_audio_fallback_cancel_test_require(
            $response->getStatusHttp() === 415
            && $reads === 0
            && $dependencies->authCalls === 0
        );
    }
    foreach ([
        'application/json', 'Application/JSON',
        'application/json; charset=utf-8', 'application/json;charset="utf-8"',
    ] as $contentType) {
        $handler = roku_audio_fallback_cancel_test_subject($operation, $dependencies);
        roku_audio_fallback_cancel_test_require(
            $handler->handle(
                roku_audio_fallback_cancel_test_request('POST', $contentType),
                $dependencies
            )->getStatusHttp() === 200
        );
    }

    $invalidBodies = [
        ['', 400], [' ', 400], ['{', 400], ['[]', 400], ['"x"', 400],
        ['1', 400], ['null', 400], [str_repeat('x', 16385), 413],
        ['{}', 400],
        ['{"internal_session_id":"' . $id . '","extra":1}', 400],
        ['{"internal_session_id":"' . $id . '","internal_session_id":"' . $id
            . '"}', 400],
        [str_repeat('[', 20) . '0' . str_repeat(']', 20), 400],
    ];
    foreach ($invalidBodies as [$body, $status]) {
        $handler = roku_audio_fallback_cancel_test_subject($operation, $dependencies);
        $response = $handler->handle(
            roku_audio_fallback_cancel_test_request('POST', 'application/json', $body),
            $dependencies
        );
        roku_audio_fallback_cancel_test_require(
            $response->getStatusHttp() === $status
            && $dependencies->authCalls === 0
            && $operation->calls === []
        );
    }
    $handler = roku_audio_fallback_cancel_test_subject($operation, $dependencies);
    roku_audio_fallback_cancel_test_require(
        $handler->handle(
            roku_audio_fallback_cancel_test_request(
                'POST',
                'application/json',
                null,
                null,
                ['cliente_id' => '101']
            ),
            $dependencies
        )->getStatusHttp() === 400
        && $dependencies->authCalls === 0
    );

    foreach ([
        'request_id', 'cliente_id', 'sistema_id', 'stream_id', 'extension',
        'source_url', 'url', 'username', 'password', 'token', 'public_token',
        'public_token_hash', 'Authorization', 'fallback_kind', 'status',
        'playback_url', 'expires_at',
    ] as $key) {
        $handler = roku_audio_fallback_cancel_test_subject($operation, $dependencies);
        $response = $handler->handle(
            roku_audio_fallback_cancel_test_request(
                'POST',
                'application/json',
                roku_audio_fallback_cancel_test_body([$key => 'TEST_ONLY'])
            ),
            $dependencies
        );
        roku_audio_fallback_cancel_test_require(
            $response->getStatusHttp() === 400
            && $dependencies->authCalls === 0
            && $operation->calls === []
        );
    }

    foreach ([
        '', substr($id, 4), 'bad_' . substr($id, 4),
        'raf_' . str_repeat('A', 42), 'raf_' . str_repeat('A', 44),
        $id . '=', substr($id, 0, -1) . '*', substr($id, 0, -1) . ' ',
        "raf_AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwd\0h8",
        "raf_AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwd\rh8",
        "raf_AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwd\nh8",
        'https://example.invalid/x', 'raf/path', 123,
    ] as $invalidId) {
        $handler = roku_audio_fallback_cancel_test_subject($operation, $dependencies);
        $response = $handler->handle(
            roku_audio_fallback_cancel_test_request(
                'POST',
                'application/json',
                roku_audio_fallback_cancel_test_body([
                    'internal_session_id' => $invalidId,
                ])
            ),
            $dependencies
        );
        roku_audio_fallback_cancel_test_require(
            $response->getStatusHttp() === 400
            && $dependencies->authCalls === 0
            && $operation->calls === []
        );
    }

    foreach ([0, -1, 2147483648] as $invalidClient) {
        $handler = roku_audio_fallback_cancel_test_subject($operation, $dependencies);
        $dependencies->authentication = $invalidClient;
        $response = $handler->handle(
            roku_audio_fallback_cancel_test_request(),
            $dependencies
        );
        roku_audio_fallback_cancel_test_require(
            $response->getStatusHttp() === 500
            && $dependencies->operationCalls === 0
        );
    }
    foreach ([
        [401, 'AUTH_REQUIRED'], [401, 'INVALID_TOKEN'], [403, 'CLIENT_INACTIVE'],
    ] as [$http, $code]) {
        $handler = roku_audio_fallback_cancel_test_subject($operation, $dependencies);
        $dependencies->authentication =
            new RokuAudioFallbackCancelEndpointException($http, $code);
        roku_audio_fallback_cancel_test_require(
            $handler->handle(
                roku_audio_fallback_cancel_test_request(),
                $dependencies
            )->getStatusHttp() === $http
            && $dependencies->operationCalls === 0
        );
    }

    foreach (['cancelled', 'preparing', 'ready', 'failed', 'expired'] as $status) {
        $handler = roku_audio_fallback_cancel_test_subject(
            $operation,
            $dependencies,
            $status
        );
        $response = $handler->handle(
            roku_audio_fallback_cancel_test_request(),
            $dependencies
        );
        $session = $response->getBody()['session'];
        roku_audio_fallback_cancel_test_require(
            $response->getStatusHttp() === 200
            && array_keys($session) === ['id', 'status', 'expires_at']
            && $operation->calls === [[101, $id]]
            && !array_key_exists('playback_url', $session)
            && !array_key_exists('request_id', $session)
        );
    }

    foreach ([
        ['unknown', null, $id, '2026-07-29T13:00:00Z'],
        ['cancelled', 'https://public.example.invalid/x', $id,
            '2026-07-29T13:00:00Z'],
        ['cancelled', null, 'raf_' . str_repeat('B', 43),
            '2026-07-29T13:00:00Z'],
        ['cancelled', null, $id, 'invalid'],
    ] as [$status, $url, $resultId, $expires]) {
        $handler = roku_audio_fallback_cancel_test_subject(
            $operation,
            $dependencies,
            $status,
            $url,
            $resultId,
            $expires
        );
        $response = $handler->handle(
            roku_audio_fallback_cancel_test_request(),
            $dependencies
        );
        roku_audio_fallback_cancel_test_require(
            $response->getStatusHttp() === 500
            && $response->getBody() === [
                'ok' => false,
                'error' => ['code' => 'INTERNAL_ERROR'],
            ]
        );
    }
    $handler = roku_audio_fallback_cancel_test_subject($operation, $dependencies);
    $operation->result = null;
    roku_audio_fallback_cancel_test_require(
        $handler->handle(
            roku_audio_fallback_cancel_test_request(),
            $dependencies
        )->getStatusHttp() === 500
    );

    $errorCases = [
        'ROKU_AUDIO_FALLBACK_INVALID_ARGUMENT' => [400, 'INVALID_REQUEST'],
        'ROKU_AUDIO_FALLBACK_NOT_FOUND' => [404, 'FALLBACK_SESSION_NOT_FOUND'],
        'ROKU_AUDIO_FALLBACK_CONFLICT' => [409, 'FALLBACK_CONFLICT'],
        'ROKU_AUDIO_FALLBACK_DISABLED' => [503, 'FALLBACK_DISABLED'],
        'ROKU_AUDIO_FALLBACK_CAPACITY_EXCEEDED' => [429, 'FALLBACK_CAPACITY_EXCEEDED'],
        'ROKU_AUDIO_FALLBACK_TRANSCODER_UNAVAILABLE' => [503, 'FALLBACK_UNAVAILABLE'],
        'ROKU_AUDIO_FALLBACK_RESULT_INDETERMINATE' => [503, 'FALLBACK_UNAVAILABLE'],
        'ROKU_AUDIO_FALLBACK_UPSTREAM_INVALID_RESPONSE' => [500, 'INTERNAL_ERROR'],
        'ROKU_AUDIO_FALLBACK_INTERNAL_FAILED' => [500, 'INTERNAL_ERROR'],
    ];
    foreach ($errorCases as $serviceCode => [$http, $publicCode]) {
        $handler = roku_audio_fallback_cancel_test_subject($operation, $dependencies);
        $operation->result = new RokuAudioFallbackServiceException($serviceCode);
        $response = $handler->handle(
            roku_audio_fallback_cancel_test_request(),
            $dependencies
        );
        $encoded = json_encode($response->getBody(), JSON_THROW_ON_ERROR);
        roku_audio_fallback_cancel_test_require(
            $response->getStatusHttp() === $http
            && $response->getBody() === [
                'ok' => false,
                'error' => ['code' => $publicCode],
            ]
            && !str_contains($encoded, $serviceCode)
            && !str_contains($encoded, $id)
        );
    }
    for ($case = 0; $case < 2; $case++) {
        $handler = roku_audio_fallback_cancel_test_subject($operation, $dependencies);
        $operation->result = new RokuAudioFallbackServiceException(
            'ROKU_AUDIO_FALLBACK_NOT_FOUND'
        );
        $response = $handler->handle(
            roku_audio_fallback_cancel_test_request(),
            $dependencies
        );
        roku_audio_fallback_cancel_test_require(
            $response->getStatusHttp() === 404
            && $response->getBody()['error']['code'] ===
                'FALLBACK_SESSION_NOT_FOUND'
        );
    }

    $source = file_get_contents(dirname(__DIR__) . '/roku_audio_fallback_cancel.php');
    roku_audio_fallback_cancel_test_require(is_string($source));
    foreach ([
        'new PDO', 'DATABASE_URL', 'pgsql:', 'pg_connect', '.env', 'putenv',
        'curl_init', 'hash_hmac', 'validarRequestId', '::derivar(',
        'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE FROM',
        'findByInternalSessionId', 'gethostbyname', 'dns_get_record',
        'checkdnsrr', 'exec(', 'system(', 'proc_open', 'eval(', 'unserialize',
        'var_dump', 'print_r', 'phpinfo',
    ] as $forbidden) {
        roku_audio_fallback_cancel_test_require(!str_contains($source, $forbidden));
    }
    roku_audio_fallback_cancel_test_require(
        substr_count($source, 'autenticarTokenRoku(') === 1
        && substr_count($source, 'RokuAudioFallbackProductionBootstrap') === 1
        && str_contains($source, 'realpath($script) === __FILE__')
    );

    fwrite(STDOUT, "ROKU_AUDIO_FALLBACK_CANCEL_ENDPOINT_TEST_PASS\n");
    exit(0);
} catch (Throwable) {
    fwrite(STDOUT, "ROKU_AUDIO_FALLBACK_CANCEL_ENDPOINT_TEST_FAIL\n");
    exit(1);
}
