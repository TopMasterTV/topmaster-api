<?php

declare(strict_types=1);

require_once __DIR__ . '/roku_audio_fallback_repository.php';
require_once __DIR__ . '/roku_transcoder_client.php';
require_once __DIR__ . '/roku_audio_fallback_service.php';
require_once __DIR__ . '/roku_audio_fallback_xtream_source_resolver.php';
require_once __DIR__ . '/roku_audio_fallback_xtream_system_context_provider.php';

final class RokuAudioFallbackRuntimeException extends RuntimeException
{
    private const CODES = [
        'ROKU_AUDIO_FALLBACK_RUNTIME_INVALID_ARGUMENT',
        'ROKU_AUDIO_FALLBACK_RUNTIME_MISSING_CONFIG',
        'ROKU_AUDIO_FALLBACK_RUNTIME_INVALID_CONFIG',
        'ROKU_AUDIO_FALLBACK_RUNTIME_BUILD_FAILED',
    ];

    public function __construct(string $code)
    {
        parent::__construct(in_array($code, self::CODES, true)
            ? $code
            : 'ROKU_AUDIO_FALLBACK_RUNTIME_BUILD_FAILED');
    }
}

interface RokuAudioFallbackRuntimeValueProvider
{
    public function get(string $name): ?string;
}

final class RokuAudioFallbackRuntimeArrayValueProvider
    implements RokuAudioFallbackRuntimeValueProvider
{
    /** @var array<string,string|null> */
    private readonly array $values;

    public function __construct(mixed $values)
    {
        if (!is_array($values)) {
            throw new RokuAudioFallbackRuntimeException(
                'ROKU_AUDIO_FALLBACK_RUNTIME_INVALID_ARGUMENT'
            );
        }
        foreach ($values as $name => $value) {
            if (!is_string($name) || !is_string($value) && $value !== null) {
                throw new RokuAudioFallbackRuntimeException(
                    'ROKU_AUDIO_FALLBACK_RUNTIME_INVALID_ARGUMENT'
                );
            }
        }
        $this->values = $values;
    }

    public function get(string $name): ?string
    {
        return array_key_exists($name, $this->values) ? $this->values[$name] : null;
    }
}

