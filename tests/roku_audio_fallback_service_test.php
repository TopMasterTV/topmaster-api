<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/roku_audio_fallback_service.php';

final class RokuAudioFallbackServiceTestStore implements RokuAudioFallbackSessionStore
{
    /** @var list<RokuAudioFallbackSessionRecord|null> */
    public array $byId = [];
    public ?RokuAudioFallbackSessionRecord $owned = null;
    public int $byIdCalls = 0;
    public int $ownedCalls = 0;

    public function findByInternalSessionId(string $id): ?RokuAudioFallbackSessionRecord
    {
        $this->byIdCalls++;
        return array_shift($this->byId);
    }

    public function findOwnedByClient(
        string $id,
        int $clienteId
    ): ?RokuAudioFallbackSessionRecord {
        $this->ownedCalls++;
        return $this->owned;
    }
}

final class RokuAudioFallbackServiceTestGateway implements RokuAudioFallbackTranscoderGateway
{
    /** @var list<array<string,mixed>|Throwable> */
    public array $createResults = [];
    /** @var array<string,mixed>|Throwable|null */
    public array|Throwable|null $statusResult = null;
    /** @var array<string,mixed>|Throwable|null */
    public array|Throwable|null $cancelResult = null;
    /** @var list<array<int,mixed>> */
    public array $createCalls = [];
    public int $statusCalls = 0;
    public int $cancelCalls = 0;
    public ?string $cancelledId = null;

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
        $result = array_shift($this->createResults);
        if ($result instanceof Throwable) {
            throw $result;
        }
        if (!is_array($result)) {
            throw new RuntimeException('SYNTHETIC_GATEWAY_FAILURE');
        }
        return $result;
    }

    public function getSessionStatus(string $internalSessionId): array
    {
        $this->statusCalls++;
        if ($this->statusResult instanceof Throwable) {
            throw $this->statusResult;
        }
        if (!is_array($this->statusResult)) {
            throw new RuntimeException('SYNTHETIC_GATEWAY_FAILURE');
        }
        return $this->statusResult;
    }

    public function cancelSession(string $internalSessionId): array
    {
        $this->cancelCalls++;
        $this->cancelledId = $internalSessionId;
        if ($this->cancelResult instanceof Throwable) {
            throw $this->cancelResult;
        }
        if (!is_array($this->cancelResult)) {
            throw new RuntimeException('SYNTHETIC_GATEWAY_FAILURE');
        }
        return $this->cancelResult;
    }
}

final class RokuAudioFallbackServiceTestResolver implements RokuAudioFallbackSourceResolver
{
    public int $calls = 0;
    public string|Throwable $result = 'https://source.example.invalid/vod/synthetic.mp4';

    public function resolve(
        int $clienteId,
        int $sistemaId,
        string $streamId,
        string $extension
    ): string {
        $this->calls++;
        if ($this->result instanceof Throwable) {
            throw $this->result;
        }
        return $this->result;
    }
}

function roku_audio_fallback_service_test_require(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('TEST_FAILURE');
    }
}

