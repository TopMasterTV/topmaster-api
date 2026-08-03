<?php

declare(strict_types=1);

require_once __DIR__ . '/roku_audio_fallback_service.php';

final class RokuAudioFallbackXtreamSourceResolverException extends RuntimeException
{
    private const CODES = [
        'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_ARGUMENT',
        'ROKU_AUDIO_FALLBACK_SOURCE_NOT_FOUND',
        'ROKU_AUDIO_FALLBACK_SOURCE_INACTIVE',
        'ROKU_AUDIO_FALLBACK_SOURCE_UNSUPPORTED',
        'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_CONTEXT',
        'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_BASE_URL',
        'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_CREDENTIALS',
        'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_URL',
        'ROKU_AUDIO_FALLBACK_SOURCE_INTERNAL_FAILED',
    ];

    public function __construct(string $code)
    {
        parent::__construct(in_array($code, self::CODES, true)
            ? $code
            : 'ROKU_AUDIO_FALLBACK_SOURCE_INTERNAL_FAILED');
    }
}

final readonly class RokuAudioFallbackXtreamSystemContext
{
    public function __construct(
        private int $clienteId,
        private int $sistemaId,
        private string $baseUrl,
        private string $username,
        private string $password,
        private bool $active,
        private string $accessType
    ) {
    }

    public function getClienteId(): int
    {
        return $this->clienteId;
    }

    public function getSistemaId(): int
    {
        return $this->sistemaId;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function isXtream(): bool
    {
        return $this->accessType === 'xtream';
    }

    public function buildVodSourceUrl(string $streamId, string $extension): string
    {
        $base = self::validateBaseUrl($this->baseUrl);
        $username = self::validateCredential($this->username);
        $password = self::validateCredential($this->password);
        $sourceUrl = $base . '/movie/'
            . rawurlencode($username) . '/'
            . rawurlencode($password) . '/'
            . rawurlencode($streamId) . '.'
            . $extension;

        if (
            strlen($sourceUrl) > 4096
            || preg_match('/[\x00-\x20\x7F\\\\]/', $sourceUrl) === 1
            || !str_ends_with($sourceUrl, '.' . $extension)
        ) {
            throw new RokuAudioFallbackXtreamSourceResolverException(
                'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_URL'
            );
        }
        try {
            $parts = parse_url($sourceUrl);
        } catch (Throwable) {
            throw new RokuAudioFallbackXtreamSourceResolverException(
                'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_URL'
            );
        }
        if (
            !is_array($parts)
            || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || !is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new RokuAudioFallbackXtreamSourceResolverException(
                'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_URL'
            );
        }
        return $sourceUrl;
    }

    private static function validateBaseUrl(string $value): string
    {
        if (
            $value === ''
            || strlen($value) > 2048
            || preg_match('/[\x00-\x20\x7F\\\\%]/', $value) === 1
        ) {
            throw new RokuAudioFallbackXtreamSourceResolverException(
                'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_BASE_URL'
            );
        }
        try {
            $parts = parse_url($value);
        } catch (Throwable) {
            throw new RokuAudioFallbackXtreamSourceResolverException(
                'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_BASE_URL'
            );
        }
        $host = $parts['host'] ?? null;
        $path = $parts['path'] ?? '';
        if (
            !is_array($parts)
            || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || !is_string($host)
            || $host === ''
            || preg_match('/\A[A-Za-z0-9.-]+\z/', $host) !== 1
            || strlen($host) > 253
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)
            || !is_string($path)
            || self::hasAmbiguousPath($path)
        ) {
            throw new RokuAudioFallbackXtreamSourceResolverException(
                'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_BASE_URL'
            );
        }
        return rtrim($value, '/');
    }

    private static function hasAmbiguousPath(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }
        return false;
    }

    private static function validateCredential(string $value): string
    {
        if (
            $value === ''
            || strlen($value) > 1024
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new RokuAudioFallbackXtreamSourceResolverException(
                'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_CREDENTIALS'
            );
        }
        return $value;
    }
}

interface RokuAudioFallbackXtreamSystemContextProvider
{
    public function getOwnedXtreamContext(
        int $clienteId,
        int $sistemaId
    ): ?RokuAudioFallbackXtreamSystemContext;
}

final class RokuAudioFallbackXtreamSourceResolver implements RokuAudioFallbackSourceResolver
{
    public function __construct(
        private readonly RokuAudioFallbackXtreamSystemContextProvider $provider
    ) {
    }