final readonly class RokuAudioFallbackRuntimeConfig
{
    public function __construct(
        private bool $enabled,
        private string $derivationSecret,
        private int $ttlSeconds,
        private string $internalUrl,
        private string $publicUrl,
        private string $hmacSecret,
        private int $connectTimeoutMs,
        private int $totalTimeoutMs,
        private int $maxResponseBytes
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getTtlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    public function getInternalUrl(): string
    {
        return $this->internalUrl;
    }

    public function getPublicUrl(): string
    {
        return $this->publicUrl;
    }

    public function getConnectTimeoutMs(): int
    {
        return $this->connectTimeoutMs;
    }

    public function getTotalTimeoutMs(): int
    {
        return $this->totalTimeoutMs;
    }

    public function getMaxResponseBytes(): int
    {
        return $this->maxResponseBytes;
    }

    public function createTranscoderClient(
        RokuTranscoderHttpTransport $transport,
        ?callable $clock = null
    ): RokuTranscoderClient {
        return new RokuTranscoderClient(
            $this->internalUrl,
            $this->hmacSecret,
            $transport,
            $this->connectTimeoutMs,
            $this->totalTimeoutMs,
            $this->maxResponseBytes,
            $clock
        );
    }

    public function createFallbackService(
        RokuAudioFallbackSessionStore $store,
        RokuAudioFallbackTranscoderGateway $gateway,
        RokuAudioFallbackSourceResolver $resolver,
        ?callable $clock = null
    ): RokuAudioFallbackService {
        return new RokuAudioFallbackService(
            $store,
            $gateway,
            $resolver,
            $this->derivationSecret,
            $this->publicUrl,
            $this->ttlSeconds,
            $this->enabled,
            $clock
        );
    }
}

final class RokuAudioFallbackRuntimeConfigLoader
{
    public const ENABLED = 'ROKU_AUDIO_FALLBACK_ENABLED';
    public const DERIVATION_SECRET = 'ROKU_AUDIO_FALLBACK_DERIVATION_SECRET';
    public const TTL_SECONDS = 'ROKU_AUDIO_FALLBACK_TTL_SECONDS';
    public const INTERNAL_URL = 'ROKU_TRANSCODER_INTERNAL_URL';
    public const PUBLIC_URL = 'ROKU_TRANSCODER_PUBLIC_URL';
    public const HMAC_SECRET = 'ROKU_TRANSCODER_HMAC_SECRET';
    public const CONNECT_TIMEOUT_MS = 'ROKU_TRANSCODER_CONNECT_TIMEOUT_MS';
    public const TOTAL_TIMEOUT_MS = 'ROKU_TRANSCODER_TOTAL_TIMEOUT_MS';
    public const MAX_RESPONSE_BYTES = 'ROKU_TRANSCODER_MAX_RESPONSE_BYTES';

    public function __construct(
        private readonly RokuAudioFallbackRuntimeValueProvider $provider
    ) {
    }

    public function load(): RokuAudioFallbackRuntimeConfig
    {
        $enabled = self::parseEnabled($this->required(self::ENABLED));
        $derivationSecret = self::parseSecret($this->required(self::DERIVATION_SECRET));
        $hmacSecret = self::parseSecret($this->required(self::HMAC_SECRET));
        if (hash_equals($derivationSecret, $hmacSecret)) {
            self::invalidConfig();
        }
        $ttl = self::parseInteger($this->required(self::TTL_SECONDS), 60, 21600);
        $internalUrl = self::parseBaseUrl($this->required(self::INTERNAL_URL));
        $publicUrl = self::parseBaseUrl($this->required(self::PUBLIC_URL));
        $connectTimeout = self::parseOptionalInteger(
            $this->provider->get(self::CONNECT_TIMEOUT_MS),
            2000,
            100,
            10000
        );
        $totalTimeout = self::parseOptionalInteger(
            $this->provider->get(self::TOTAL_TIMEOUT_MS),
            10000,
            500,
            30000
        );
        if ($totalTimeout < $connectTimeout) {
            self::invalidConfig();
        }
        $maxResponseBytes = self::parseOptionalInteger(
            $this->provider->get(self::MAX_RESPONSE_BYTES),
            65536,
            1024,
            1048576
        );

        return new RokuAudioFallbackRuntimeConfig(
            $enabled,
            $derivationSecret,
            $ttl,
            $internalUrl,
            $publicUrl,
            $hmacSecret,
            $connectTimeout,
            $totalTimeout,
            $maxResponseBytes
        );
    }

    private function required(string $name): string
    {
        $value = $this->provider->get($name);
        if ($value === null) {
            throw new RokuAudioFallbackRuntimeException(
                'ROKU_AUDIO_FALLBACK_RUNTIME_MISSING_CONFIG'
            );
        }
        return $value;
    }

    private static function parseEnabled(string $value): bool
    {
        if ($value === '1' || strcasecmp($value, 'true') === 0) {
            return true;
        }
        if ($value === '0' || strcasecmp($value, 'false') === 0) {
            return false;
        }
        self::invalidConfig();
    }

    private static function parseSecret(string $value): string
    {
        $length = strlen($value);
        if ($length < 32 || $length > 4096) {
            self::invalidConfig();
        }
        return $value;
    }

    private static function parseOptionalInteger(
        ?string $value,
        int $default,
        int $minimum,
        int $maximum
    ): int {
        return $value === null ? $default : self::parseInteger($value, $minimum, $maximum);
    }

    private static function parseInteger(string $value, int $minimum, int $maximum): int
    {
        if (
            preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) !== 1
            || strlen($value) > strlen((string) $maximum)
            || strlen($value) === strlen((string) $maximum)
                && strcmp($value, (string) $maximum) > 0
        ) {
            self::invalidConfig();
        }
        $parsed = (int) $value;
        if ($parsed < $minimum || $parsed > $maximum) {
            self::invalidConfig();
        }
        return $parsed;
    }

    private static function parseBaseUrl(string $value): string
    {
        if (
            $value === ''
            || strlen($value) > 2048
            || preg_match('/[\x00-\x20\x7F\\\\%]/', $value) === 1
        ) {
            self::invalidConfig();
        }
        try {
            $parts = parse_url($value);
        } catch (Throwable) {
            self::invalidConfig();
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
            self::invalidConfig();
        }
        return $value;
    }

    private static function invalidConfig(): never
    {
        throw new RokuAudioFallbackRuntimeException(
            'ROKU_AUDIO_FALLBACK_RUNTIME_INVALID_CONFIG'
        );
    }
}

final class RokuAudioFallbackRuntimeFactory
{
    public static function build(
        RokuAudioFallbackRuntimeConfig $config,
        RokuAudioFallbackQueryExecutor $executor,
        RokuTranscoderHttpTransport $transport,
        ?callable $clock = null
    ): RokuAudioFallbackService {
        try {
            $repository = new RokuAudioFallbackRepository($executor);
            $store = new RokuAudioFallbackRepositoryStore($repository);
            $contextProvider = new RokuAudioFallbackQueryXtreamSystemContextProvider(
                $executor
            );
            $resolver = new RokuAudioFallbackXtreamSourceResolver($contextProvider);
            $client = $config->createTranscoderClient($transport, $clock);
            $gateway = new RokuAudioFallbackTranscoderClientGateway($client);
            return $config->createFallbackService($store, $gateway, $resolver, $clock);
        } catch (
            RokuTranscoderClientException
            | RokuAudioFallbackServiceException
            | RokuAudioFallbackRepositoryException
            | RokuAudioFallbackXtreamSystemContextProviderException
            | RokuAudioFallbackXtreamSourceResolverException
        ) {
            throw new RokuAudioFallbackRuntimeException(
                'ROKU_AUDIO_FALLBACK_RUNTIME_BUILD_FAILED'
            );
        }
    }
}
