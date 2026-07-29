<?php

declare(strict_types=1);

require_once __DIR__ . '/roku_audio_fallback_repository.php';
require_once __DIR__ . '/roku_audio_fallback_xtream_source_resolver.php';

final class RokuAudioFallbackXtreamSystemContextProviderException extends RuntimeException
{
    private const ALLOWED_CODES = [
        'ROKU_AUDIO_FALLBACK_XTREAM_PROVIDER_INVALID_ARGUMENT',
        'ROKU_AUDIO_FALLBACK_XTREAM_PROVIDER_DATABASE_FAILED',
        'ROKU_AUDIO_FALLBACK_XTREAM_PROVIDER_INVALID_ROW',
        'ROKU_AUDIO_FALLBACK_XTREAM_PROVIDER_INVALID_CONTEXT',
    ];

    public function __construct(string $code)
    {
        parent::__construct(in_array($code, self::ALLOWED_CODES, true)
            ? $code
            : 'ROKU_AUDIO_FALLBACK_XTREAM_PROVIDER_DATABASE_FAILED');
    }
}

final class RokuAudioFallbackQueryXtreamSystemContextProvider
    implements RokuAudioFallbackXtreamSystemContextProvider
{
    private const FIND_OWNED_CONTEXT_SQL = <<<'SQL'
SELECT
    s.id AS sistema_id,
    s.cliente_id,
    (s.status = 'Active') AS active,
    CASE
        WHEN COALESCE(NULLIF(TRIM(s.url), ''), NULLIF(TRIM(m.url_padrao), '')) IS NOT NULL
          AND s.usuario IS NOT NULL
          AND s.usuario <> ''
          AND s.senha IS NOT NULL
          AND s.senha <> ''
            THEN 'xtream'
        WHEN NULLIF(TRIM(s.m3u_url), '') IS NOT NULL
            THEN 'm3u'
        ELSE 'indisponivel'
    END AS access_type,
    COALESCE(NULLIF(TRIM(s.url), ''), NULLIF(TRIM(m.url_padrao), '')) AS base_url,
    s.usuario AS username,
    s.senha AS password
FROM public.sistemas AS s
LEFT JOIN public.modelos_sistemas AS m
    ON m.id = s.modelo_id
WHERE s.id = :sistema_id
  AND s.cliente_id = :cliente_id
LIMIT 1
SQL;

    public function __construct(
        private readonly RokuAudioFallbackQueryExecutor $executor
    ) {
    }

    public function getOwnedXtreamContext(
        mixed $clienteId,
        mixed $sistemaId
    ): ?RokuAudioFallbackXtreamSystemContext {
        self::validateId($clienteId);
        self::validateId($sistemaId);

        try {
            $row = $this->executor->fetchOne(
                self::FIND_OWNED_CONTEXT_SQL,
                [
                    ':sistema_id' => $sistemaId,
                    ':cliente_id' => $clienteId,
                ],
                [
                    ':sistema_id' => PDO::PARAM_INT,
                    ':cliente_id' => PDO::PARAM_INT,
                ]
            );
        } catch (Throwable) {
            throw new RokuAudioFallbackXtreamSystemContextProviderException(
                'ROKU_AUDIO_FALLBACK_XTREAM_PROVIDER_DATABASE_FAILED'
            );
        }

        if ($row === null) {
            return null;
        }

        try {
            $rowClienteId = self::parseDatabaseId($row, 'cliente_id');
            $rowSistemaId = self::parseDatabaseId($row, 'sistema_id');
            $active = self::parseDatabaseBoolean($row, 'active');
            $accessType = self::parseDatabaseString($row, 'access_type');
            $baseUrl = self::parseDatabaseString($row, 'base_url');
            $username = self::parseDatabaseString($row, 'username');
            $password = self::parseDatabaseString($row, 'password');

            if ($rowClienteId !== $clienteId || $rowSistemaId !== $sistemaId) {
                throw new RokuAudioFallbackXtreamSystemContextProviderException(
                    'ROKU_AUDIO_FALLBACK_XTREAM_PROVIDER_INVALID_ROW'
                );
            }
        } catch (RokuAudioFallbackXtreamSystemContextProviderException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RokuAudioFallbackXtreamSystemContextProviderException(
                'ROKU_AUDIO_FALLBACK_XTREAM_PROVIDER_INVALID_ROW'
            );
        }

        try {
            return new RokuAudioFallbackXtreamSystemContext(
                $rowClienteId,
                $rowSistemaId,
                $baseUrl,
                $username,
                $password,
                $active,
                $accessType
            );
        } catch (Throwable) {
            throw new RokuAudioFallbackXtreamSystemContextProviderException(
                'ROKU_AUDIO_FALLBACK_XTREAM_PROVIDER_INVALID_CONTEXT'
            );
        }
    }

    private static function validateId(mixed $value): void
    {
        if (!is_int($value) || $value < 1 || $value > 2147483647) {
            throw new RokuAudioFallbackXtreamSystemContextProviderException(
                'ROKU_AUDIO_FALLBACK_XTREAM_PROVIDER_INVALID_ARGUMENT'
            );
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function parseDatabaseId(array $row, string $column): int
    {
        if (!array_key_exists($column, $row)) {
            self::throwInvalidRow();
        }
        $value = $row[$column];
        if (is_int($value)) {
            $parsed = $value;
        } elseif (
            is_string($value)
            && preg_match('/\A[1-9][0-9]*\z/', $value) === 1
            && (
                strlen($value) < 10
                || strlen($value) === 10 && strcmp($value, '2147483647') <= 0
            )
        ) {
            $parsed = (int) $value;
        } else {
            self::throwInvalidRow();
        }
        if ($parsed < 1 || $parsed > 2147483647) {
            self::throwInvalidRow();
        }
        return $parsed;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function parseDatabaseBoolean(array $row, string $column): bool
    {
        if (!array_key_exists($column, $row)) {
            self::throwInvalidRow();
        }
        return match ($row[$column]) {
            true, 't' => true,
            false, 'f' => false,
            default => self::throwInvalidRow(),
        };
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function parseDatabaseString(array $row, string $column): string
    {
        if (!array_key_exists($column, $row) || !is_string($row[$column])) {
            self::throwInvalidRow();
        }
        return $row[$column];
    }

    private static function throwInvalidRow(): never
    {
        throw new RokuAudioFallbackXtreamSystemContextProviderException(
            'ROKU_AUDIO_FALLBACK_XTREAM_PROVIDER_INVALID_ROW'
        );
    }
}
