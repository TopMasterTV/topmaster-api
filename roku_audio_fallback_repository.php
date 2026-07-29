<?php

declare(strict_types=1);

final class RokuAudioFallbackRepositoryException extends RuntimeException
{
    private const ALLOWED_CODES = [
        'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_ARGUMENT',
        'ROKU_AUDIO_FALLBACK_REPOSITORY_DATABASE_FAILED',
        'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_ROW',
        'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_RECORD',
    ];

    public function __construct(string $errorCode)
    {
        if (!in_array($errorCode, self::ALLOWED_CODES, true)) {
            $errorCode = 'ROKU_AUDIO_FALLBACK_REPOSITORY_DATABASE_FAILED';
        }
        parent::__construct($errorCode);
    }
}

interface RokuAudioFallbackQueryExecutor
{
    /**
     * @param array<string,int|string> $parameters
     * @param array<string,int> $parameterTypes
     * @return array<string,mixed>|null
     */
    public function fetchOne(string $sql, array $parameters, array $parameterTypes): ?array;
}

final class RokuAudioFallbackPdoQueryExecutor implements RokuAudioFallbackQueryExecutor
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function fetchOne(string $sql, array $parameters, array $parameterTypes): ?array
    {
        $statement = null;
        try {
            $statement = $this->pdo->prepare($sql);
            if (!$statement instanceof PDOStatement) {
                throw new RokuAudioFallbackRepositoryException(
                    'ROKU_AUDIO_FALLBACK_REPOSITORY_DATABASE_FAILED'
                );
            }
            if (array_keys($parameters) !== array_keys($parameterTypes)) {
                throw new RokuAudioFallbackRepositoryException(
                    'ROKU_AUDIO_FALLBACK_REPOSITORY_DATABASE_FAILED'
                );
            }
            foreach ($parameters as $name => $value) {
                $type = $parameterTypes[$name] ?? null;
                if (!in_array($type, [PDO::PARAM_INT, PDO::PARAM_STR], true)) {
                    throw new RokuAudioFallbackRepositoryException(
                        'ROKU_AUDIO_FALLBACK_REPOSITORY_DATABASE_FAILED'
                    );
                }
                if (!$statement->bindValue($name, $value, $type)) {
                    throw new RokuAudioFallbackRepositoryException(
                        'ROKU_AUDIO_FALLBACK_REPOSITORY_DATABASE_FAILED'
                    );
                }
            }
            if (!$statement->execute()) {
                throw new RokuAudioFallbackRepositoryException(
                    'ROKU_AUDIO_FALLBACK_REPOSITORY_DATABASE_FAILED'
                );
            }
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return null;
            }
            if (!is_array($row)) {
                throw new RokuAudioFallbackRepositoryException(
                    'ROKU_AUDIO_FALLBACK_REPOSITORY_DATABASE_FAILED'
                );
            }
            return $row;
        } catch (RokuAudioFallbackRepositoryException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RokuAudioFallbackRepositoryException(
                'ROKU_AUDIO_FALLBACK_REPOSITORY_DATABASE_FAILED'
            );
        } finally {
            if ($statement instanceof PDOStatement) {
                try {
                    $statement->closeCursor();
                } catch (Throwable) {
                    // Cursor cleanup must not expose driver details.
                }
            }
        }
    }
}

