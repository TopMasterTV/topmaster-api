<?php

declare(strict_types=1);

ob_start();
require_once dirname(__DIR__) . '/roku_audio_fallback_create.php';
require_once dirname(__DIR__) . '/roku_audio_fallback_status.php';
require_once dirname(__DIR__) . '/roku_audio_fallback_cancel.php';
$includeOutput = ob_get_clean();

final class RokuAudioFallbackEndpointsIntegrationState
{
    /** @var array<string,array<string,mixed>> */
    public array $sessions = [];
    public int $contextQueries = 0;
    public int $sessionQueries = 0;
    public int $createRequests = 0;
    public int $statusRequests = 0;
    public int $cancelRequests = 0;
    public int $sourceUrlsObserved = 0;
    public int $nextDatabaseId = 9001;
    public int $nextAttempt = 1;
}

final class RokuAudioFallbackEndpointsIntegrationExecutor
    implements RokuAudioFallbackQueryExecutor
{
    public function __construct(
        private readonly RokuAudioFallbackEndpointsIntegrationState $state
    ) {
    }

    public function fetchOne(
        string $sql,
        array $parameters,
        array $parameterTypes
    ): ?array {
        if (str_contains($sql, 'FROM public.sistemas AS s')) {
            $this->state->contextQueries++;
            if (
                $parameters !== [
                    ':sistema_id' => 202,
                    ':cliente_id' => 101,
                ]
                || $parameterTypes !== [
                    ':sistema_id' => PDO::PARAM_INT,
                    ':cliente_id' => PDO::PARAM_INT,
                ]
            ) {
                return null;
            }
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

        if (!str_contains($sql, 'FROM public.roku_audio_fallback_sessions')) {
            throw new RuntimeException('TEST_FAILURE');
        }
        $this->state->sessionQueries++;
        $id = $parameters[':internal_session_id'] ?? null;
        if (!is_string($id) || !isset($this->state->sessions[$id])) {
            return null;
        }
        $row = $this->state->sessions[$id];
        if (
            array_key_exists(':cliente_id', $parameters)
            && $parameters[':cliente_id'] !== $row['cliente_id']
        ) {
            return null;
        }
        return $row;
    }
}

final class RokuAudioFallbackEndpointsIntegrationTransport
    implements RokuTranscoderHttpTransport
{
    public function __construct(
        private readonly RokuAudioFallbackEndpointsIntegrationState $state
    ) {
    }

    public function send(RokuTranscoderHttpRequest $request): RokuTranscoderHttpResponse
    {
        if ($request->method === 'POST') {
            return $this->create($request);
        }
        if ($request->method === 'GET') {
            return $this->status($request);
        }
        if ($request->method === 'DELETE') {
            return $this->cancel($request);
        }
        throw new RuntimeException('TEST_FAILURE');
    }

    private function create(
        RokuTranscoderHttpRequest $request
    ): RokuTranscoderHttpResponse {
        $this->state->createRequests++;
        $payload = json_decode($request->body, true, 32, JSON_THROW_ON_ERROR);
        if (
            !is_array($payload)
            || !is_string($payload['internal_session_id'] ?? null)
            || !is_string($payload['public_token_hash'] ?? null)
            || !is_string($payload['source_url'] ?? null)
        ) {
            throw new RuntimeException('TEST_FAILURE');
        }
        $this->state->sourceUrlsObserved++;
        $id = $payload['internal_session_id'];
        if (!isset($this->state->sessions[$id])) {
            $this->state->sessions[$id] = [
                'id' => (string) $this->state->nextDatabaseId++,
                'internal_session_id' => $id,
                'public_token_hash' => $payload['public_token_hash'],
                'cliente_id' => $payload['cliente_id'],
                'sistema_id' => $payload['sistema_id'],
                'stream_id' => $payload['stream_id'],
                'extensao_sanitizada' => $payload['extension'],
                'status' => 'created',
                'fallback_kind' => 'vod_audio_stereo',
                'created_at' => '2026-07-29T12:00:00Z',
                'expires_at' => $payload['expires_at'],
                'last_access_at' => '2026-07-29T12:00:01Z',
                'started_at' => null,
                'ready_at' => null,
                'finished_at' => null,
                'cancelled_at' => null,
                'failure_code' => null,
                'tentativa' => (string) $this->state->nextAttempt++,
            ];
        }
        return $this->response($id, 'created', 202);
    }

    private function status(
        RokuTranscoderHttpRequest $request
    ): RokuTranscoderHttpResponse {
        $this->state->statusRequests++;
        $id = $this->idFromUrl($request->url, true);
        if (!isset($this->state->sessions[$id])) {
            throw new RuntimeException('TEST_FAILURE');
        }
        $current = $this->state->sessions[$id]['status'];
        if ($current !== 'cancelled') {
            $current = $this->state->statusRequests === 1 ? 'preparing' : 'ready';
            $this->state->sessions[$id]['status'] = $current;
        }
        return $this->response($id, $current, 200);
    }

    private function cancel(
        RokuTranscoderHttpRequest $request
    ): RokuTranscoderHttpResponse {
        $this->state->cancelRequests++;
        $id = $this->idFromUrl($request->url, false);
        if (!isset($this->state->sessions[$id])) {
            throw new RuntimeException('TEST_FAILURE');
        }
        $this->state->sessions[$id]['status'] = 'cancelled';
        $this->state->sessions[$id]['cancelled_at'] = '2026-07-29T12:10:00Z';
        return $this->response($id, 'cancelled', 200);
    }

    private function idFromUrl(string $url, bool $status): string
    {
        $pattern = $status
            ? '#/internal/sessions/([A-Za-z0-9_-]+)/status\z#D'
            : '#/internal/sessions/([A-Za-z0-9_-]+)\z#D';
        if (preg_match($pattern, $url, $matches) !== 1) {
            throw new RuntimeException('TEST_FAILURE');
        }
        return $matches[1];
    }

    private function response(
        string $id,
        string $status,
        int $httpStatus
    ): RokuTranscoderHttpResponse {
        $row = $this->state->sessions[$id];
        $body = json_encode([
            'ok' => true,
            'session' => [
                'id' => $id,
                'status' => $status,
                'created_at' => $row['created_at'],
                'expires_at' => $row['expires_at'],
                'last_access_at' => $row['last_access_at'],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return new RokuTranscoderHttpResponse(
            $httpStatus,
            'application/json',
            $body
        );
    }
}

final class RokuAudioFallbackEndpointsIntegrationClock
{
    public int $calls = 0;

    public function __construct(private int $timestamp)
    {
    }

    public function __invoke(): int
    {
        $this->calls++;
        return $this->timestamp;
    }

    public function advance(int $seconds): void
    {
        $this->timestamp += $seconds;
    }
}

final class RokuAudioFallbackEndpointsIntegrationOperation implements
    RokuAudioFallbackCreateEndpointOperation,
    RokuAudioFallbackStatusEndpointOperation,
    RokuAudioFallbackCancelEndpointOperation
{
    public function __construct(private readonly RokuAudioFallbackService $service)
    {
    }

    public function createSession(
        int $clienteId,
        int $sistemaId,
        string $streamId,
        string $extension,
        string $requestId
    ): RokuAudioFallbackServiceResult {
        return $this->service->createSession(
            $clienteId,
            $sistemaId,
            $streamId,
            $extension,
            $requestId
        );
    }

    public function getStatus(
        int $clienteId,
        string $internalSessionId,
        string $requestId
    ): RokuAudioFallbackServiceResult {
        return $this->service->getStatus(
            $clienteId,
            $internalSessionId,
            $requestId
        );
    }

    public function cancelSession(
        int $clienteId,
        string $internalSessionId
    ): RokuAudioFallbackServiceResult {
        return $this->service->cancelSession($clienteId, $internalSessionId);
    }
}

final class RokuAudioFallbackEndpointsIntegrationCreateDependencies
    implements RokuAudioFallbackCreateEndpointDependencies
{
    public function __construct(
        private readonly int $clienteId,
        private readonly RokuAudioFallbackEndpointsIntegrationOperation $operation
    ) {
    }

    public function authenticate(): int
    {
        return $this->clienteId;
    }

    public function operation(): RokuAudioFallbackCreateEndpointOperation
    {
        return $this->operation;
    }
}

final class RokuAudioFallbackEndpointsIntegrationStatusDependencies
    implements RokuAudioFallbackStatusEndpointDependencies
{
    public function __construct(
        private readonly int $clienteId,
        private readonly RokuAudioFallbackEndpointsIntegrationOperation $operation
    ) {
    }

    public function authenticate(): int
    {
        return $this->clienteId;
    }

    public function operation(): RokuAudioFallbackStatusEndpointOperation
    {
        return $this->operation;
    }
}

final class RokuAudioFallbackEndpointsIntegrationCancelDependencies
    implements RokuAudioFallbackCancelEndpointDependencies
{
    public function __construct(
        private readonly int $clienteId,
        private readonly RokuAudioFallbackEndpointsIntegrationOperation $operation
    ) {
    }

    public function authenticate(): int
    {
        return $this->clienteId;
    }

    public function operation(): RokuAudioFallbackCancelEndpointOperation
    {
        return $this->operation;
    }
}

function roku_audio_fallback_endpoints_integration_require(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('TEST_FAILURE');
    }
}

/** @return array<string,string|null> */
function roku_audio_fallback_endpoints_integration_config(): array
{
    return [
        RokuAudioFallbackRuntimeConfigLoader::ENABLED => 'true',
        RokuAudioFallbackRuntimeConfigLoader::DERIVATION_SECRET =>
            'TEST_ONLY_DO_NOT_USE__INTEGRATION_DERIVATION_SECRET',
        RokuAudioFallbackRuntimeConfigLoader::TTL_SECONDS => '3600',
        RokuAudioFallbackRuntimeConfigLoader::INTERNAL_URL =>
            'https://internal.example.invalid',
        RokuAudioFallbackRuntimeConfigLoader::PUBLIC_URL =>
            'https://public.example.invalid',
        RokuAudioFallbackRuntimeConfigLoader::HMAC_SECRET =>
            'TEST_ONLY_DO_NOT_USE__INTEGRATION_HMAC_SECRET_DIFFERENT',
        RokuAudioFallbackRuntimeConfigLoader::CONNECT_TIMEOUT_MS => '2000',
        RokuAudioFallbackRuntimeConfigLoader::TOTAL_TIMEOUT_MS => '10000',
        RokuAudioFallbackRuntimeConfigLoader::MAX_RESPONSE_BYTES => '65536',
    ];
}

function roku_audio_fallback_endpoints_integration_body(array $body): string
{
    return json_encode(
        $body,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
}

function roku_audio_fallback_endpoints_integration_create_request(
    array $body
): RokuAudioFallbackCreateEndpointRequest {
    $json = roku_audio_fallback_endpoints_integration_body($body);
    return new RokuAudioFallbackCreateEndpointRequest(
        'POST',
        'application/json',
        (string) strlen($json),
        [],
        static fn (): string => $json
    );
}

function roku_audio_fallback_endpoints_integration_status_request(
    array $body
): RokuAudioFallbackStatusEndpointRequest {
    $json = roku_audio_fallback_endpoints_integration_body($body);
    return new RokuAudioFallbackStatusEndpointRequest(
        'POST',
        'application/json',
        (string) strlen($json),
        [],
        static fn (): string => $json
    );
}

function roku_audio_fallback_endpoints_integration_cancel_request(
    array $body
): RokuAudioFallbackCancelEndpointRequest {
    $json = roku_audio_fallback_endpoints_integration_body($body);
    return new RokuAudioFallbackCancelEndpointRequest(
        'POST',
        'application/json',
        (string) strlen($json),
        [],
        static fn (): string => $json
    );
}

/** @param array<string,mixed> $body */
function roku_audio_fallback_endpoints_integration_public(
    array $body,
    bool $allowPlayback
): void {
    roku_audio_fallback_endpoints_integration_require(
        array_keys($body) === ['ok', 'session']
        && $body['ok'] === true
        && is_array($body['session'])
    );
    $keys = array_keys($body['session']);
    $expected = $allowPlayback
        ? ['id', 'status', 'expires_at', 'playback_url']
        : ['id', 'status', 'expires_at'];
    roku_audio_fallback_endpoints_integration_require($keys === $expected);
    $encoded = json_encode($body, JSON_THROW_ON_ERROR);
    foreach ([
        'cliente_id', 'sistema_id', 'stream_id', 'extension', 'request_id',
        'public_token', 'public_token_hash', 'source_url', 'username', 'password',
        'Authorization', 'HMAC', 'internal_state', 'raw_response', 'SELECT ',
        'stack trace',
    ] as $forbidden) {
        roku_audio_fallback_endpoints_integration_require(
            !str_contains($encoded, $forbidden)
        );
    }
}

/** @param array<string,mixed> $body */
function roku_audio_fallback_endpoints_integration_error(
    array $body,
    string $code
): void {
    roku_audio_fallback_endpoints_integration_require(
        $body === ['ok' => false, 'error' => ['code' => $code]]
    );
}

function roku_audio_fallback_endpoints_integration_main(): void
{
    global $includeOutput;
    roku_audio_fallback_endpoints_integration_require($includeOutput === '');

    $state = new RokuAudioFallbackEndpointsIntegrationState();
    $executor = new RokuAudioFallbackEndpointsIntegrationExecutor($state);
    $transport = new RokuAudioFallbackEndpointsIntegrationTransport($state);
    $clock = new RokuAudioFallbackEndpointsIntegrationClock(1785326400);
    $config = (new RokuAudioFallbackRuntimeConfigLoader(
        new RokuAudioFallbackRuntimeArrayValueProvider(
            roku_audio_fallback_endpoints_integration_config()
        )
    ))->load();
    $service = RokuAudioFallbackRuntimeFactory::build(
        $config,
        $executor,
        $transport,
        $clock
    );
    $operation = new RokuAudioFallbackEndpointsIntegrationOperation($service);
    $createOwner = new RokuAudioFallbackEndpointsIntegrationCreateDependencies(
        101,
        $operation
    );
    $statusOwner = new RokuAudioFallbackEndpointsIntegrationStatusDependencies(
        101,
        $operation
    );
    $cancelOwner = new RokuAudioFallbackEndpointsIntegrationCancelDependencies(
        101,
        $operation
    );
    $createHandler = new RokuAudioFallbackCreateEndpointHandler();
    $statusHandler = new RokuAudioFallbackStatusEndpointHandler();
    $cancelHandler = new RokuAudioFallbackCancelEndpointHandler();

    $requestId = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';
    $createInput = [
        'sistema_id' => 202,
        'stream_id' => 'synthetic_stream_1',
        'extension' => 'mp4',
        'request_id' => $requestId,
    ];
    $created = $createHandler->handle(
        roku_audio_fallback_endpoints_integration_create_request($createInput),
        $createOwner
    );
    $createdBody = $created->getBody();
    roku_audio_fallback_endpoints_integration_require(
        $created->getStatusHttp() === 202
        && ($createdBody['session']['status'] ?? null) === 'preparing'
        && is_string($createdBody['session']['id'] ?? null)
        && preg_match(
            '/\Araf_[A-Za-z0-9_-]{43}\z/D',
            $createdBody['session']['id']
        ) === 1
        && $state->contextQueries === 1
        && $state->sourceUrlsObserved === 1
        && $state->createRequests === 1
        && count($state->sessions) === 1
    );
    roku_audio_fallback_endpoints_integration_public($createdBody, false);
    $id = $createdBody['session']['id'];

    $repeated = $createHandler->handle(
        roku_audio_fallback_endpoints_integration_create_request($createInput),
        $createOwner
    );
    roku_audio_fallback_endpoints_integration_require(
        $repeated->getStatusHttp() === 202
        && ($repeated->getBody()['session']['id'] ?? null) === $id
        && count($state->sessions) === 1
        && $state->contextQueries === 1
        && $state->createRequests === 1
    );
    roku_audio_fallback_endpoints_integration_public($repeated->getBody(), false);

    $statusInput = [
        'internal_session_id' => $id,
        'request_id' => $requestId,
    ];
    $preparing = $statusHandler->handle(
        roku_audio_fallback_endpoints_integration_status_request($statusInput),
        $statusOwner
    );
    roku_audio_fallback_endpoints_integration_require(
        $preparing->getStatusHttp() === 200
        && ($preparing->getBody()['session']['id'] ?? null) === $id
        && ($preparing->getBody()['session']['status'] ?? null) === 'preparing'
        && $state->statusRequests === 1
    );
    roku_audio_fallback_endpoints_integration_public($preparing->getBody(), false);

    $clock->advance(10);
    $ready = $statusHandler->handle(
        roku_audio_fallback_endpoints_integration_status_request($statusInput),
        $statusOwner
    );
    roku_audio_fallback_endpoints_integration_require(
        $ready->getStatusHttp() === 200
        && ($ready->getBody()['session']['id'] ?? null) === $id
        && ($ready->getBody()['session']['status'] ?? null) === 'ready'
        && is_string($ready->getBody()['session']['playback_url'] ?? null)
        && $state->statusRequests === 2
    );
    roku_audio_fallback_endpoints_integration_public($ready->getBody(), true);

    $wrongRequest = $statusHandler->handle(
        roku_audio_fallback_endpoints_integration_status_request([
            'internal_session_id' => $id,
            'request_id' => 'AQECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8',
        ]),
        $statusOwner
    );
    roku_audio_fallback_endpoints_integration_require(
        $wrongRequest->getStatusHttp() === 409
    );
    roku_audio_fallback_endpoints_integration_error(
        $wrongRequest->getBody(),
        'FALLBACK_CONFLICT'
    );

    $foreignDependencies =
        new RokuAudioFallbackEndpointsIntegrationStatusDependencies(102, $operation);
    $foreign = $statusHandler->handle(
        roku_audio_fallback_endpoints_integration_status_request($statusInput),
        $foreignDependencies
    );
    $missingId = 'raf_' . str_repeat('A', 43);
    if ($missingId === $id) {
        $missingId = 'raf_' . str_repeat('B', 43);
    }
    $missing = $statusHandler->handle(
        roku_audio_fallback_endpoints_integration_status_request([
            'internal_session_id' => $missingId,
            'request_id' => $requestId,
        ]),
        $statusOwner
    );
    roku_audio_fallback_endpoints_integration_require(
        $foreign->getStatusHttp() === 404
        && $missing->getStatusHttp() === 404
        && $foreign->getBody() === $missing->getBody()
    );
    roku_audio_fallback_endpoints_integration_error(
        $foreign->getBody(),
        'FALLBACK_SESSION_NOT_FOUND'
    );

    $cancelled = $cancelHandler->handle(
        roku_audio_fallback_endpoints_integration_cancel_request([
            'internal_session_id' => $id,
        ]),
        $cancelOwner
    );
    roku_audio_fallback_endpoints_integration_require(
        $cancelled->getStatusHttp() === 200
        && ($cancelled->getBody()['session']['id'] ?? null) === $id
        && ($cancelled->getBody()['session']['status'] ?? null) === 'cancelled'
        && $state->cancelRequests === 1
    );
    roku_audio_fallback_endpoints_integration_public($cancelled->getBody(), false);

    $cancelledAgain = $cancelHandler->handle(
        roku_audio_fallback_endpoints_integration_cancel_request([
            'internal_session_id' => $id,
        ]),
        $cancelOwner
    );
    roku_audio_fallback_endpoints_integration_require(
        $cancelledAgain->getStatusHttp() === 200
        && ($cancelledAgain->getBody()['session']['status'] ?? null) === 'cancelled'
        && $state->cancelRequests === 2
        && count($state->sessions) === 1
        && $state->contextQueries === 1
    );
    roku_audio_fallback_endpoints_integration_public(
        $cancelledAgain->getBody(),
        false
    );

    $afterCancel = $statusHandler->handle(
        roku_audio_fallback_endpoints_integration_status_request($statusInput),
        $statusOwner
    );
    roku_audio_fallback_endpoints_integration_require(
        $afterCancel->getStatusHttp() === 200
        && ($afterCancel->getBody()['session']['id'] ?? null) === $id
        && ($afterCancel->getBody()['session']['status'] ?? null) === 'cancelled'
    );
    roku_audio_fallback_endpoints_integration_public($afterCancel->getBody(), false);

    $newAttemptInput = $createInput;
    $newAttemptInput['request_id'] =
        'AgECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';
    $newAttempt = $createHandler->handle(
        roku_audio_fallback_endpoints_integration_create_request($newAttemptInput),
        $createOwner
    );
    roku_audio_fallback_endpoints_integration_require(
        $newAttempt->getStatusHttp() === 202
        && is_string($newAttempt->getBody()['session']['id'] ?? null)
        && $newAttempt->getBody()['session']['id'] !== $id
        && count($state->sessions) === 2
        && $state->createRequests === 2
        && $state->contextQueries === 2
        && $state->sourceUrlsObserved === 2
    );
    roku_audio_fallback_endpoints_integration_public($newAttempt->getBody(), false);

    roku_audio_fallback_endpoints_integration_require(
        $clock->calls > 0
        && $state->sessionQueries > 0
        && count($state->sessions) === 2
    );
}

function rokuAudioFallbackEndpointsIntegrationTestExecutedDirectly(): bool
{
    $script = $_SERVER['SCRIPT_FILENAME'] ?? null;
    return is_string($script)
        && $script !== ''
        && realpath($script) === __FILE__;
}

if (rokuAudioFallbackEndpointsIntegrationTestExecutedDirectly()) {
    try {
        roku_audio_fallback_endpoints_integration_main();
        fwrite(STDOUT, "ROKU_AUDIO_FALLBACK_ENDPOINTS_INTEGRATION_TEST_PASS\n");
        exit(0);
    } catch (Throwable) {
        fwrite(STDOUT, "ROKU_AUDIO_FALLBACK_ENDPOINTS_INTEGRATION_TEST_FAIL\n");
        exit(1);
    }
}
