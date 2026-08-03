<?php

declare(strict_types=1);

require_once __DIR__ . '/roku_audio_fallback_idempotency.php';
require_once __DIR__ . '/roku_audio_fallback_repository.php';
require_once __DIR__ . '/roku_transcoder_client.php';

final class RokuAudioFallbackServiceException extends RuntimeException
{
    private const CODES = [
        'ROKU_AUDIO_FALLBACK_DISABLED',
        'ROKU_AUDIO_FALLBACK_INVALID_ARGUMENT',
        'ROKU_AUDIO_FALLBACK_INVALID_CONFIG',
        'ROKU_AUDIO_FALLBACK_NOT_FOUND',
        'ROKU_AUDIO_FALLBACK_CONFLICT',
        'ROKU_AUDIO_FALLBACK_SOURCE_FAILED',
        'ROKU_AUDIO_FALLBACK_CAPACITY_EXCEEDED',
        'ROKU_AUDIO_FALLBACK_TRANSCODER_UNAVAILABLE',
        'ROKU_AUDIO_FALLBACK_RESULT_INDETERMINATE',
        'ROKU_AUDIO_FALLBACK_UPSTREAM_REJECTED',
        'ROKU_AUDIO_FALLBACK_UPSTREAM_INVALID_RESPONSE',
        'ROKU_AUDIO_FALLBACK_INTERNAL_FAILED',
    ];

    public function __construct(string $code)
    {
        parent::__construct(in_array($code, self::CODES, true)
            ? $code
            : 'ROKU_AUDIO_FALLBACK_INTERNAL_FAILED');
    }
}

interface RokuAudioFallbackSessionStore
{
    public function findByInternalSessionId(string $id): ?RokuAudioFallbackSessionRecord;

    public function findOwnedByClient(
        string $id,
        int $clienteId
    ): ?RokuAudioFallbackSessionRecord;
}

final class RokuAudioFallbackRepositoryStore implements RokuAudioFallbackSessionStore
{
    public function __construct(private readonly RokuAudioFallbackRepository $repository)
    {
    }

    public function findByInternalSessionId(string $id): ?RokuAudioFallbackSessionRecord
    {
        return $this->repository->findByInternalSessionId($id);
    }

    public function findOwnedByClient(
        string $id,
        int $clienteId
    ): ?RokuAudioFallbackSessionRecord {
        return $this->repository->findOwnedByClient($id, $clienteId);
    }
}

interface RokuAudioFallbackTranscoderGateway
{
    /** @return array<string,mixed> */
    public function createSession(
        string $internalSessionId,
        string $publicTokenHash,
        int $clienteId,
        int $sistemaId,
        string $streamId,
        string $sourceUrl,
        string $extension,
        string $expiresAt
    ): array;

    /** @return array<string,mixed> */
    public function getSessionStatus(string $internalSessionId): array;

    /** @return array<string,mixed> */
    public function cancelSession(string $internalSessionId): array;
}

final class RokuAudioFallbackTranscoderClientGateway implements RokuAudioFallbackTranscoderGateway
{
    public function __construct(private readonly RokuTranscoderClient $client)
    {
    }

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
        return $this->client->createSession(
            $internalSessionId,
            $publicTokenHash,
            $clienteId,
            $sistemaId,
            $streamId,
            $sourceUrl,
            $extension,
            $expiresAt
        );
    }

    public function getSessionStatus(string $internalSessionId): array
    {
        return $this->client->getStatus($internalSessionId);
    }

    public function cancelSession(string $internalSessionId): array
    {
        return $this->client->cancelSession($internalSessionId);
    }
}

interface RokuAudioFallbackSourceResolver
{
    public function resolve(
        int $clienteId,
        int $sistemaId,
        string $streamId,
        string $extension
    ): string;
}