final readonly class RokuAudioFallbackSessionRecord
{
    private const STATES = [
        'created', 'validating', 'starting', 'preparing', 'ready', 'streaming',
        'cancelling', 'cancelled', 'finished', 'expired', 'failed',
    ];
    private const EXTENSIONS = ['mp4', 'mov', 'm4v', 'mkv', 'm3u8'];
    private const REQUIRED_COLUMNS = [
        'id', 'internal_session_id', 'public_token_hash', 'cliente_id', 'sistema_id',
        'stream_id', 'extensao_sanitizada', 'status', 'fallback_kind', 'created_at',
        'expires_at', 'last_access_at', 'started_at', 'ready_at', 'finished_at',
        'cancelled_at', 'failure_code', 'tentativa',
    ];

    private function __construct(
        private int $databaseId,
        private string $internalSessionId,
        private string $publicTokenHash,
        private int $clienteId,
        private int $sistemaId,
        private string $streamId,
        private string $extension,
        private string $status,
        private string $fallbackKind,
        private string $createdAt,
        private string $expiresAt,
        private string $lastAccessAt,
        private ?string $startedAt,
        private ?string $readyAt,
        private ?string $finishedAt,
        private ?string $cancelledAt,
        private ?string $failureCode,
        private int $attempt
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        foreach (self::REQUIRED_COLUMNS as $column) {
            if (!array_key_exists($column, $row)) {
                throw new RokuAudioFallbackRepositoryException(
                    'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_ROW'
                );
            }
        }

        $internalSessionId = self::validateIdentifier($row['internal_session_id']);
        $publicTokenHash = self::validateHash($row['public_token_hash']);
        $streamId = self::validateStream($row['stream_id']);
        $extension = self::validateExactString($row['extensao_sanitizada']);
        $status = self::validateExactString($row['status']);
        $fallbackKind = self::validateExactString($row['fallback_kind']);

        if (
            !in_array($extension, self::EXTENSIONS, true)
            || !in_array($status, self::STATES, true)
            || $fallbackKind !== 'vod_audio_stereo'
        ) {
            throw new RokuAudioFallbackRepositoryException(
                'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_ROW'
            );
        }

        return new self(
            self::parsePositiveInteger($row['id'], PHP_INT_MAX),
            $internalSessionId,
            $publicTokenHash,
            self::parsePositiveInteger($row['cliente_id'], 2147483647),
            self::parsePositiveInteger($row['sistema_id'], 2147483647),
            $streamId,
            $extension,
            $status,
            $fallbackKind,
            self::validateTimestamp($row['created_at'], false),
            self::validateTimestamp($row['expires_at'], false),
            self::validateTimestamp($row['last_access_at'], false),
            self::validateTimestamp($row['started_at'], true),
            self::validateTimestamp($row['ready_at'], true),
            self::validateTimestamp($row['finished_at'], true),
            self::validateTimestamp($row['cancelled_at'], true),
            self::validateFailureCode($row['failure_code']),
            self::parsePositiveInteger($row['tentativa'], 2147483647)
        );
    }

    public function getInternalSessionId(): string
    {
        return $this->internalSessionId;
    }

    public function getClienteId(): int
    {
        return $this->clienteId;
    }

    public function getSistemaId(): int
    {
        return $this->sistemaId;
    }

    public function getStreamId(): string
    {
        return $this->streamId;
    }

    public function getExtension(): string
    {
        return $this->extension;
    }

    public function getFallbackKind(): string
    {
        return $this->fallbackKind;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): string
    {
        return $this->expiresAt;
    }

    public function getLastAccessAt(): string
    {
        return $this->lastAccessAt;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function getFailureCode(): ?string
    {
        return $this->failureCode;
    }

    public function matchesExpectedAttempt(
        mixed $clienteId,
        mixed $sistemaId,
        mixed $streamId,
        mixed $extension,
        mixed $fallbackKind,
        mixed $publicTokenHash
    ): bool {
        try {
            $expectedCliente = self::validateExpectedId($clienteId);
            $expectedSistema = self::validateExpectedId($sistemaId);
            $expectedStream = self::validateStream($streamId);
            $expectedExtension = self::validateExactString($extension);
            $expectedKind = self::validateExactString($fallbackKind);
            $expectedHash = self::validateHash($publicTokenHash);
        } catch (RokuAudioFallbackRepositoryException) {
            throw new RokuAudioFallbackRepositoryException(
                'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_RECORD'
            );
        }
        if (!in_array($expectedExtension, self::EXTENSIONS, true)) {
            throw new RokuAudioFallbackRepositoryException(
                'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_RECORD'
            );
        }

        return $this->clienteId === $expectedCliente
            && $this->sistemaId === $expectedSistema
            && $this->streamId === $expectedStream
            && $this->extension === $expectedExtension
            && $this->fallbackKind === $expectedKind
            && hash_equals($this->publicTokenHash, $expectedHash);
    }

    private static function parsePositiveInteger(mixed $value, int $maximum): int
    {
        if (is_int($value)) {
            $parsed = $value;
        } elseif (
            is_string($value)
            && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) === 1
            && strlen($value) <= strlen((string) $maximum)
            && (
                strlen($value) < strlen((string) $maximum)
                || strcmp($value, (string) $maximum) <= 0
            )
        ) {
            $parsed = (int) $value;
        } else {
            throw new RokuAudioFallbackRepositoryException(
                'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_ROW'
            );
        }
        if ($parsed < 1 || $parsed > $maximum) {
            throw new RokuAudioFallbackRepositoryException(
                'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_ROW'
            );
        }
        return $parsed;
    }

    private static function validateExpectedId(mixed $value): int
    {
        if (!is_int($value) || $value < 1 || $value > 2147483647) {
            throw new RokuAudioFallbackRepositoryException(
                'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_RECORD'
            );
        }
        return $value;
    }

    private static function validateIdentifier(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[A-Za-z0-9_-]{16,128}\z/', $value) !== 1) {
            throw new RokuAudioFallbackRepositoryException(
                'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_ROW'
            );
        }
        return $value;
    }

    private static function validateHash(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[0-9a-f]{64}\z/', $value) !== 1) {
            throw new RokuAudioFallbackRepositoryException(
                'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_ROW'
            );
        }
        return $value;
    }

    private static function validateStream(mixed $value): string
    {
        if (
            !is_string($value) || $value === '' || strlen($value) > 512
            || preg_match('/[\x00\r\n]/', $value) === 1
            || preg_match('/\A\s+\z/u', $value) === 1
        ) {
            throw new RokuAudioFallbackRepositoryException(
                'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_ROW'
            );
        }
        return $value;
    }

    private static function validateExactString(mixed $value): string
    {
        if (!is_string($value) || $value === '' || preg_match('/[\x00\r\n]/', $value) === 1) {
            throw new RokuAudioFallbackRepositoryException(
                'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_ROW'
            );
        }
        return $value;
    }

    private static function validateTimestamp(mixed $value, bool $nullable): ?string
    {
        if ($nullable && $value === null) {
            return null;
        }
        if (
            !is_string($value) || $value === '' || strlen($value) > 128
            || preg_match('/[\x00\r\n]/', $value) === 1
        ) {
            throw new RokuAudioFallbackRepositoryException(
                'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_ROW'
            );
        }
        return $value;
    }

    private static function validateFailureCode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (
            !is_string($value) || $value === '' || strlen($value) > 100
            || preg_match('/[\x00\r\n]/', $value) === 1
        ) {
            throw new RokuAudioFallbackRepositoryException(
                'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_ROW'
            );
        }
        return $value;
    }
}

