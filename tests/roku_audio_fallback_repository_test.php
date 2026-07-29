<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/roku_audio_fallback_repository.php';
require_once dirname(__DIR__) . '/roku_audio_fallback_idempotency.php';

final class RokuAudioFallbackRepositoryTestExecutor implements RokuAudioFallbackQueryExecutor
{
    /** @var list<array{sql:string,parameters:array<string,int|string>,types:array<string,int>}> */
    public array $calls = [];
    /** @var array<string,mixed>|null */
    public ?array $row = null;
    public ?Throwable $failure = null;

    public function fetchOne(string $sql, array $parameters, array $parameterTypes): ?array
    {
        $this->calls[] = compact('sql', 'parameters') + ['types' => $parameterTypes];
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return $this->row;
    }
}

function roku_audio_fallback_repository_test_require(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('TEST_FAILURE');
    }
}

function roku_audio_fallback_repository_test_error(
    callable $operation,
    string $expectedCode,
    array $forbidden = []
): void {
    try {
        $operation();
    } catch (RokuAudioFallbackRepositoryException $exception) {
        roku_audio_fallback_repository_test_require($exception->getMessage() === $expectedCode);
        foreach ($forbidden as $value) {
            roku_audio_fallback_repository_test_require(
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
function roku_audio_fallback_repository_test_row(array $changes = []): array
{
    return array_replace([
        'id' => '9001',
        'internal_session_id' => 'synthetic_session_A1',
        'public_token_hash' => str_repeat('a', 64),
        'cliente_id' => '101',
        'sistema_id' => 202,
        'stream_id' => 'synthetic_stream_1',
        'extensao_sanitizada' => 'mp4',
        'status' => 'preparing',
        'fallback_kind' => 'vod_audio_stereo',
        'created_at' => '2026-07-29 12:00:00+00',
        'expires_at' => '2026-07-29 13:00:00+00',
        'last_access_at' => '2026-07-29 12:00:01+00',
        'started_at' => null,
        'ready_at' => null,
        'finished_at' => null,
        'cancelled_at' => null,
        'failure_code' => null,
        'tentativa' => '1',
    ], $changes);
}

try {
    $id = 'synthetic_session_A1';
    $hash = str_repeat('a', 64);
    $executor = new RokuAudioFallbackRepositoryTestExecutor();
    $repository = new RokuAudioFallbackRepository($executor);

    roku_audio_fallback_repository_test_require($repository->findByInternalSessionId($id) === null);
    roku_audio_fallback_repository_test_require(count($executor->calls) === 1);
    $first = $executor->calls[0];
    roku_audio_fallback_repository_test_require(
        str_contains($first['sql'], 'FROM public.roku_audio_fallback_sessions')
    );
    roku_audio_fallback_repository_test_require(
        str_contains($first['sql'], 'WHERE internal_session_id = :internal_session_id')
    );
    roku_audio_fallback_repository_test_require(str_contains($first['sql'], 'LIMIT 1'));
    roku_audio_fallback_repository_test_require(!str_contains($first['sql'], 'SELECT *'));
    foreach ([
        'instance_id', 'process_id', 'temporary_directory', 'playlist_relative_path',
        'total_segmentos', 'bytes_temporarios', 'motivo_encerramento', 'source_url',
    ] as $forbiddenColumn) {
        roku_audio_fallback_repository_test_require(
            !str_contains($first['sql'], $forbiddenColumn)
        );
    }
    roku_audio_fallback_repository_test_require(
        $first['parameters'] === [':internal_session_id' => $id]
    );
    roku_audio_fallback_repository_test_require(
        $first['types'] === [':internal_session_id' => PDO::PARAM_STR]
    );

    $executor->row = roku_audio_fallback_repository_test_row(['extra_column' => 'ignored']);
    $record = $repository->findByInternalSessionId($id);
    roku_audio_fallback_repository_test_require($record instanceof RokuAudioFallbackSessionRecord);
    roku_audio_fallback_repository_test_require($record->getInternalSessionId() === $id);
    roku_audio_fallback_repository_test_require($record->getStatus() === 'preparing');
    roku_audio_fallback_repository_test_require($record->getAttempt() === 1);
    roku_audio_fallback_repository_test_require($record->getFailureCode() === null);

    $ownedExecutor = new RokuAudioFallbackRepositoryTestExecutor();
    $ownedExecutor->row = roku_audio_fallback_repository_test_row();
    $ownedRepository = new RokuAudioFallbackRepository($ownedExecutor);
    roku_audio_fallback_repository_test_require(
        $ownedRepository->findOwnedByClient($id, 101) instanceof RokuAudioFallbackSessionRecord
    );
    $ownedCall = $ownedExecutor->calls[0];
    roku_audio_fallback_repository_test_require(
        str_contains($ownedCall['sql'], 'AND cliente_id = :cliente_id')
    );
    roku_audio_fallback_repository_test_require($ownedCall['parameters'] === [
        ':internal_session_id' => $id,
        ':cliente_id' => 101,
    ]);
    roku_audio_fallback_repository_test_require($ownedCall['types'] === [
        ':internal_session_id' => PDO::PARAM_STR,
        ':cliente_id' => PDO::PARAM_INT,
    ]);

    $absentExecutor = new RokuAudioFallbackRepositoryTestExecutor();
    $absentRepository = new RokuAudioFallbackRepository($absentExecutor);
    roku_audio_fallback_repository_test_require(
        $absentRepository->findOwnedByClient($id, 101) === null
    );
    roku_audio_fallback_repository_test_require(
        $absentRepository->findOwnedByClient($id, 999) === null
    );

    $terminal = RokuAudioFallbackSessionRecord::fromDatabaseRow(
        roku_audio_fallback_repository_test_row([
            'id' => 9002,
            'cliente_id' => 101,
            'sistema_id' => '202',
            'status' => 'failed',
            'started_at' => '2026-07-29 12:00:02+00',
            'finished_at' => '2026-07-29 12:05:00+00',
            'failure_code' => 'SYNTHETIC_FAILURE',
            'tentativa' => 2,
        ])
    );
    roku_audio_fallback_repository_test_require($terminal->getStatus() === 'failed');
    roku_audio_fallback_repository_test_require(
        $terminal->getFailureCode() === 'SYNTHETIC_FAILURE'
    );
    $reflection = new ReflectionClass(RokuAudioFallbackSessionRecord::class);
    roku_audio_fallback_repository_test_require($reflection->isReadOnly());
    foreach ($reflection->getProperties() as $property) {
        roku_audio_fallback_repository_test_require($property->isReadOnly());
        roku_audio_fallback_repository_test_require(!$property->isPublic());
    }
    foreach ([
        'getPublicTokenHash', 'publicTokenHash', 'tokenHash', 'getDatabaseId',
        'getRow', 'getRawRow', 'toArray', 'jsonSerialize', '__toString',
        '__debugInfo', '__get', '__set',
    ] as $forbiddenMethod) {
        roku_audio_fallback_repository_test_require(!$reflection->hasMethod($forbiddenMethod));
    }
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        roku_audio_fallback_repository_test_require(
            !str_starts_with(strtolower($method->getName()), 'set')
        );
    }

    $derivationSecret = 'TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES';
    $requestId = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';
    $otherRequestId = 'ICEiIyQlJicoKSorLC0uLzAxMjM0NTY3ODk6Ozw9Pj8';
    $derived = RokuAudioFallbackIdempotency::derivar(
        101,
        202,
        'synthetic_stream_1',
        'MP4',
        $requestId,
        $derivationSecret
    );
    $serviceExecutor = new RokuAudioFallbackRepositoryTestExecutor();
    $serviceExecutor->row = roku_audio_fallback_repository_test_row([
        'internal_session_id' => $derived['internal_session_id'],
        'public_token_hash' => $derived['public_token_hash'],
        'extensao_sanitizada' => $derived['extension'],
    ]);
    $serviceRepository = new RokuAudioFallbackRepository($serviceExecutor);
    $serviceRecord = $serviceRepository->findByInternalSessionId(
        $derived['internal_session_id']
    );
    roku_audio_fallback_repository_test_require(
        $serviceRecord instanceof RokuAudioFallbackSessionRecord
    );

    $clienteId = $serviceRecord->getClienteId();
    $sistemaId = $serviceRecord->getSistemaId();
    $streamId = $serviceRecord->getStreamId();
    $extension = $serviceRecord->getExtension();
    $fallbackKind = $serviceRecord->getFallbackKind();
    roku_audio_fallback_repository_test_require(is_int($clienteId) && $clienteId === 101);
    roku_audio_fallback_repository_test_require(is_int($sistemaId) && $sistemaId === 202);
    roku_audio_fallback_repository_test_require(
        is_string($streamId) && $streamId === 'synthetic_stream_1'
    );
    roku_audio_fallback_repository_test_require(
        is_string($extension) && $extension === 'mp4'
    );
    roku_audio_fallback_repository_test_require(
        is_string($fallbackKind) && $fallbackKind === 'vod_audio_stereo'
    );
    roku_audio_fallback_repository_test_require($serviceRecord->getClienteId() === $clienteId);
    roku_audio_fallback_repository_test_require($serviceRecord->getSistemaId() === $sistemaId);
    roku_audio_fallback_repository_test_require($serviceRecord->getStreamId() === $streamId);
    roku_audio_fallback_repository_test_require($serviceRecord->getExtension() === $extension);
    roku_audio_fallback_repository_test_require(
        $serviceRecord->getFallbackKind() === $fallbackKind
    );

    $reconstructed = RokuAudioFallbackIdempotency::derivar(
        $clienteId,
        $sistemaId,
        $streamId,
        $extension,
        $requestId,
        $derivationSecret
    );
    roku_audio_fallback_repository_test_require(
        $reconstructed['internal_session_id'] === $serviceRecord->getInternalSessionId()
    );
    roku_audio_fallback_repository_test_require(
        $serviceRecord->matchesExpectedAttempt(
            $clienteId,
            $sistemaId,
            $streamId,
            $extension,
            $fallbackKind,
            $reconstructed['public_token_hash']
        )
    );
    $syntheticPlaybackUrl = 'https://transcoder.example.invalid/media/'
        . $reconstructed['public_token'] . '/index.m3u8';
    roku_audio_fallback_repository_test_require(
        str_starts_with($syntheticPlaybackUrl, 'https://transcoder.example.invalid/media/')
        && str_ends_with($syntheticPlaybackUrl, '/index.m3u8')
    );

    $wrongRequest = RokuAudioFallbackIdempotency::derivar(
        $clienteId,
        $sistemaId,
        $streamId,
        $extension,
        $otherRequestId,
        $derivationSecret
    );
    roku_audio_fallback_repository_test_require(
        $wrongRequest['internal_session_id'] !== $reconstructed['internal_session_id']
        && $wrongRequest['public_token'] !== $reconstructed['public_token']
        && $wrongRequest['public_token_hash'] !== $reconstructed['public_token_hash']
        && $wrongRequest['internal_session_id'] !== $serviceRecord->getInternalSessionId()
    );
    roku_audio_fallback_repository_test_require(
        !$serviceRecord->matchesExpectedAttempt(
            $clienteId,
            $sistemaId,
            $streamId,
            $extension,
            $fallbackKind,
            $wrongRequest['public_token_hash']
        )
    );

    foreach ([
        [$clienteId + 1, $sistemaId, $streamId, $extension],
        [$clienteId, $sistemaId + 1, $streamId, $extension],
        [$clienteId, $sistemaId, $streamId . '_changed', $extension],
        [$clienteId, $sistemaId, $streamId, 'mkv'],
    ] as [$changedCliente, $changedSistema, $changedStream, $changedExtension]) {
        $changed = RokuAudioFallbackIdempotency::derivar(
            $changedCliente,
            $changedSistema,
            $changedStream,
            $changedExtension,
            $requestId,
            $derivationSecret
        );
        roku_audio_fallback_repository_test_require(
            $changed['internal_session_id'] !== $reconstructed['internal_session_id']
            && $changed['public_token_hash'] !== $reconstructed['public_token_hash']
        );
        roku_audio_fallback_repository_test_require(
            !$serviceRecord->matchesExpectedAttempt(
                $changedCliente,
                $changedSistema,
                $changedStream,
                $changed['extension'],
                $fallbackKind,
                $changed['public_token_hash']
            )
        );
    }
    roku_audio_fallback_repository_test_require(
        !$serviceRecord->matchesExpectedAttempt(
            $clienteId,
            $sistemaId,
            $streamId,
            $extension,
            'synthetic_other_kind',
            $reconstructed['public_token_hash']
        )
    );
    roku_audio_fallback_repository_test_require(
        $serviceRecord->getClienteId() === $clienteId
        && $serviceRecord->getSistemaId() === $sistemaId
        && $serviceRecord->getStreamId() === $streamId
        && $serviceRecord->getExtension() === $extension
        && $serviceRecord->getFallbackKind() === $fallbackKind
    );

    $validRow = roku_audio_fallback_repository_test_row();
    $invalidRows = [];
    $missing = $validRow;
    unset($missing['status']);
    $invalidRows[] = $missing;
    foreach ([
        ['id', 0], ['id', '+1'], ['id', '1e3'], ['id', ' 1'],
        ['id', '999999999999999999999999999999'],
        ['cliente_id', 0], ['sistema_id', true],
        ['public_token_hash', strtoupper($hash)], ['public_token_hash', 'abc'],
        ['internal_session_id', 'short'], ['stream_id', ''],
        ['stream_id', str_repeat('s', 513)], ['stream_id', "bad\nstream"],
        ['extensao_sanitizada', 'avi'], ['status', 'unknown'],
        ['fallback_kind', 'unknown'], ['created_at', null], ['expires_at', ''],
        ['last_access_at', "bad\nvalue"], ['ready_at', []],
        ['failure_code', str_repeat('f', 101)], ['failure_code', "bad\rvalue"],
        ['tentativa', 0], ['tentativa', 1.0],
    ] as [$field, $value]) {
        $invalidRows[] = roku_audio_fallback_repository_test_row([$field => $value]);
    }
    foreach ($invalidRows as $invalidRow) {
        roku_audio_fallback_repository_test_error(
            static fn () => RokuAudioFallbackSessionRecord::fromDatabaseRow($invalidRow),
            'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_ROW',
            [$id, $hash, 'synthetic_stream_1', 'SYNTHETIC_FAILURE']
        );
    }

    roku_audio_fallback_repository_test_require(
        $record->matchesExpectedAttempt(101, 202, 'synthetic_stream_1', 'mp4', 'vod_audio_stereo', $hash)
    );
    foreach ([
        [102, 202, 'synthetic_stream_1', 'mp4', 'vod_audio_stereo', $hash],
        [101, 203, 'synthetic_stream_1', 'mp4', 'vod_audio_stereo', $hash],
        [101, 202, 'synthetic_stream_2', 'mp4', 'vod_audio_stereo', $hash],
        [101, 202, 'synthetic_stream_1', 'mkv', 'vod_audio_stereo', $hash],
        [101, 202, 'synthetic_stream_1', 'mp4', 'synthetic_other_kind', $hash],
        [101, 202, 'synthetic_stream_1', 'mp4', 'vod_audio_stereo', str_repeat('b', 64)],
    ] as $expected) {
        roku_audio_fallback_repository_test_require(
            !$record->matchesExpectedAttempt(...$expected)
        );
    }
    roku_audio_fallback_repository_test_error(
        static fn () => $record->matchesExpectedAttempt(
            '101',
            202,
            'synthetic_stream_1',
            'mp4',
            'vod_audio_stereo',
            $hash
        ),
        'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_RECORD',
        [$hash]
    );

    foreach (['', 'short', str_repeat('a', 129), 'invalid.id'] as $invalidId) {
        roku_audio_fallback_repository_test_error(
            static fn () => $repository->findByInternalSessionId($invalidId),
            'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_ARGUMENT',
            [$invalidId]
        );
    }
    foreach ([0, -1, 2147483648, 1.0, '1'] as $invalidClient) {
        roku_audio_fallback_repository_test_error(
            static fn () => $repository->findOwnedByClient($id, $invalidClient),
            'ROKU_AUDIO_FALLBACK_REPOSITORY_INVALID_ARGUMENT',
            [$id]
        );
    }

    $failedExecutor = new RokuAudioFallbackRepositoryTestExecutor();
    $failedExecutor->failure = new RuntimeException('SYNTHETIC_DATABASE_DETAIL');
    $failedRepository = new RokuAudioFallbackRepository($failedExecutor);
    roku_audio_fallback_repository_test_error(
        static fn () => $failedRepository->findByInternalSessionId($id),
        'ROKU_AUDIO_FALLBACK_REPOSITORY_DATABASE_FAILED',
        ['SYNTHETIC_DATABASE_DETAIL', $id, $hash]
    );

    $source = file_get_contents(dirname(__DIR__) . '/roku_audio_fallback_repository.php');
    roku_audio_fallback_repository_test_require(is_string($source));
    foreach (['prepare(', 'bindValue(', 'execute(', 'PDO::FETCH_ASSOC', 'closeCursor(', 'hash_equals('] as $required) {
        roku_audio_fallback_repository_test_require(str_contains($source, $required));
    }
    roku_audio_fallback_repository_test_require(!str_contains($source, 'new PDO'));
    foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'TRUNCATE ', 'ALTER ', 'DROP ', 'CREATE '] as $writeSql) {
        roku_audio_fallback_repository_test_require(!str_contains($source, $writeSql));
    }

    fwrite(STDOUT, "ROKU_AUDIO_FALLBACK_REPOSITORY_TEST_PASS\n");
    exit(0);
} catch (Throwable) {
    fwrite(STDOUT, "ROKU_AUDIO_FALLBACK_REPOSITORY_TEST_FAIL\n");
    exit(1);
}
