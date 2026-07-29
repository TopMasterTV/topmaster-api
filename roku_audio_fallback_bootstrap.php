<?php

declare(strict_types=1);

require_once __DIR__ . '/roku_audio_fallback_runtime.php';

final class RokuAudioFallbackBootstrapException extends RuntimeException
{
    private const CODES = [
        'ROKU_AUDIO_FALLBACK_BOOTSTRAP_INVALID_ARGUMENT',
        'ROKU_AUDIO_FALLBACK_BOOTSTRAP_INVALID_ENVIRONMENT',
        'ROKU_AUDIO_FALLBACK_BOOTSTRAP_BUILD_FAILED',
    ];

    public function __construct(string $code)
    {
        parent::__construct(in_array($code, self::CODES, true)
            ? $code
            : 'ROKU_AUDIO_FALLBACK_BOOTSTRAP_BUILD_FAILED');
    }
}

interface RokuAudioFallbackEnvironmentReader
{
    public function read(string $name): string|false;
}

final class RokuAudioFallbackGetenvEnvironmentReader
    implements RokuAudioFallbackEnvironmentReader
{
    public function read(string $name): string|false
    {
        return getenv($name);
    }
}

final class RokuAudioFallbackEnvironmentValueProvider
    implements RokuAudioFallbackRuntimeValueProvider
{
    private const ALLOWED_NAMES = [
        RokuAudioFallbackRuntimeConfigLoader::ENABLED,
        RokuAudioFallbackRuntimeConfigLoader::DERIVATION_SECRET,
        RokuAudioFallbackRuntimeConfigLoader::TTL_SECONDS,
        RokuAudioFallbackRuntimeConfigLoader::INTERNAL_URL,
        RokuAudioFallbackRuntimeConfigLoader::PUBLIC_URL,
        RokuAudioFallbackRuntimeConfigLoader::HMAC_SECRET,
        RokuAudioFallbackRuntimeConfigLoader::CONNECT_TIMEOUT_MS,
        RokuAudioFallbackRuntimeConfigLoader::TOTAL_TIMEOUT_MS,
        RokuAudioFallbackRuntimeConfigLoader::MAX_RESPONSE_BYTES,
    ];

    public function __construct(
        private readonly RokuAudioFallbackEnvironmentReader $reader
    ) {
    }

    public function get(string $name): ?string
    {
        if (!in_array($name, self::ALLOWED_NAMES, true)) {
            throw new RokuAudioFallbackBootstrapException(
                'ROKU_AUDIO_FALLBACK_BOOTSTRAP_INVALID_ARGUMENT'
            );
        }
        try {
            $value = $this->reader->read($name);
        } catch (Throwable) {
            throw new RokuAudioFallbackBootstrapException(
                'ROKU_AUDIO_FALLBACK_BOOTSTRAP_INVALID_ENVIRONMENT'
            );
        }
        return $value === false ? null : $value;
    }
}

final class RokuAudioFallbackProductionBootstrap
{
    public function build(
        PDO $pdo,
        ?RokuAudioFallbackRuntimeValueProvider $values = null,
        ?RokuTranscoderHttpTransport $transport = null,
        ?callable $clock = null
    ): RokuAudioFallbackService {
        $values ??= new RokuAudioFallbackEnvironmentValueProvider(
            new RokuAudioFallbackGetenvEnvironmentReader()
        );
        $transport ??= new RokuTranscoderCurlTransport();

        try {
            $config = (new RokuAudioFallbackRuntimeConfigLoader($values))->load();
            $executor = new RokuAudioFallbackPdoQueryExecutor($pdo);
            return RokuAudioFallbackRuntimeFactory::build(
                $config,
                $executor,
                $transport,
                $clock
            );
        } catch (RokuAudioFallbackRuntimeException) {
            throw new RokuAudioFallbackBootstrapException(
                'ROKU_AUDIO_FALLBACK_BOOTSTRAP_INVALID_ENVIRONMENT'
            );
        } catch (RokuAudioFallbackBootstrapException $exception) {
            throw $exception;
        } catch (
            RokuAudioFallbackRepositoryException
            | RokuTranscoderClientException
            | RokuAudioFallbackServiceException
        ) {
            throw new RokuAudioFallbackBootstrapException(
                'ROKU_AUDIO_FALLBACK_BOOTSTRAP_BUILD_FAILED'
            );
        }
    }
}