final class RokuAudioFallbackRepository
{
    private const SELECT_COLUMNS = <<<'SQL'
SELECT
    id,
    internal_session_id,
    public_token_hash,
    cliente_id,
    sistema_id,
    stream_id,
    extensao_sanitizada,
    status,
    fallback_kind,
    created_at,
    expires_at,
    last_access_at,
    started_at,
    ready_at,
    finished_at,
    cancelled_at,
    failure_code,
    tentativa
FROM public.roku_audio_fallback_sessions
SQL;

    private const FIND_BY_ID_SQL = self::SELECT_COLUMNS . <<<'SQL'

WHERE internal_session_id = :internal_session_id
LIMIT 1
SQL;

    private const FIND_BY_ID_AND_CLIENT_SQL = self::SELECT_COLUMNS . <<<'SQL'

WHERE internal_session_id = :internal_session_id
  AND cliente_id = :cliente_id
LIMIT 1
SQL;

    public function __construct(private readonly RokuAudioFallbackQueryExecutor $executor)
    {
    }

    public function findByInternalSessionId(mixed $internalSessionId): ?RokuAudioFallbackSessionRecord
    {
        $validatedId = self::validateLookupIdentifier($internalSessionId);
        return $this->query(
            self::FIND_BY_ID_SQL,
            [':internal_session_id' => $validatedId],
            [':internal_session_id' => PDO::PARAM_STR]
        );
    }

    public function findOwnedByClient(
        mixed $internalSessionId,
        mixed $clienteId
    ): ?RokuAudioFallbackSessionRecord {
        $validatedId = self::validateLookupIdentifier($internalSessionId);
        if (!is_int($clienteId) || $clienteId < 1 || $clienteId > 2147483647) {
            throw new RokuAudioFallbackRepositoryException(
                'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_ARGUMENT'
            );
        }
        return $this->query(
            self::FIND_BY_ID_AND_CLIENT_SQL,
            [':internal_session_id' => $validatedId, ':cliente_id' => $clienteId],
            [':internal_session_id' => PDO::PARAM_STR, ':cliente_id' => PDO::PARAM_INT]
        );
    }

    /**
     * @param array<string,int|string> $parameters
     * @param array<string,int> $types
     */
    private function query(
        string $sql,
        array $parameters,
        array $types
    ): ?RokuAudioFallbackSessionRecord {
        try {
            $row = $this->executor->fetchOne($sql, $parameters, $types);
        } catch (RokuAudioFallbackRepositoryException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RokuAudioFallbackRepositoryException(
                'ROKU_AUDIO_FALLBACK_REPOSITORY_DATABASE_FAILED'
            );
        }
        if ($row === null) {
            return null;
        }
        try {
            return RokuAudioFallbackSessionRecord::fromDatabaseRow($row);
        } catch (RokuAudioFallbackRepositoryException) {
            throw new RokuAudioFallbackRepositoryException(
                'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_ROW'
            );
        }
    }

    private static function validateLookupIdentifier(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[A-Za-z0-9_-]{16,128}\z/', $value) !== 1) {
            throw new RokuAudioFallbackRepositoryException(
                'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_ARGUMENT'
            );
        }
        return $value;
    }
}
