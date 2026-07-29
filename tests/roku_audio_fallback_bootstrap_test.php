<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/roku_audio_fallback_bootstrap.php';

final class RokuAudioFallbackBootstrapTestReader implements RokuAudioFallbackEnvironmentReader
{
    /** @var array<string,string|false> */
    public array $values = [];
    /** @var list<string> */
    public array $calls = [];
    public ?Throwable $failure = null;

    public function read(string $name): string|false
    {
        $this->calls[] = $name;
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return $this->values[$name] ?? false;
    }
}

final class RokuAudioFallbackBootstrapTestTransport implements RokuTranscoderHttpTransport
{
    public int $calls = 0;

    public function send(RokuTranscoderHttpRequest $request): RokuTranscoderHttpResponse
    {
        $this->calls++;
        throw new RuntimeException('TEST_FAILURE');
    }
}

final class RokuAudioFallbackBootstrapTestPdo extends PDO
{
    public int $calls = 0;

    public function __construct()
    {
    }

    public function prepare(
        string $query,
        array $options = []
    ): PDOStatement|false {
        $this->calls++;
        throw new RuntimeException('TEST_FAILURE');
    }
}

function roku_audio_fallback_bootstrap_test_require(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('TEST_FAILURE');
    }
}

function roku_audio_fallback_bootstrap_test_error(
    callable $operation,
    string $code,
    array $forbidden = []
): void {
    try {
        $operation();
    } catch (RokuAudioFallbackBootstrapException $exception) {
        roku_audio_fallback_bootstrap_test_require($exception->getMessage() === $code);
        foreach ($forbidden as $value) {
            roku_audio_fallback_bootstrap_test_require(
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

/** @return array<string,string> */
function roku_audio_fallback_bootstrap_test_values(
    string $enabled = 'false'
): array {
    return [
        RokuAudioFallbackRuntimeConfigLoader::ENABLED => $enabled,
        RokuAudioFallbackRuntimeConfigLoader::DERIVATION_SECRET =>
            'TEST_ONLY_DO_NOT_USE__DERIVATION_SECRET_32_BYTES',
        RokuAudioFallbackRuntimeConfigLoader::TTL_SECONDS => '3600',
        RokuAudioFallbackRuntimeConfigLoader::INTERNAL_URL =>
            'https://internal-transcoder.example.invalid',
        RokuAudioFallbackRuntimeConfigLoader::PUBLIC_URL =>
            'https://public-transcoder.example.invalid',
        RokuAudioFallbackRuntimeConfigLoader::HMAC_SECRET =>
            'TEST_ONLY_DO_NOT_USE__HMAC_SECRET_DIFFERENT_32_BYTES',
        RokuAudioFallbackRuntimeConfigLoader::CONNECT_TIMEOUT_MS => '2000',
        RokuAudioFallbackRuntimeConfigLoader::TOTAL_TIMEOUT_MS => '10000',
        RokuAudioFallbackRuntimeConfigLoader::MAX_RESPONSE_BYTES => '65536',
    ];
}

try {
    $allowedNames = array_keys(roku_audio_fallback_bootstrap_test_values());
    $reader = new RokuAudioFallbackBootstrapTestReader();
    $provider = new RokuAudioFallbackEnvironmentValueProvider($reader);
    roku_audio_fallback_bootstrap_test_require($reader->calls === []);

    foreach ($allowedNames as $name) {
        $reader->values[$name] = 'synthetic-value';
        $before = count($reader->calls);
        roku_audio_fallback_bootstrap_test_require(
            $provider->get($name) === 'synthetic-value'
            && count($reader->calls) === $before + 1
            && $reader->calls[$before] === $name
        );
    }
    $reader->values[$allowedNames[0]] = '';
    roku_audio_fallback_bootstrap_test_require($provider->get($allowedNames[0]) === '');
    unset($reader->values[$allowedNames[0]]);
    roku_audio_fallback_bootstrap_test_require($provider->get($allowedNames[0]) === null);

    foreach ([
        '', 'UNKNOWN', 'ROKU_AUDIO_FALLBACK', 'roku_audio_fallback_enabled',
        ' ROKU_AUDIO_FALLBACK_ENABLED', 'ROKU_AUDIO_FALLBACK_ENABLED ',
        "ROKU_AUDIO_FALLBACK_ENABLED\0", "ROKU_AUDIO_FALLBACK_ENABLED\r",
        "ROKU_AUDIO_FALLBACK_ENABLED\n",
    ] as $invalidName) {
        $before = count($reader->calls);
        roku_audio_fallback_bootstrap_test_error(
            static fn () => $provider->get($invalidName),
            'ROKU_AUDIO_FALLBACK_BOOTSTRAP_INVALID_ARGUMENT'
        );
        roku_audio_fallback_bootstrap_test_require(count($reader->calls) === $before);
    }

    $failedReader = new RokuAudioFallbackBootstrapTestReader();
    $failedReader->failure = new RuntimeException('SYNTHETIC_ENVIRONMENT_DETAIL');
    roku_audio_fallback_bootstrap_test_error(
        static fn () => (new RokuAudioFallbackEnvironmentValueProvider($failedReader))
            ->get(RokuAudioFallbackRuntimeConfigLoader::ENABLED),
        'ROKU_AUDIO_FALLBACK_BOOTSTRAP_INVALID_ENVIRONMENT',
        ['SYNTHETIC_ENVIRONMENT_DETAIL']
    );

    $bootstrap = new RokuAudioFallbackProductionBootstrap();
    $pdo = new RokuAudioFallbackBootstrapTestPdo();
    $transport = new RokuAudioFallbackBootstrapTestTransport();
    $clockCalls = 0;
    $clock = static function () use (&$clockCalls): int {
        $clockCalls++;
        return 1785326400;
    };
    $disabled = $bootstrap->build(
        $pdo,
        new RokuAudioFallbackRuntimeArrayValueProvider(
            roku_audio_fallback_bootstrap_test_values()
        ),
        $transport,
        $clock
    );
    roku_audio_fallback_bootstrap_test_require(
        $disabled instanceof RokuAudioFallbackService
        && $pdo->calls === 0
        && $transport->calls === 0
        && $clockCalls === 0
    );
    foreach ([
        static fn () => $disabled->createSession(
            101,
            202,
            'synthetic_stream_1',
            'mp4',
            'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8'
        ),
        static fn () => $disabled->getStatus(
            101,
            'synthetic_session_A1',
            'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8'
        ),
        static fn () => $disabled->cancelSession(101, 'synthetic_session_A1'),
    ] as $operation) {
        try {
            $operation();
        } catch (RokuAudioFallbackServiceException $exception) {
            roku_audio_fallback_bootstrap_test_require(
                $exception->getMessage() === 'ROKU_AUDIO_FALLBACK_DISABLED'
            );
            continue;
        }
        throw new RuntimeException('TEST_FAILURE');
    }
    roku_audio_fallback_bootstrap_test_require(
        $pdo->calls === 0 && $transport->calls === 0 && $clockCalls === 0
    );

    $activePdo = new RokuAudioFallbackBootstrapTestPdo();
    $activeTransport = new RokuAudioFallbackBootstrapTestTransport();
    $activeClockCalls = 0;
    $active = $bootstrap->build(
        $activePdo,
        new RokuAudioFallbackRuntimeArrayValueProvider(
            roku_audio_fallback_bootstrap_test_values('true')
        ),
        $activeTransport,
        static function () use (&$activeClockCalls): int {
            $activeClockCalls++;
            return 1785326400;
        }
    );
    roku_audio_fallback_bootstrap_test_require(
        $active instanceof RokuAudioFallbackService
        && $activePdo->calls === 0
        && $activeTransport->calls === 0
        && $activeClockCalls === 0
    );

    $invalidCases = [];
    $missing = roku_audio_fallback_bootstrap_test_values();
    unset($missing[RokuAudioFallbackRuntimeConfigLoader::DERIVATION_SECRET]);
    $invalidCases[] = $missing;
    foreach ([
        [RokuAudioFallbackRuntimeConfigLoader::ENABLED, 'yes'],
        [RokuAudioFallbackRuntimeConfigLoader::DERIVATION_SECRET, 'short'],
        [
            RokuAudioFallbackRuntimeConfigLoader::HMAC_SECRET,
            roku_audio_fallback_bootstrap_test_values()[
                RokuAudioFallbackRuntimeConfigLoader::DERIVATION_SECRET
            ],
        ],
        [RokuAudioFallbackRuntimeConfigLoader::TTL_SECONDS, '59'],
        [RokuAudioFallbackRuntimeConfigLoader::INTERNAL_URL, 'http://invalid.example'],
        [RokuAudioFallbackRuntimeConfigLoader::PUBLIC_URL, '/relative'],
        [RokuAudioFallbackRuntimeConfigLoader::TOTAL_TIMEOUT_MS, '100'],
    ] as [$name, $value]) {
        $invalidCases[] = array_replace(
            roku_audio_fallback_bootstrap_test_values(),
            [$name => $value]
        );
    }
    foreach ($invalidCases as $invalidValues) {
        $invalidPdo = new RokuAudioFallbackBootstrapTestPdo();
        $invalidTransport = new RokuAudioFallbackBootstrapTestTransport();
        $invalidClockCalls = 0;
        roku_audio_fallback_bootstrap_test_error(
            static fn () => $bootstrap->build(
                $invalidPdo,
                new RokuAudioFallbackRuntimeArrayValueProvider($invalidValues),
                $invalidTransport,
                static function () use (&$invalidClockCalls): int {
                    $invalidClockCalls++;
                    return 1785326400;
                }
            ),
            'ROKU_AUDIO_FALLBACK_BOOTSTRAP_INVALID_ENVIRONMENT',
            array_values($invalidValues)
        );
        roku_audio_fallback_bootstrap_test_require(
            $invalidPdo->calls === 0
            && $invalidTransport->calls === 0
            && $invalidClockCalls === 0
        );
    }

    $readerReflection = new ReflectionClass(RokuAudioFallbackGetenvEnvironmentReader::class);
    roku_audio_fallback_bootstrap_test_require(
        $readerReflection->implementsInterface(RokuAudioFallbackEnvironmentReader::class)
        && $readerReflection->getConstructor() === null
    );
    $providerReflection = new ReflectionClass(RokuAudioFallbackEnvironmentValueProvider::class);
    foreach (['getValues', 'toArray', 'getReader', '__get'] as $method) {
        roku_audio_fallback_bootstrap_test_require(
            !$providerReflection->hasMethod($method)
        );
    }

    $source = file_get_contents(dirname(__DIR__) . '/roku_audio_fallback_bootstrap.php');
    roku_audio_fallback_bootstrap_test_require(is_string($source));
    roku_audio_fallback_bootstrap_test_require(
        substr_count($source, 'getenv($name)') === 1
        && !str_contains($source, 'putenv')
        && !str_contains($source, '$_ENV')
        && !str_contains($source, '$_SERVER')
        && !str_contains($source, '.env')
        && !str_contains($source, 'new PDO')
        && !str_contains($source, 'DATABASE_URL')
        && !str_contains($source, 'http_response_code')
        && !str_contains($source, 'header(')
    );

    fwrite(STDOUT, "ROKU_AUDIO_FALLBACK_BOOTSTRAP_TEST_PASS\n");
    exit(0);
} catch (Throwable) {
    fwrite(STDOUT, "ROKU_AUDIO_FALLBACK_BOOTSTRAP_TEST_FAIL\n");
    exit(1);
}