    public function resolve(
        mixed $clienteId,
        mixed $sistemaId,
        mixed $streamId,
        mixed $extension
    ): string {
        self::validateId($clienteId);
        self::validateId($sistemaId);
        self::validateStreamId($streamId);
        self::validateExtension($extension);

        // Instrumentação temporária para diagnóstico do provider Xtream do fallback Roku.
        // Remover antes da versão final de produção.
        self::logSourceStage('FALLBACK_SOURCE_STAGE=PROVIDER_CALL_STARTED');
        try {
            $context = $this->provider->getOwnedXtreamContext($clienteId, $sistemaId);
            self::logSourceStage('FALLBACK_SOURCE_STAGE=PROVIDER_CALL_SUCCEEDED');
        } catch (RokuAudioFallbackXtreamSourceResolverException $exception) {
            self::logSourceStage(
                'FALLBACK_SOURCE_STAGE=PROVIDER_FAILED INTERNAL_CODE=PROVIDER_INTERNAL_ERROR'
            );
            throw $exception;
        } catch (RokuAudioFallbackXtreamSystemContextProviderException $exception) {
            self::logSourceStage(
                'FALLBACK_SOURCE_STAGE=PROVIDER_FAILED INTERNAL_CODE=' .
                self::sanitizeProviderCode($exception)
            );
            throw $exception;
        } catch (Throwable) {
            self::logSourceStage(
                'FALLBACK_SOURCE_STAGE=PROVIDER_FAILED INTERNAL_CODE=PROVIDER_INTERNAL_ERROR'
            );
            throw new RokuAudioFallbackXtreamSourceResolverException(
                'ROKU_AUDIO_FALLBACK_SOURCE_INTERNAL_FAILED'
            );
        }
        if ($context === null) {
            self::logSourceStage('FALLBACK_SOURCE_STAGE=CONTEXT_NOT_FOUND');
            throw new RokuAudioFallbackXtreamSourceResolverException(
                'ROKU_AUDIO_FALLBACK_SOURCE_NOT_FOUND'
            );
        }
        self::logSourceStage('FALLBACK_SOURCE_STAGE=CONTEXT_FOUND');
        if (
            $context->getClienteId() !== $clienteId
            || $context->getSistemaId() !== $sistemaId
        ) {
            throw new RokuAudioFallbackXtreamSourceResolverException(
                'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_CONTEXT'
            );
        }
        self::logSourceStage('FALLBACK_SOURCE_STAGE=CONTEXT_VALID');
        if (!$context->isActive()) {
            throw new RokuAudioFallbackXtreamSourceResolverException(
                'ROKU_AUDIO_FALLBACK_SOURCE_INACTIVE'
            );
        }
        self::logSourceStage('FALLBACK_SOURCE_STAGE=STATUS_VALID');
        if (!$context->isXtream()) {
            throw new RokuAudioFallbackXtreamSourceResolverException(
                'ROKU_AUDIO_FALLBACK_SOURCE_UNSUPPORTED'
            );
        }
        self::logSourceStage('FALLBACK_SOURCE_STAGE=TYPE_VALID');
        self::logSourceStage('FALLBACK_SOURCE_STAGE=SOURCE_BUILD_STARTED');
        try {
            $sourceUrl = $context->buildVodSourceUrl($streamId, $extension);
        } catch (RokuAudioFallbackXtreamSourceResolverException $exception) {
            throw $exception;
        } catch (Throwable) {
            self::logSourceStage(
                'FALLBACK_SOURCE_STAGE=SOURCE_BUILD_FAILED INTERNAL_CODE=SOURCE_BUILD_INTERNAL_ERROR'
            );
            throw new RokuAudioFallbackXtreamSourceResolverException(
                'ROKU_AUDIO_FALLBACK_SOURCE_INTERNAL_FAILED'
            );
        }
        self::logSourceStage('FALLBACK_SOURCE_STAGE=SOURCE_BUILT');
        return $sourceUrl;
    }

    private static function logSourceStage(string $message): void
    {
        error_log($message);
    }

    private static function sanitizeProviderCode(
        RokuAudioFallbackXtreamSystemContextProviderException $exception
    ): string {
        $allowed = [
            'ROKU_AUDIO_FALLBACK_XTREAM_PROVIDER_INVALID_ARGUMENT',
            'ROKU_AUDIO_FALLBACK_XTREAM_PROVIDER_DATABASE_FAILED',
            'ROKU_AUDIO_FALLBACK_XTREAM_PROVIDER_INVALID_ROW',
            'ROKU_AUDIO_FALLBACK_XTREAM_PROVIDER_INVALID_CONTEXT',
        ];
        $code = $exception->getMessage();
        return in_array($code, $allowed, true) ? $code : 'PROVIDER_INTERNAL_ERROR';
    }

    private static function validateId(mixed $value): void
    {
        if (!is_int($value) || $value < 1 || $value > 2147483647) {
            throw new RokuAudioFallbackXtreamSourceResolverException(
                'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_ARGUMENT'
            );
        }
    }

    private static function validateStreamId(mixed $value): void
    {
        if (
            !is_string($value)
            || $value === ''
            || strlen($value) > 512
            || preg_match('/[\x00-\x20\x7F\/\\\\?#]/', $value) === 1
            || preg_match('/\A[A-Za-z][A-Za-z0-9+.-]*:/', $value) === 1
            || $value === '.'
            || $value === '..'
        ) {
            throw new RokuAudioFallbackXtreamSourceResolverException(
                'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_ARGUMENT'
            );
        }
    }

    private static function validateExtension(mixed $value): void
    {
        if (!in_array($value, ['mp4', 'mov', 'm4v', 'mkv'], true)) {
            throw new RokuAudioFallbackXtreamSourceResolverException(
                'ROKU_AUDIO_FALLBACK_SOURCE_INVALID_ARGUMENT'
            );
        }
    }
}