function roku_audio_fallback_service_test_error(
    callable $operation,
    string $code,
    array $forbidden = []
): void {
    try {
        $operation();
    } catch (RokuAudioFallbackServiceException $exception) {
        roku_audio_fallback_service_test_require($exception->getMessage() === $code);
        foreach ($forbidden as $value) {
            roku_audio_fallback_service_test_require(
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

/** @return array<string,mixed> */
function roku_audio_fallback_service_test_derived(
    string $requestId,
    int $clienteId = 101,
    int $sistemaId = 202,
    string $streamId = 'synthetic_stream_1',
    string $extension = 'mp4'
): array {
    return RokuAudioFallbackIdempotency::derivar(
        $clienteId,
        $sistemaId,
        $streamId,
        $extension,
        $requestId,
        'TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES'
    );
}

function roku_audio_fallback_service_test_record(
    array $derived,
    array $changes = []
): RokuAudioFallbackSessionRecord {
    return RokuAudioFallbackSessionRecord::fromDatabaseRow(array_replace([
        'id' => 9001,
        'internal_session_id' => $derived['internal_session_id'],
        'public_token_hash' => $derived['public_token_hash'],
        'cliente_id' => 101,
        'sistema_id' => 202,
        'stream_id' => 'synthetic_stream_1',
        'extensao_sanitizada' => $derived['extension'],
        'status' => 'preparing',
        'fallback_kind' => 'vod_audio_stereo',
        'created_at' => '2026-07-29T12:00:00Z',
        'expires_at' => '2026-07-29T13:00:00Z',
        'last_access_at' => '2026-07-29T12:00:01Z',
        'started_at' => null,
        'ready_at' => null,
        'finished_at' => null,
        'cancelled_at' => null,
        'failure_code' => null,
        'tentativa' => 1,
    ], $changes));
}

/** @return array<string,mixed> */
function roku_audio_fallback_service_test_response(
    string $id,
    string $status,
    string $expiresAt = '2026-07-29T13:00:00Z'
): array {
    return [
        'id' => $id,
        'status' => $status,
        'created_at' => '2026-07-29T12:00:00Z',
        'expires_at' => $expiresAt,
        'last_access_at' => '2026-07-29T12:00:01Z',
    ];
}

function roku_audio_fallback_service_test_subject(
    RokuAudioFallbackServiceTestStore $store,
    RokuAudioFallbackServiceTestGateway $gateway,
    RokuAudioFallbackServiceTestResolver $resolver,
    bool $enabled = true,
    mixed $secret = 'TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES',
    mixed $baseUrl = 'https://transcoder.example.invalid',
    mixed $ttl = 3600,
    ?callable $clock = null
): RokuAudioFallbackService {
    return new RokuAudioFallbackService(
        $store,
        $gateway,
        $resolver,
        $secret,
        $baseUrl,
        $ttl,
        $enabled,
        $clock ?? static fn (): int => 1785326400
    );
}

try {
    $requestId = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';
    $otherRequestId = 'ICEiIyQlJicoKSorLC0uLzAxMjM0NTY3ODk6Ozw9Pj8';
    $derived = roku_audio_fallback_service_test_derived($requestId);

    $store = new RokuAudioFallbackServiceTestStore();
    $store->byId[] = null;
    $gateway = new RokuAudioFallbackServiceTestGateway();
    $gateway->createResults[] = roku_audio_fallback_service_test_response(
        $derived['internal_session_id'],
        'created'
    );
    $resolver = new RokuAudioFallbackServiceTestResolver();
    $clockCalls = 0;
    $service = roku_audio_fallback_service_test_subject(
        $store,
        $gateway,
        $resolver,
        true,
        'TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES',
        'https://transcoder.example.invalid/',
        3600,
        static function () use (&$clockCalls): int {
            $clockCalls++;
            return 1785326400;
        }
    );
    $created = $service->createSession(101, 202, 'synthetic_stream_1', 'MP4', $requestId);
    roku_audio_fallback_service_test_require($created->getStatus() === 'preparing');
    roku_audio_fallback_service_test_require($created->getPlaybackUrl() === null);
    roku_audio_fallback_service_test_require($resolver->calls === 1 && $clockCalls === 1);
    roku_audio_fallback_service_test_require(count($gateway->createCalls) === 1);
    $createCall = $gateway->createCalls[0];
    roku_audio_fallback_service_test_require(
        $createCall[0] === $derived['internal_session_id']
        && $createCall[1] === $derived['public_token_hash']
        && $createCall[2] === 101
        && $createCall[3] === 202
        && $createCall[4] === 'synthetic_stream_1'
        && $createCall[5] === $resolver->result
        && $createCall[6] === 'mp4'
        && $createCall[7] === '2026-07-29T13:00:00Z'
    );

    $readyStore = new RokuAudioFallbackServiceTestStore();
    $readyStore->byId[] = null;
    $readyGateway = new RokuAudioFallbackServiceTestGateway();
    $readyGateway->createResults[] = roku_audio_fallback_service_test_response(
        $derived['internal_session_id'],
        'ready'
    );
    $readyResolver = new RokuAudioFallbackServiceTestResolver();
    $ready = roku_audio_fallback_service_test_subject(
        $readyStore,
        $readyGateway,
        $readyResolver
    )->createSession(101, 202, 'synthetic_stream_1', 'mp4', $requestId);
    $expectedUrl = 'https://transcoder.example.invalid/media/'
        . $derived['public_token'] . '/index.m3u8';
    roku_audio_fallback_service_test_require($ready->getPlaybackUrl() === $expectedUrl);

    $resultReflection = new ReflectionClass(RokuAudioFallbackServiceResult::class);
    roku_audio_fallback_service_test_require($resultReflection->isReadOnly());
    foreach ($resultReflection->getProperties() as $property) {
        roku_audio_fallback_service_test_require($property->isPrivate());
    }
    foreach ([
        'getClienteId', 'getSistemaId', 'getStreamId', 'getExtension',
        'getPublicToken', 'getPublicTokenHash', 'getSourceUrl', 'toArray',
        'jsonSerialize', '__toString', '__get', '__set',
    ] as $method) {
        roku_audio_fallback_service_test_require(!$resultReflection->hasMethod($method));
    }

    foreach ([
        'created' => 'preparing',
        'preparing' => 'preparing',
        'ready' => 'ready',
        'streaming' => 'ready',
        'finished' => 'ready',
        'failed' => 'failed',
        'cancelling' => 'cancelled',
        'cancelled' => 'cancelled',
        'expired' => 'expired',
    ] as $internalStatus => $publicStatus) {
        $existingStore = new RokuAudioFallbackServiceTestStore();
        $existingStore->byId[] = roku_audio_fallback_service_test_record(
            $derived,
            ['status' => $internalStatus]
        );
        $existingGateway = new RokuAudioFallbackServiceTestGateway();
        $existingResolver = new RokuAudioFallbackServiceTestResolver();
        $existing = roku_audio_fallback_service_test_subject(
            $existingStore,
            $existingGateway,
            $existingResolver
        )->createSession(101, 202, 'synthetic_stream_1', 'mp4', $requestId);
        roku_audio_fallback_service_test_require($existing->getStatus() === $publicStatus);
        roku_audio_fallback_service_test_require(
            ($existing->getPlaybackUrl() !== null) === ($publicStatus === 'ready')
        );
        roku_audio_fallback_service_test_require(
            $existingResolver->calls === 0
            && $existingGateway->createCalls === []
            && $existingGateway->statusCalls === 0
        );
    }

    foreach ([
        ['cliente_id' => 102],
        ['sistema_id' => 203],
        ['stream_id' => 'synthetic_stream_2'],
        ['extensao_sanitizada' => 'mkv'],
        ['fallback_kind' => 'synthetic_other_kind'],
        ['public_token_hash' => str_repeat('b', 64)],
        ['internal_session_id' => 'synthetic_conflicting_session'],
    ] as $changes) {
        try {
            $conflictRecord = roku_audio_fallback_service_test_record($derived, $changes);
        } catch (RokuAudioFallbackRepositoryException) {
            continue;
        }
        $conflictStore = new RokuAudioFallbackServiceTestStore();
        $conflictStore->byId[] = $conflictRecord;
        $conflictGateway = new RokuAudioFallbackServiceTestGateway();
        $conflictResolver = new RokuAudioFallbackServiceTestResolver();
        roku_audio_fallback_service_test_error(
            static fn () => roku_audio_fallback_service_test_subject(
                $conflictStore,
                $conflictGateway,
                $conflictResolver
            )->createSession(101, 202, 'synthetic_stream_1', 'mp4', $requestId),
            'ROKU_AUDIO_FALLBACK_CONFLICT',
            [$requestId, $derived['internal_session_id'], $derived['public_token_hash']]
        );
        roku_audio_fallback_service_test_require(
            $conflictResolver->calls === 0 && $conflictGateway->createCalls === []
        );
    }

    foreach ([
        'ROKU_TRANSCODER_CLIENT_TIMEOUT',
        'ROKU_TRANSCODER_CLIENT_TRANSPORT_FAILED',
    ] as $failureCode) {
        $recoveryStore = new RokuAudioFallbackServiceTestStore();
        $recoveryStore->byId = [
            null,
            roku_audio_fallback_service_test_record($derived, ['status' => 'ready']),
        ];
        $recoveryGateway = new RokuAudioFallbackServiceTestGateway();
        $recoveryGateway->createResults[] = new RokuTranscoderClientException($failureCode);
        $recoveryResolver = new RokuAudioFallbackServiceTestResolver();
        $recovered = roku_audio_fallback_service_test_subject(
            $recoveryStore,
            $recoveryGateway,
            $recoveryResolver
        )->createSession(101, 202, 'synthetic_stream_1', 'mp4', $requestId);
        roku_audio_fallback_service_test_require(
            $recovered->getStatus() === 'ready'
            && count($recoveryGateway->createCalls) === 1
            && $recoveryResolver->calls === 1
        );
    }

    $retryStore = new RokuAudioFallbackServiceTestStore();
    $retryStore->byId = [null, null];
    $retryGateway = new RokuAudioFallbackServiceTestGateway();
    $retryGateway->createResults = [
        new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_TIMEOUT'),
        roku_audio_fallback_service_test_response($derived['internal_session_id'], 'created'),
    ];
    $retryResolver = new RokuAudioFallbackServiceTestResolver();
    $retryClockCalls = 0;
    $retried = roku_audio_fallback_service_test_subject(
        $retryStore,
        $retryGateway,
        $retryResolver,
        true,
        'TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES',
        'https://transcoder.example.invalid',
        3600,
        static function () use (&$retryClockCalls): int {
            $retryClockCalls++;
            return 1785326400;
        }
    )->createSession(101, 202, 'synthetic_stream_1', 'mp4', $requestId);
    roku_audio_fallback_service_test_require($retried->getStatus() === 'preparing');
    roku_audio_fallback_service_test_require(
        count($retryGateway->createCalls) === 2
        && $retryGateway->createCalls[0] === $retryGateway->createCalls[1]
        && $retryResolver->calls === 1
        && $retryClockCalls === 1
    );

    foreach ([
        [
            new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_TIMEOUT'),
            new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_CONFLICT'),
        ],
        [
            new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_TIMEOUT'),
            new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_TIMEOUT'),
        ],
        [
            new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_TRANSPORT_FAILED'),
            new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_TRANSPORT_FAILED'),
        ],
    ] as $failures) {
        $indeterminateStore = new RokuAudioFallbackServiceTestStore();
        $indeterminateStore->byId = [null, null, null];
        $indeterminateGateway = new RokuAudioFallbackServiceTestGateway();
        $indeterminateGateway->createResults = $failures;
        $indeterminateResolver = new RokuAudioFallbackServiceTestResolver();
        roku_audio_fallback_service_test_error(
            static fn () => roku_audio_fallback_service_test_subject(
                $indeterminateStore,
                $indeterminateGateway,
                $indeterminateResolver
            )->createSession(101, 202, 'synthetic_stream_1', 'mp4', $requestId),
            'ROKU_AUDIO_FALLBACK_RESULT_INDETERMINATE',
            [$requestId, $derived['internal_session_id']]
        );
        roku_audio_fallback_service_test_require(
            count($indeterminateGateway->createCalls) === 2
            && $indeterminateResolver->calls === 1
        );
    }

    foreach ([
        'ROKU_TRANSCODER_CLIENT_CONFLICT',
        'ROKU_TRANSCODER_CLIENT_TIMEOUT',
    ] as $secondFailure) {
        $lateStore = new RokuAudioFallbackServiceTestStore();
        $lateStore->byId = [
            null,
            null,
            roku_audio_fallback_service_test_record($derived, ['status' => 'ready']),
        ];
        $lateGateway = new RokuAudioFallbackServiceTestGateway();
        $lateGateway->createResults = [
            new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_TIMEOUT'),
            new RokuTranscoderClientException($secondFailure),
        ];
        $lateResolver = new RokuAudioFallbackServiceTestResolver();
        $late = roku_audio_fallback_service_test_subject(
            $lateStore,
            $lateGateway,
            $lateResolver
        )->createSession(101, 202, 'synthetic_stream_1', 'mp4', $requestId);
        roku_audio_fallback_service_test_require(
            $late->getStatus() === 'ready'
            && count($lateGateway->createCalls) === 2
            && $lateGateway->createCalls[0] === $lateGateway->createCalls[1]
            && $lateResolver->calls === 1
        );
    }

    foreach ([
        'ROKU_TRANSCODER_CLIENT_CAPACITY_EXCEEDED' => 'ROKU_AUDIO_FALLBACK_CAPACITY_EXCEEDED',
        'ROKU_TRANSCODER_CLIENT_UNAUTHORIZED' => 'ROKU_AUDIO_FALLBACK_UPSTREAM_REJECTED',
        'ROKU_TRANSCODER_CLIENT_FORBIDDEN' => 'ROKU_AUDIO_FALLBACK_UPSTREAM_REJECTED',
        'ROKU_TRANSCODER_CLIENT_UPSTREAM_REJECTED' => 'ROKU_AUDIO_FALLBACK_UPSTREAM_REJECTED',
        'ROKU_TRANSCODER_CLIENT_UPSTREAM_FAILED' => 'ROKU_AUDIO_FALLBACK_UPSTREAM_REJECTED',
        'ROKU_TRANSCODER_CLIENT_INVALID_RESPONSE' =>
            'ROKU_AUDIO_FALLBACK_UPSTREAM_INVALID_RESPONSE',
        'ROKU_TRANSCODER_CLIENT_INVALID_CONTENT_TYPE' =>
            'ROKU_AUDIO_FALLBACK_UPSTREAM_INVALID_RESPONSE',
        'ROKU_TRANSCODER_CLIENT_RESPONSE_TOO_LARGE' =>
            'ROKU_AUDIO_FALLBACK_UPSTREAM_INVALID_RESPONSE',
    ] as $clientCode => $serviceCode) {
        $failureStore = new RokuAudioFallbackServiceTestStore();
        $failureStore->byId[] = null;
        $failureGateway = new RokuAudioFallbackServiceTestGateway();
        $failureGateway->createResults[] = new RokuTranscoderClientException($clientCode);
        $failureResolver = new RokuAudioFallbackServiceTestResolver();
        roku_audio_fallback_service_test_error(
            static fn () => roku_audio_fallback_service_test_subject(
                $failureStore,
                $failureGateway,
                $failureResolver
            )->createSession(101, 202, 'synthetic_stream_1', 'mp4', $requestId),
            $serviceCode
        );
        roku_audio_fallback_service_test_require(count($failureGateway->createCalls) === 1);
    }

    $ownedRecord = roku_audio_fallback_service_test_record(
        $derived,
        ['status' => 'ready']
    );
    $statusStore = new RokuAudioFallbackServiceTestStore();
    $statusStore->owned = $ownedRecord;
    $statusGateway = new RokuAudioFallbackServiceTestGateway();
    $statusGateway->statusResult = roku_audio_fallback_service_test_response(
        $derived['internal_session_id'],
        'streaming'
    );
    $statusResolver = new RokuAudioFallbackServiceTestResolver();
    $statusResult = roku_audio_fallback_service_test_subject(
        $statusStore,
        $statusGateway,
        $statusResolver
    )->getStatus(101, $derived['internal_session_id'], $requestId);
    roku_audio_fallback_service_test_require(
        $statusResult->getStatus() === 'ready'
        && $statusResult->getPlaybackUrl() === $expectedUrl
        && $statusStore->ownedCalls === 1
        && $statusStore->byIdCalls === 0
        && $statusGateway->statusCalls === 1
        && $statusResolver->calls === 0
    );

    foreach ([
        'created' => 'preparing',
        'validating' => 'preparing',
        'starting' => 'preparing',
        'preparing' => 'preparing',
        'ready' => 'ready',
        'streaming' => 'ready',
        'finished' => 'ready',
        'cancelling' => 'cancelled',
        'cancelled' => 'cancelled',
        'failed' => 'failed',
        'expired' => 'expired',
    ] as $internalStatus => $publicStatus) {
        $pollStore = new RokuAudioFallbackServiceTestStore();
        $pollStore->owned = $ownedRecord;
        $pollGateway = new RokuAudioFallbackServiceTestGateway();
        $pollGateway->statusResult = roku_audio_fallback_service_test_response(
            $derived['internal_session_id'],
            $internalStatus
        );
        $pollResolver = new RokuAudioFallbackServiceTestResolver();
        $polled = roku_audio_fallback_service_test_subject(
            $pollStore,
            $pollGateway,
            $pollResolver
        )->getStatus(101, $derived['internal_session_id'], $requestId);
        roku_audio_fallback_service_test_require(
            $polled->getStatus() === $publicStatus
            && ($polled->getPlaybackUrl() !== null) === ($publicStatus === 'ready')
            && $pollStore->ownedCalls === 1
            && $pollStore->byIdCalls === 0
            && $pollGateway->statusCalls === 1
            && $pollResolver->calls === 0
        );
    }

    $wrongStatusStore = new RokuAudioFallbackServiceTestStore();
    $wrongStatusStore->owned = $ownedRecord;
    $wrongStatusGateway = new RokuAudioFallbackServiceTestGateway();
    $wrongStatusGateway->statusResult = roku_audio_fallback_service_test_response(
        'synthetic_wrong_upstream_id',
        'ready'
    );
    roku_audio_fallback_service_test_error(
        static fn () => roku_audio_fallback_service_test_subject(
            $wrongStatusStore,
            $wrongStatusGateway,
            new RokuAudioFallbackServiceTestResolver()
        )->getStatus(101, $derived['internal_session_id'], $requestId),
        'ROKU_AUDIO_FALLBACK_UPSTREAM_INVALID_RESPONSE'
    );

    roku_audio_fallback_service_test_error(
        static fn () => roku_audio_fallback_service_test_subject(
            $statusStore,
            new RokuAudioFallbackServiceTestGateway(),
            new RokuAudioFallbackServiceTestResolver()
        )->getStatus(101, $derived['internal_session_id'], $otherRequestId),
        'ROKU_AUDIO_FALLBACK_CONFLICT'
    );

    $missingStore = new RokuAudioFallbackServiceTestStore();
    roku_audio_fallback_service_test_error(
        static fn () => roku_audio_fallback_service_test_subject(
            $missingStore,
            new RokuAudioFallbackServiceTestGateway(),
            new RokuAudioFallbackServiceTestResolver()
        )->getStatus(101, $derived['internal_session_id'], $requestId),
        'ROKU_AUDIO_FALLBACK_NOT_FOUND'
    );
    roku_audio_fallback_service_test_error(
        static fn () => roku_audio_fallback_service_test_subject(
            $missingStore,
            new RokuAudioFallbackServiceTestGateway(),
            new RokuAudioFallbackServiceTestResolver()
        )->cancelSession(101, $derived['internal_session_id']),
        'ROKU_AUDIO_FALLBACK_NOT_FOUND'
    );

    foreach (['cancelling', 'cancelled', 'finished'] as $cancelStatus) {
        $cancelStore = new RokuAudioFallbackServiceTestStore();
        $cancelStore->owned = $ownedRecord;
        $cancelGateway = new RokuAudioFallbackServiceTestGateway();
        $cancelGateway->cancelResult = roku_audio_fallback_service_test_response(
            $derived['internal_session_id'],
            $cancelStatus
        );
        $cancelResolver = new RokuAudioFallbackServiceTestResolver();
        $cancelled = roku_audio_fallback_service_test_subject(
            $cancelStore,
            $cancelGateway,
            $cancelResolver
        )->cancelSession(101, $derived['internal_session_id']);
        roku_audio_fallback_service_test_require(
            $cancelled->getPlaybackUrl() === null
            && $cancelGateway->cancelCalls === 1
            && $cancelGateway->cancelledId === $derived['internal_session_id']
            && $cancelStore->ownedCalls === 1
            && $cancelStore->byIdCalls === 0
            && $cancelResolver->calls === 0
        );
    }

    $secondDerived = roku_audio_fallback_service_test_derived($otherRequestId);
    roku_audio_fallback_service_test_require(
        $secondDerived['internal_session_id'] !== $derived['internal_session_id']
        && $secondDerived['public_token'] !== $derived['public_token']
        && $secondDerived['public_token_hash'] !== $derived['public_token_hash']
    );

    $tvOneStore = new RokuAudioFallbackServiceTestStore();
    $tvOneStore->byId[] = null;
    $tvOneGateway = new RokuAudioFallbackServiceTestGateway();
    $tvOneGateway->createResults[] = roku_audio_fallback_service_test_response(
        $derived['internal_session_id'],
        'ready'
    );
    $tvOne = roku_audio_fallback_service_test_subject(
        $tvOneStore,
        $tvOneGateway,
        new RokuAudioFallbackServiceTestResolver()
    )->createSession(101, 202, 'synthetic_stream_1', 'mp4', $requestId);
    $tvTwoStore = new RokuAudioFallbackServiceTestStore();
    $tvTwoStore->byId[] = null;
    $tvTwoGateway = new RokuAudioFallbackServiceTestGateway();
    $tvTwoGateway->createResults[] = roku_audio_fallback_service_test_response(
        $secondDerived['internal_session_id'],
        'ready'
    );
    $tvTwo = roku_audio_fallback_service_test_subject(
        $tvTwoStore,
        $tvTwoGateway,
        new RokuAudioFallbackServiceTestResolver()
    )->createSession(101, 202, 'synthetic_stream_1', 'mp4', $otherRequestId);
    roku_audio_fallback_service_test_require(
        $tvOne->getId() !== $tvTwo->getId()
        && $tvOne->getPlaybackUrl() !== $tvTwo->getPlaybackUrl()
    );
    $tvOneCancelStore = new RokuAudioFallbackServiceTestStore();
    $tvOneCancelStore->owned = roku_audio_fallback_service_test_record($derived);
    $tvOneCancelGateway = new RokuAudioFallbackServiceTestGateway();
    $tvOneCancelGateway->cancelResult = roku_audio_fallback_service_test_response(
        $derived['internal_session_id'],
        'cancelled'
    );
    roku_audio_fallback_service_test_subject(
        $tvOneCancelStore,
        $tvOneCancelGateway,
        new RokuAudioFallbackServiceTestResolver()
    )->cancelSession(101, $derived['internal_session_id']);
    roku_audio_fallback_service_test_require(
        $tvOneCancelGateway->cancelledId === $derived['internal_session_id']
        && $tvOneCancelGateway->cancelledId !== $secondDerived['internal_session_id']
    );

    $wrongCreateStore = new RokuAudioFallbackServiceTestStore();
    $wrongCreateStore->byId[] = null;
    $wrongCreateGateway = new RokuAudioFallbackServiceTestGateway();
    $wrongCreateGateway->createResults[] = roku_audio_fallback_service_test_response(
        'synthetic_wrong_upstream_id',
        'created'
    );
    roku_audio_fallback_service_test_error(
        static fn () => roku_audio_fallback_service_test_subject(
            $wrongCreateStore,
            $wrongCreateGateway,
            new RokuAudioFallbackServiceTestResolver()
        )->createSession(101, 202, 'synthetic_stream_1', 'mp4', $requestId),
        'ROKU_AUDIO_FALLBACK_UPSTREAM_INVALID_RESPONSE'
    );


    $disabledStore = new RokuAudioFallbackServiceTestStore();
    $disabledGateway = new RokuAudioFallbackServiceTestGateway();
    $disabledResolver = new RokuAudioFallbackServiceTestResolver();
    $disabledClockCalls = 0;
    $disabled = roku_audio_fallback_service_test_subject(
        $disabledStore,
        $disabledGateway,
        $disabledResolver,
        false,
        'TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES',
        'https://transcoder.example.invalid',
        3600,
        static function () use (&$disabledClockCalls): int {
            $disabledClockCalls++;
            return 1785326400;
        }
    );
    foreach ([
        static fn () => $disabled->createSession(
            101,
            202,
            'synthetic_stream_1',
            'mp4',
            $requestId
        ),
        static fn () => $disabled->getStatus(
            101,
            $derived['internal_session_id'],
            $requestId
        ),
        static fn () => $disabled->cancelSession(101, $derived['internal_session_id']),
    ] as $operation) {
        roku_audio_fallback_service_test_error(
            $operation,
            'ROKU_AUDIO_FALLBACK_DISABLED'
        );
    }
    roku_audio_fallback_service_test_require(
        $disabledStore->byIdCalls === 0
        && $disabledStore->ownedCalls === 0
        && $disabledGateway->createCalls === []
        && $disabledGateway->statusCalls === 0
        && $disabledGateway->cancelCalls === 0
        && $disabledResolver->calls === 0
        && $disabledClockCalls === 0
    );

    foreach ([
        ['', 'https://transcoder.example.invalid', 3600],
        [str_repeat('x', 31), 'https://transcoder.example.invalid', 3600],
        ['TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES', '', 3600],
        ['TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES', 'http://host.invalid', 3600],
        ['TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES', '/relative', 3600],
        ['TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES', 'https://user@host.invalid', 3600],
        ['TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES', 'https://host.invalid?q=1', 3600],
        ['TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES', 'https://host.invalid#x', 3600],
        ['TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES', 'https://host.invalid/path', 3600],
        ['TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES', 'https://host%2einvalid', 3600],
        ['TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES', "https://host.invalid\n", 3600],
        ['TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES', 'https://host.invalid\\x', 3600],
        ['TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES', 'https://tést.invalid', 3600],
        ['TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES', 'https://host.invalid', 59],
        ['TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES', 'https://host.invalid', 21601],
        ['TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES', 'https://host.invalid', '3600'],
        ['TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES', 'https://host.invalid', 3600.0],
    ] as [$secret, $url, $ttl]) {
        roku_audio_fallback_service_test_error(
            static fn () => roku_audio_fallback_service_test_subject(
                new RokuAudioFallbackServiceTestStore(),
                new RokuAudioFallbackServiceTestGateway(),
                new RokuAudioFallbackServiceTestResolver(),
                true,
                $secret,
                $url,
                $ttl
            ),
            'ROKU_AUDIO_FALLBACK_INVALID_CONFIG',
            [$secret, $url]
        );
    }

    foreach ([0, -1, '1785326400'] as $invalidNow) {
        $clockStore = new RokuAudioFallbackServiceTestStore();
        $clockStore->byId[] = null;
        roku_audio_fallback_service_test_error(
            static fn () => roku_audio_fallback_service_test_subject(
                $clockStore,
                new RokuAudioFallbackServiceTestGateway(),
                new RokuAudioFallbackServiceTestResolver(),
                true,
                'TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES',
                'https://transcoder.example.invalid',
                3600,
                static fn () => $invalidNow
            )->createSession(101, 202, 'synthetic_stream_1', 'mp4', $requestId),
            'ROKU_AUDIO_FALLBACK_INVALID_CONFIG'
        );
    }

    $source = file_get_contents(dirname(__DIR__) . '/roku_audio_fallback_service.php');
    roku_audio_fallback_service_test_require(is_string($source));
    foreach ([
        'getenv(', '$_ENV', '$_SERVER', 'PDO', 'pg_connect', 'curl_init',
        'stream_socket_client', 'fsockopen', 'exec(', 'shell_exec', 'system(',
        'passthru', 'proc_open', 'popen', 'eval(', 'unserialize', 'error_log',
        'var_dump', 'print_r', 'phpinfo', 'sleep(', 'usleep(',
    ] as $forbidden) {
        roku_audio_fallback_service_test_require(!str_contains($source, $forbidden));
    }

    fwrite(STDOUT, "ROKU_AUDIO_FALLBACK_SERVICE_TEST_PASS\n");
    exit(0);
} catch (Throwable) {
    fwrite(STDOUT, "ROKU_AUDIO_FALLBACK_SERVICE_TEST_FAIL\n");
    exit(1);
}