final readonly class RokuAudioFallbackServiceResult
{
    public function __construct(
        private string $id,
        private string $status,
        private string $expiresAt,
        private ?string $playbackUrl
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getExpiresAt(): string
    {
        return $this->expiresAt;
    }

    public function getPlaybackUrl(): ?string
    {
        return $this->playbackUrl;
    }
}

final class RokuAudioFallbackService
{
    private const FALLBACK_KIND = 'vod_audio_stereo';
    private const INDETERMINATE_CLIENT_CODES = [
        'ROKU_TRANSCODER_CLIENT_TIMEOUT',
        'ROKU_TRANSCODER_CLIENT_TRANSPORT_FAILED',
    ];

    private readonly string $derivationSecret;
    private readonly string $publicBaseUrl;
    private readonly int $ttl;
    private readonly Closure $clock;

    public function __construct(
        private readonly RokuAudioFallbackSessionStore $store,
        private readonly RokuAudioFallbackTranscoderGateway $gateway,
        private readonly RokuAudioFallbackSourceResolver $sourceResolver,
        mixed $derivationSecret,
        mixed $publicBaseUrl,
        mixed $ttl,
        private readonly bool $enabled,
        ?callable $clock = null
    ) {
        if (!is_string($derivationSecret) || strlen($derivationSecret) < 32) {
            throw new RokuAudioFallbackServiceException(
                'ROKU_AUDIO_FALLBACK_INVALID_CONFIG'
            );
        }
        $this->derivationSecret = $derivationSecret;
        $this->publicBaseUrl = self::validatePublicBaseUrl($publicBaseUrl);
        if (!is_int($ttl) || $ttl < 60 || $ttl > 21600) {
            throw new RokuAudioFallbackServiceException(
                'ROKU_AUDIO_FALLBACK_INVALID_CONFIG'
            );
        }
        $this->ttl = $ttl;
        $this->clock = Closure::fromCallable($clock ?? static fn (): int => time());
    }

    public function createSession(
        int $clienteId,
        int $sistemaId,
        string $streamId,
        string $extension,
        string $requestId
    ): RokuAudioFallbackServiceResult {
        $this->requireEnabled();
        $derived = $this->derive(
            $clienteId,
            $sistemaId,
            $streamId,
            $extension,
            $requestId
        );
        $internalId = $derived['internal_session_id'];

        $existing = $this->findById($internalId);
        if ($existing !== null) {
            $result = $this->resultFromRecord(
                $existing,
                $derived,
                $internalId,
                $clienteId,
                $sistemaId,
                $streamId
            );
            self::logCreateDiagnostic(
                'FALLBACK_CREATE_STAGE=SESSION_ACCEPTED PUBLIC_STATUS=' . $result->getStatus()
            );
            return $result;
        }

        // Instrumentação temporária para diagnóstico controlado do fallback Roku.
        // Remover antes do commit final de produção.
        self::logCreateDiagnostic('FALLBACK_CREATE_STAGE=SOURCE_RESOLUTION_STARTED');
        try {
            $sourceUrl = $this->sourceResolver->resolve(
                $clienteId,
                $sistemaId,
                $streamId,
                $derived['extension']
            );
        } catch (Throwable $exception) {
            self::logCreateDiagnostic(
                'FALLBACK_CREATE_STAGE=SOURCE_RESOLUTION_FAILED INTERNAL_CODE=' .
                self::sanitizedSourceCode($exception)
            );
            throw new RokuAudioFallbackServiceException(
                'ROKU_AUDIO_FALLBACK_SOURCE_FAILED'
            );
        }
        if (!is_string($sourceUrl) || $sourceUrl === '') {
            self::logCreateDiagnostic(
                'FALLBACK_CREATE_STAGE=SOURCE_RESOLUTION_FAILED INTERNAL_CODE=SOURCE_RESULT_INVALID'
            );
            throw new RokuAudioFallbackServiceException(
                'ROKU_AUDIO_FALLBACK_SOURCE_FAILED'
            );
        }
        self::logCreateDiagnostic('FALLBACK_CREATE_STAGE=SOURCE_RESOLVED');

        $now = ($this->clock)();
        if (!is_int($now) || $now < 1 || $now > PHP_INT_MAX - $this->ttl) {
            throw new RokuAudioFallbackServiceException(
                'ROKU_AUDIO_FALLBACK_INVALID_CONFIG'
            );
        }
        $expiresAt = gmdate('Y-m-d\TH:i:s\Z', $now + $this->ttl);
        $arguments = [
            $internalId,
            $derived['public_token_hash'],
            $clienteId,
            $sistemaId,
            $streamId,
            $sourceUrl,
            $derived['extension'],
            $expiresAt,
        ];

        self::logCreateDiagnostic('FALLBACK_CREATE_STAGE=TRANSCODER_CALL_STARTED');
        try {
            $response = $this->gateway->createSession(...$arguments);
            self::logCreateDiagnostic(
                'FALLBACK_CREATE_STAGE=TRANSCODER_CALL_SUCCEEDED HTTP_STATUS=202'
            );
            $result = $this->resultFromUpstream(
                $response,
                $internalId,
                $derived['public_token']
            );
            self::logCreateDiagnostic(
                'FALLBACK_CREATE_STAGE=SESSION_ACCEPTED PUBLIC_STATUS=' . $result->getStatus()
            );
            return $result;
        } catch (RokuTranscoderClientException $exception) {
            self::logTranscoderCreateFailure($exception);
            if (!in_array($exception->getMessage(), self::INDETERMINATE_CLIENT_CODES, true)) {
                throw self::mapClientException($exception);
            }
        } catch (RokuAudioFallbackServiceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RokuAudioFallbackServiceException(
                'ROKU_AUDIO_FALLBACK_INTERNAL_FAILED'
            );
        }

        $reconciled = $this->findById($internalId);
        if ($reconciled !== null) {
            $result = $this->resultFromRecord(
                $reconciled,
                $derived,
                $internalId,
                $clienteId,
                $sistemaId,
                $streamId
            );
            self::logCreateDiagnostic(
                'FALLBACK_CREATE_STAGE=SESSION_ACCEPTED PUBLIC_STATUS=' . $result->getStatus()
            );
            return $result;
        }

        self::logCreateDiagnostic('FALLBACK_CREATE_STAGE=TRANSCODER_CALL_STARTED');
        try {
            $response = $this->gateway->createSession(...$arguments);
            self::logCreateDiagnostic(
                'FALLBACK_CREATE_STAGE=TRANSCODER_CALL_SUCCEEDED HTTP_STATUS=202'
            );
            $result = $this->resultFromUpstream(
                $response,
                $internalId,
                $derived['public_token']
            );
            self::logCreateDiagnostic(
                'FALLBACK_CREATE_STAGE=SESSION_ACCEPTED PUBLIC_STATUS=' . $result->getStatus()
            );
            return $result;
        } catch (RokuTranscoderClientException $exception) {
            self::logTranscoderCreateFailure($exception);
            if (
                $exception->getMessage() !== 'ROKU_TRANSCODER_CLIENT_CONFLICT'
                && !in_array(
                    $exception->getMessage(),
                    self::INDETERMINATE_CLIENT_CODES,
                    true
                )
            ) {
                throw self::mapClientException($exception);
            }
        } catch (RokuAudioFallbackServiceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RokuAudioFallbackServiceException(
                'ROKU_AUDIO_FALLBACK_INTERNAL_FAILED'
            );
        }

        $reconciled = $this->findById($internalId);
        if ($reconciled !== null) {
            return $this->resultFromRecord(
                $reconciled,
                $derived,
                $internalId,
                $clienteId,
                $sistemaId,
                $streamId
            );
        }
        throw new RokuAudioFallbackServiceException(
            'ROKU_AUDIO_FALLBACK_RESULT_INDETERMINATE'
        );
    }

    private static function logCreateDiagnostic(string $message): void
    {
        error_log($message);
    }

    private static function sanitizedSourceCode(Throwable $exception): string
    {
        $allowed = [
            'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_ARGUMENT',
            'ROKU_AUDIO_FALLBACK_SOURCE_NOT_FOUND',
            'ROKU_AUDIO_FALLBACK_SOURCE_INACTIVE',
            'ROKU_AUDIO_FALLBACK_SOURCE_UNSUPPORTED',
            'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_CONTEXT',
            'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_BASE_URL',
            'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_CREDENTIALS',
            'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_URL',
            'ROKU_AUDIO_FALLBACK_SOURCE_INTERNAL_FAILED',
            'ROKU_AUDIO_FALLBACK_XTREAM_PROVIDER_INVALID_ARGUMENT',
            'ROKU_AUDIO_FALLBACK_XTREAM_PROVIDER_DATABASE_FAILED',
            'ROKU_AUDIO_FALLBACK_XTREAM_PROVIDER_INVALID_ROW',
            'ROKU_AUDIO_FALLBACK_XTREAM_PROVIDER_INVALID_CONTEXT',
        ];
        $code = $exception->getMessage();
        return in_array($code, $allowed, true) ? $code : 'SOURCE_INTERNAL_ERROR';
    }

    private static function logTranscoderCreateFailure(
        RokuTranscoderClientException $exception
    ): void {
        $status = $exception->getUpstreamStatus();
        $code = self::sanitizedTranscoderCode($exception);
        if ($status !== null) {
            self::logCreateDiagnostic(
                'FALLBACK_CREATE_STAGE=TRANSCODER_HTTP_ERROR HTTP_STATUS=' .
                $status . ' INTERNAL_CODE=' . $code
            );
            return;
        }
        self::logCreateDiagnostic(
            'FALLBACK_CREATE_STAGE=TRANSCODER_TRANSPORT_ERROR INTERNAL_CODE=' . $code
        );
    }

    private static function sanitizedTranscoderCode(
        RokuTranscoderClientException $exception
    ): string {
        $allowed = [
            'ROKU_TRANSCODER_CLIENT_INVALID_ARGUMENT',
            'ROKU_TRANSCODER_CLIENT_INVALID_BASE_URL',
            'ROKU_TRANSCODER_CLIENT_INVALID_CONFIG',
            'ROKU_TRANSCODER_CLIENT_INVALID_PAYLOAD',
            'ROKU_TRANSCODER_CLIENT_CURL_UNAVAILABLE',
            'ROKU_TRANSCODER_CLIENT_TRANSPORT_FAILED',
            'ROKU_TRANSCODER_CLIENT_TIMEOUT',
            'ROKU_TRANSCODER_CLIENT_RESPONSE_TOO_LARGE',
            'ROKU_TRANSCODER_CLIENT_INVALID_CONTENT_TYPE',
            'ROKU_TRANSCODER_CLIENT_INVALID_RESPONSE',
            'ROKU_TRANSCODER_CLIENT_UNAUTHORIZED',
            'ROKU_TRANSCODER_CLIENT_FORBIDDEN',
            'ROKU_TRANSCODER_CLIENT_NOT_FOUND',
            'ROKU_TRANSCODER_CLIENT_CONFLICT',
            'ROKU_TRANSCODER_CLIENT_CAPACITY_EXCEEDED',
            'ROKU_TRANSCODER_CLIENT_UPSTREAM_REJECTED',
            'ROKU_TRANSCODER_CLIENT_UPSTREAM_FAILED',
        ];
        $code = $exception->getMessage();
        return in_array($code, $allowed, true) ? $code : 'TRANSCODER_INTERNAL_ERROR';
    }

    public function getStatus(
        int $clienteId,
        string $internalSessionId,
        string $requestId
    ): RokuAudioFallbackServiceResult {
        $this->requireEnabled();
        $record = $this->findOwned($internalSessionId, $clienteId);
        if ($record === null) {
            throw new RokuAudioFallbackServiceException('ROKU_AUDIO_FALLBACK_NOT_FOUND');
        }
        $derived = $this->deriveFromRecord($record, $requestId);
        $this->assertRecordMatches(
            $record,
            $derived,
            $internalSessionId,
            $record->getClienteId(),
            $record->getSistemaId(),
            $record->getStreamId()
        );

        try {
            $response = $this->gateway->getSessionStatus($internalSessionId);
        } catch (RokuTranscoderClientException $exception) {
            throw self::mapClientException($exception);
        } catch (Throwable) {
            throw new RokuAudioFallbackServiceException(
                'ROKU_AUDIO_FALLBACK_INTERNAL_FAILED'
            );
        }
        return $this->resultFromUpstream(
            $response,
            $internalSessionId,
            $derived['public_token']
        );
    }

    public function cancelSession(
        int $clienteId,
        string $internalSessionId
    ): RokuAudioFallbackServiceResult {
        $this->requireEnabled();
        $record = $this->findOwned($internalSessionId, $clienteId);
        if ($record === null) {
            throw new RokuAudioFallbackServiceException('ROKU_AUDIO_FALLBACK_NOT_FOUND');
        }
        try {
            $response = $this->gateway->cancelSession($internalSessionId);
        } catch (RokuTranscoderClientException $exception) {
            throw self::mapClientException($exception);
        } catch (Throwable) {
            throw new RokuAudioFallbackServiceException(
                'ROKU_AUDIO_FALLBACK_INTERNAL_FAILED'
            );
        }
        $validated = self::validateUpstream($response, $internalSessionId);
        return new RokuAudioFallbackServiceResult(
            $internalSessionId,
            self::translateStatus($validated['status']),
            $validated['expires_at'],
            null
        );
    }

    /** @return array{canonical:string,internal_session_id:string,public_token:string,public_token_hash:string,extension:string} */
    private function derive(
        int $clienteId,
        int $sistemaId,
        string $streamId,
        string $extension,
        string $requestId
    ): array {
        try {
            return RokuAudioFallbackIdempotency::derivar(
                $clienteId,
                $sistemaId,
                $streamId,
                $extension,
                $requestId,
                $this->derivationSecret
            );
        } catch (RokuAudioFallbackIdempotencyException) {
            throw new RokuAudioFallbackServiceException(
                'ROKU_AUDIO_FALLBACK_INVALID_ARGUMENT'
            );
        } catch (Throwable) {
            throw new RokuAudioFallbackServiceException(
                'ROKU_AUDIO_FALLBACK_INTERNAL_FAILED'
            );
        }
    }

    /** @return array{canonical:string,internal_session_id:string,public_token:string,public_token_hash:string,extension:string} */
    private function deriveFromRecord(
        RokuAudioFallbackSessionRecord $record,
        string $requestId
    ): array {
        try {
            return RokuAudioFallbackIdempotency::derivar(
                $record->getClienteId(),
                $record->getSistemaId(),
                $record->getStreamId(),
                $record->getExtension(),
                $requestId,
                $this->derivationSecret
            );
        } catch (RokuAudioFallbackIdempotencyException) {
            throw new RokuAudioFallbackServiceException(
                'ROKU_AUDIO_FALLBACK_CONFLICT'
            );
        } catch (Throwable) {
            throw new RokuAudioFallbackServiceException(
                'ROKU_AUDIO_FALLBACK_INTERNAL_FAILED'
            );
        }
    }

    /** @param array<string,mixed> $derived */
    private function resultFromRecord(
        RokuAudioFallbackSessionRecord $record,
        array $derived,
        string $expectedId,
        int $expectedClienteId,
        int $expectedSistemaId,
        string $expectedStreamId
    ): RokuAudioFallbackServiceResult {
        $this->assertRecordMatches(
            $record,
            $derived,
            $expectedId,
            $expectedClienteId,
            $expectedSistemaId,
            $expectedStreamId
        );
        $status = self::translateStatus($record->getStatus());
        return new RokuAudioFallbackServiceResult(
            $expectedId,
            $status,
            $record->getExpiresAt(),
            $status === 'ready'
                ? $this->buildPlaybackUrl($derived['public_token'])
                : null
        );
    }

    /** @param array<string,mixed> $derived */
    private function assertRecordMatches(
        RokuAudioFallbackSessionRecord $record,
        array $derived,
        string $expectedId,
        int $expectedClienteId,
        int $expectedSistemaId,
        string $expectedStreamId
    ): void {
        try {
            $matches = $record->getInternalSessionId() === $expectedId
                && $record->getClienteId() === $expectedClienteId
                && $record->getSistemaId() === $expectedSistemaId
                && $record->getStreamId() === $expectedStreamId
                && $record->getFallbackKind() === self::FALLBACK_KIND
                && $record->matchesExpectedAttempt(
                    $expectedClienteId,
                    $expectedSistemaId,
                    $expectedStreamId,
                    $derived['extension'],
                    self::FALLBACK_KIND,
                    $derived['public_token_hash']
                );
        } catch (RokuAudioFallbackRepositoryException) {
            $matches = false;
        }
        if (!$matches) {
            throw new RokuAudioFallbackServiceException('ROKU_AUDIO_FALLBACK_CONFLICT');
        }
    }

    /** @param array<string,mixed> $response */
    private function resultFromUpstream(
        array $response,
        string $expectedId,
        string $publicToken
    ): RokuAudioFallbackServiceResult {
        $validated = self::validateUpstream($response, $expectedId);
        $status = self::translateStatus($validated['status']);
        return new RokuAudioFallbackServiceResult(
            $expectedId,
            $status,
            $validated['expires_at'],
            $status === 'ready' ? $this->buildPlaybackUrl($publicToken) : null
        );
    }

    private function findById(string $id): ?RokuAudioFallbackSessionRecord
    {
        try {
            return $this->store->findByInternalSessionId($id);
        } catch (Throwable) {
            throw new RokuAudioFallbackServiceException(
                'ROKU_AUDIO_FALLBACK_INTERNAL_FAILED'
            );
        }
    }

    private function findOwned(
        string $id,
        int $clienteId
    ): ?RokuAudioFallbackSessionRecord {
        try {
            return $this->store->findOwnedByClient($id, $clienteId);
        } catch (RokuAudioFallbackRepositoryException $exception) {
            if ($exception->getMessage() === 'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_ARGUMENT') {
                throw new RokuAudioFallbackServiceException(
                    'ROKU_AUDIO_FALLBACK_INVALID_ARGUMENT'
                );
            }
            throw new RokuAudioFallbackServiceException(
                'ROKU_AUDIO_FALLBACK_INTERNAL_FAILED'
            );
        } catch (Throwable) {
            throw new RokuAudioFallbackServiceException(
                'ROKU_AUDIO_FALLBACK_INTERNAL_FAILED'
            );
        }
    }

    private function requireEnabled(): void
    {
        self::logCreateDiagnostic(
            'FALLBACK_CREATE_STAGE=FEATURE_GATE_CHECK ENABLED=' .
            ($this->enabled ? 'true' : 'false')
        );
        if (!$this->enabled) {
            self::logCreateDiagnostic(
                'FALLBACK_CREATE_STAGE=FEATURE_GATE_REJECTED INTERNAL_CODE=FALLBACK_DISABLED'
            );
            throw new RokuAudioFallbackServiceException('ROKU_AUDIO_FALLBACK_DISABLED');
        }
    }

    private function buildPlaybackUrl(string $publicToken): string
    {
        return $this->publicBaseUrl . '/media/' . $publicToken . '/index.m3u8';
    }

    /** @param array<string,mixed> $response @return array{id:string,status:string,expires_at:string} */
    private static function validateUpstream(array $response, string $expectedId): array
    {
        if (
            count($response) !== 5
            || !is_string($response['id'] ?? null)
            || !hash_equals($expectedId, $response['id'])
            || !is_string($response['status'] ?? null)
            || !is_string($response['expires_at'] ?? null)
            || !is_string($response['created_at'] ?? null)
            || !is_string($response['last_access_at'] ?? null)
        ) {
            throw new RokuAudioFallbackServiceException(
                'ROKU_AUDIO_FALLBACK_UPSTREAM_INVALID_RESPONSE'
            );
        }
        return [
            'id' => $response['id'],
            'status' => $response['status'],
            'expires_at' => $response['expires_at'],
        ];
    }

    private static function translateStatus(string $status): string
    {
        return match ($status) {
            'created', 'validating', 'starting', 'preparing' => 'preparing',
            'ready', 'streaming', 'finished' => 'ready',
            'cancelling', 'cancelled' => 'cancelled',
            'failed' => 'failed',
            'expired' => 'expired',
            default => throw new RokuAudioFallbackServiceException(
                'ROKU_AUDIO_FALLBACK_UPSTREAM_INVALID_RESPONSE'
            ),
        };
    }

    private static function mapClientException(
        RokuTranscoderClientException $exception
    ): RokuAudioFallbackServiceException {
        $mapped = match ($exception->getMessage()) {
            'ROKU_TRANSCODER_CLIENT_INVALID_ARGUMENT',
            'ROKU_TRANSCODER_CLIENT_INVALID_PAYLOAD' =>
                'ROKU_AUDIO_FALLBACK_INVALID_ARGUMENT',
            'ROKU_TRANSCODER_CLIENT_INVALID_BASE_URL',
            'ROKU_TRANSCODER_CLIENT_INVALID_CONFIG',
            'ROKU_TRANSCODER_CLIENT_CURL_UNAVAILABLE' =>
                'ROKU_AUDIO_FALLBACK_INTERNAL_FAILED',
            'ROKU_TRANSCODER_CLIENT_TIMEOUT',
            'ROKU_TRANSCODER_CLIENT_TRANSPORT_FAILED' =>
                'ROKU_AUDIO_FALLBACK_TRANSCODER_UNAVAILABLE',
            'ROKU_TRANSCODER_CLIENT_RESPONSE_TOO_LARGE',
            'ROKU_TRANSCODER_CLIENT_INVALID_CONTENT_TYPE',
            'ROKU_TRANSCODER_CLIENT_INVALID_RESPONSE' =>
                'ROKU_AUDIO_FALLBACK_UPSTREAM_INVALID_RESPONSE',
            'ROKU_TRANSCODER_CLIENT_CAPACITY_EXCEEDED' =>
                'ROKU_AUDIO_FALLBACK_CAPACITY_EXCEEDED',
            'ROKU_TRANSCODER_CLIENT_NOT_FOUND' =>
                'ROKU_AUDIO_FALLBACK_NOT_FOUND',
            'ROKU_TRANSCODER_CLIENT_UNAUTHORIZED',
            'ROKU_TRANSCODER_CLIENT_FORBIDDEN',
            'ROKU_TRANSCODER_CLIENT_CONFLICT',
            'ROKU_TRANSCODER_CLIENT_UPSTREAM_REJECTED',
            'ROKU_TRANSCODER_CLIENT_UPSTREAM_FAILED' =>
                'ROKU_AUDIO_FALLBACK_UPSTREAM_REJECTED',
            default => 'ROKU_AUDIO_FALLBACK_INTERNAL_FAILED',
        };
        return new RokuAudioFallbackServiceException($mapped);
    }

    private static function validatePublicBaseUrl(mixed $value): string
    {
        if (
            !is_string($value)
            || $value === ''
            || strlen($value) > 2048
            || preg_match('/[\x00\r\n\t\\\\%]/', $value) === 1
        ) {
            throw new RokuAudioFallbackServiceException(
                'ROKU_AUDIO_FALLBACK_INVALID_CONFIG'
            );
        }
        try {
            $parts = parse_url($value);
        } catch (Throwable) {
            throw new RokuAudioFallbackServiceException(
                'ROKU_AUDIO_FALLBACK_INVALID_CONFIG'
            );
        }
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || !is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || preg_match('/\A[A-Za-z0-9.-]+\z/', $parts['host']) !== 1
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)
            || isset($parts['path']) && !in_array($parts['path'], ['', '/'], true)
        ) {
            throw new RokuAudioFallbackServiceException(
                'ROKU_AUDIO_FALLBACK_INVALID_CONFIG'
            );
        }
        return str_ends_with($value, '/') ? substr($value, 0, -1) : $value;
    }
}
