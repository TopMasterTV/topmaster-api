<?php

declare(strict_types=1);

require_once __DIR__ . '/roku_audio_fallback_bootstrap.php';
require_once __DIR__ . '/roku_audio_fallback_idempotency.php';
require_once __DIR__ . '/roku_obter_contexto_xtream.php';

final class RokuAudioFallbackStatusEndpointException extends RuntimeException
{
    public function __construct(
        private readonly int $statusHttp,
        private readonly string $publicCode
    ) {
        parent::__construct($publicCode);
    }

    public function getStatusHttp(): int
    {
        return $this->statusHttp;
    }

    public function getPublicCode(): string
    {
        return $this->publicCode;
    }
}

final class RokuAudioFallbackStatusEndpointRequest
{
    private readonly Closure $bodyReader;
    private bool $bodyRead = false;

    /**
     * @param array<mixed> $query
     * @param callable(): mixed $bodyReader
     */
    public function __construct(
        public readonly mixed $method,
        public readonly mixed $contentType,
        public readonly mixed $contentLength,
        public readonly array $query,
        callable $bodyReader
    ) {
        $this->bodyReader = Closure::fromCallable($bodyReader);
    }

    public function readBody(): string
    {
        if ($this->bodyRead) {
            throw new RokuAudioFallbackStatusEndpointException(
                400,
                'INVALID_REQUEST'
            );
        }
        $this->bodyRead = true;
        $body = ($this->bodyReader)();
        if (!is_string($body)) {
            throw new RokuAudioFallbackStatusEndpointException(
                400,
                'INVALID_REQUEST'
            );
        }
        return $body;
    }
}

final readonly class RokuAudioFallbackStatusEndpointResponse
{
    /**
     * @param array<string,mixed> $body
     * @param list<string> $headers
     */
    public function __construct(
        private int $statusHttp,
        private array $body,
        private array $headers
    ) {
    }

    public function getStatusHttp(): int
    {
        return $this->statusHttp;
    }

    /** @return array<string,mixed> */
    public function getBody(): array
    {
        return $this->body;
    }

    /** @return list<string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}

interface RokuAudioFallbackStatusEndpointOperation
{
    public function getStatus(
        int $clienteId,
        string $internalSessionId,
        string $requestId
    ): RokuAudioFallbackServiceResult;
}

interface RokuAudioFallbackStatusEndpointDependencies
{
    public function authenticate(): int;

    public function operation(): RokuAudioFallbackStatusEndpointOperation;
}

final class RokuAudioFallbackStatusServiceOperation
    implements RokuAudioFallbackStatusEndpointOperation
{
    public function __construct(private readonly RokuAudioFallbackService $service)
    {
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
}

final class RokuAudioFallbackStatusProductionDependencies
    implements RokuAudioFallbackStatusEndpointDependencies
{
    private ?PDO $pdo = null;

    public function authenticate(): int
    {
        try {
            $this->pdo = criarConexaoContextoXtreamRoku();
            $authenticated = autenticarTokenRoku($this->pdo);
        } catch (RokuAuthException $exception) {
            throw new RokuAudioFallbackStatusEndpointException(
                $exception->getStatusHttp(),
                $exception->getCodigoPublico()
            );
        } catch (Throwable) {
            throw new RokuAudioFallbackStatusEndpointException(
                500,
                'INTERNAL_ERROR'
            );
        }

        $clienteId = $authenticated['cliente_id'] ?? null;
        if (!is_int($clienteId) || $clienteId < 1 || $clienteId > 2147483647) {
            throw new RokuAudioFallbackStatusEndpointException(
                500,
                'INTERNAL_ERROR'
            );
        }
        return $clienteId;
    }

    public function operation(): RokuAudioFallbackStatusEndpointOperation
    {
        if (!$this->pdo instanceof PDO) {
            throw new RokuAudioFallbackStatusEndpointException(
                500,
                'INTERNAL_ERROR'
            );
        }
        try {
            $service = (new RokuAudioFallbackProductionBootstrap())->build($this->pdo);
            return new RokuAudioFallbackStatusServiceOperation($service);
        } catch (Throwable) {
            throw new RokuAudioFallbackStatusEndpointException(
                500,
                'INTERNAL_ERROR'
            );
        }
    }
}

final class RokuAudioFallbackStatusEndpointHandler
{
    private const MAX_BODY_BYTES = 16384;
    private const PUBLIC_STATUSES = [
        'preparing', 'ready', 'cancelled', 'failed', 'expired',
    ];
    private const HEADERS = [
        'Content-Type: application/json; charset=utf-8',
        'Cache-Control: no-store, max-age=0',
        'Pragma: no-cache',
        'X-Content-Type-Options: nosniff',
    ];

    public function handle(
        RokuAudioFallbackStatusEndpointRequest $request,
        RokuAudioFallbackStatusEndpointDependencies $dependencies
    ): RokuAudioFallbackStatusEndpointResponse {
        try {
            $input = $this->validateRequest($request);
            $clienteId = $dependencies->authenticate();
            if ($clienteId < 1 || $clienteId > 2147483647) {
                throw new RokuAudioFallbackStatusEndpointException(
                    500,
                    'INTERNAL_ERROR'
                );
            }
            $result = $dependencies->operation()->getStatus(
                $clienteId,
                $input['internal_session_id'],
                $input['request_id']
            );
            return $this->success($result, $input['internal_session_id']);
        } catch (RokuAudioFallbackStatusEndpointException $exception) {
            return $this->error(
                $exception->getStatusHttp(),
                $exception->getPublicCode(),
                $exception->getStatusHttp() === 405
            );
        } catch (RokuAudioFallbackServiceException $exception) {
            [$status, $code] = self::mapServiceError($exception->getMessage());
            return $this->error($status, $code);
        } catch (Throwable) {
            return $this->error(500, 'INTERNAL_ERROR');
        }
    }

    /** @return array{internal_session_id:string,request_id:string} */
    private function validateRequest(
        RokuAudioFallbackStatusEndpointRequest $request
    ): array {
        if ($request->method !== 'POST') {
            throw new RokuAudioFallbackStatusEndpointException(
                405,
                'METHOD_NOT_ALLOWED'
            );
        }
        if ($request->query !== []) {
            throw new RokuAudioFallbackStatusEndpointException(
                400,
                'INVALID_REQUEST'
            );
        }
        if (!self::validContentType($request->contentType)) {
            throw new RokuAudioFallbackStatusEndpointException(
                415,
                'UNSUPPORTED_MEDIA_TYPE'
            );
        }
        self::validateContentLength($request->contentLength);
        $body = $request->readBody();
        $length = strlen($body);
        if ($length === 0 || $length > self::MAX_BODY_BYTES) {
            throw new RokuAudioFallbackStatusEndpointException(
                $length > self::MAX_BODY_BYTES ? 413 : 400,
                $length > self::MAX_BODY_BYTES ? 'PAYLOAD_TOO_LARGE' : 'INVALID_REQUEST'
            );
        }
        if (str_contains($body, "\0")) {
            throw new RokuAudioFallbackStatusEndpointException(
                400,
                'INVALID_REQUEST'
            );
        }
        try {
            $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RokuAudioFallbackStatusEndpointException(400, 'INVALID_JSON');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RokuAudioFallbackStatusEndpointException(
                400,
                'INVALID_REQUEST'
            );
        }
        $allowed = ['internal_session_id', 'request_id'];
        if (
            count($decoded) !== 2
            || array_diff(array_keys($decoded), $allowed) !== []
            || array_diff($allowed, array_keys($decoded)) !== []
            || self::rootObjectHasDuplicateKeys($body)
        ) {
            throw new RokuAudioFallbackStatusEndpointException(
                400,
                'INVALID_REQUEST'
            );
        }

        $internalSessionId = $decoded['internal_session_id'];
        $requestId = $decoded['request_id'];
        if (
            !is_string($internalSessionId)
            || preg_match(
                '/\Araf_[A-Za-z0-9_-]{43}\z/D',
                $internalSessionId
            ) !== 1
        ) {
            throw new RokuAudioFallbackStatusEndpointException(
                400,
                'INVALID_REQUEST'
            );
        }
        try {
            RokuAudioFallbackIdempotency::validarRequestId($requestId);
        } catch (RokuAudioFallbackIdempotencyException) {
            throw new RokuAudioFallbackStatusEndpointException(
                400,
                'INVALID_REQUEST'
            );
        }
        return [
            'internal_session_id' => $internalSessionId,
            'request_id' => $requestId,
        ];
    }

    private static function validContentType(mixed $contentType): bool
    {
        if (!is_string($contentType)) {
            return false;
        }
        return preg_match(
            '/\Aapplication\/json(?:\s*;\s*charset\s*=\s*(?:utf-8|"utf-8"))?\z/iD',
            $contentType
        ) === 1;
    }

    private static function validateContentLength(mixed $contentLength): void
    {
        if ($contentLength === null) {
            return;
        }
        $tooLarge = is_string($contentLength)
            && ctype_digit($contentLength)
            && strlen($contentLength) <= 10
            && (int) $contentLength > self::MAX_BODY_BYTES;
        if (
            !is_string($contentLength)
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $contentLength) !== 1
            || strlen($contentLength) > 5
            || (int) $contentLength > self::MAX_BODY_BYTES
        ) {
            throw new RokuAudioFallbackStatusEndpointException(
                $tooLarge ? 413 : 400,
                $tooLarge ? 'PAYLOAD_TOO_LARGE' : 'INVALID_REQUEST'
            );
        }
    }

    private static function rootObjectHasDuplicateKeys(string $json): bool
    {
        preg_match_all(
            '/(?:\A|,)\s*"((?:\\\\.|[^"\\\\])*)"\s*:/',
            trim($json, " \t\r\n{}"),
            $matches
        );
        $seen = [];
        foreach ($matches[1] as $encodedKey) {
            try {
                $key = json_decode('"' . $encodedKey . '"', true, 2, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return true;
            }
            if (!is_string($key) || isset($seen[$key])) {
                return true;
            }
            $seen[$key] = true;
        }
        return false;
    }

    private function success(
        RokuAudioFallbackServiceResult $result,
        string $expectedId
    ): RokuAudioFallbackStatusEndpointResponse {
        $id = $result->getId();
        $status = $result->getStatus();
        $expiresAt = $result->getExpiresAt();
        $playbackUrl = $result->getPlaybackUrl();
        if (
            !hash_equals($expectedId, $id)
            || !in_array($status, self::PUBLIC_STATUSES, true)
            || preg_match(
                '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',
                $expiresAt
            ) !== 1
            || $status === 'ready' && !self::validPlaybackUrl($playbackUrl)
            || $status !== 'ready' && $playbackUrl !== null
        ) {
            throw new RokuAudioFallbackStatusEndpointException(
                500,
                'INTERNAL_ERROR'
            );
        }
        $session = [
            'id' => $id,
            'status' => $status,
            'expires_at' => $expiresAt,
        ];
        if ($playbackUrl !== null) {
            $session['playback_url'] = $playbackUrl;
        }
        return new RokuAudioFallbackStatusEndpointResponse(
            200,
            ['ok' => true, 'session' => $session],
            self::HEADERS
        );
    }

    private static function validPlaybackUrl(mixed $url): bool
    {
        if (
            !is_string($url)
            || $url === ''
            || strlen($url) > 4096
            || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1
        ) {
            return false;
        }
        try {
            $parts = parse_url($url);
        } catch (Throwable) {
            return false;
        }
        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment']);
    }

    private function error(
        int $status,
        string $code,
        bool $allowPost = false
    ): RokuAudioFallbackStatusEndpointResponse {
        $headers = self::HEADERS;
        if ($allowPost) {
            $headers[] = 'Allow: POST';
        }
        return new RokuAudioFallbackStatusEndpointResponse(
            $status,
            ['ok' => false, 'error' => ['code' => $code]],
            $headers
        );
    }

    /** @return array{int,string} */
    private static function mapServiceError(string $code): array
    {
        return match ($code) {
            'ROKU_AUDIO_FALLBACK_INVALID_ARGUMENT' => [400, 'INVALID_REQUEST'],
            'ROKU_AUDIO_FALLBACK_NOT_FOUND' => [404, 'FALLBACK_SESSION_NOT_FOUND'],
            'ROKU_AUDIO_FALLBACK_CONFLICT' => [409, 'FALLBACK_CONFLICT'],
            'ROKU_AUDIO_FALLBACK_CAPACITY_EXCEEDED' => [429, 'FALLBACK_CAPACITY_EXCEEDED'],
            'ROKU_AUDIO_FALLBACK_DISABLED' => [503, 'FALLBACK_DISABLED'],
            'ROKU_AUDIO_FALLBACK_TRANSCODER_UNAVAILABLE',
            'ROKU_AUDIO_FALLBACK_RESULT_INDETERMINATE' =>
                [503, 'FALLBACK_UNAVAILABLE'],
            'ROKU_AUDIO_FALLBACK_UPSTREAM_REJECTED' =>
                [409, 'FALLBACK_UPSTREAM_REJECTED'],
            default => [500, 'INTERNAL_ERROR'],
        };
    }
}

final class RokuAudioFallbackStatusEndpointRunner
{
    public function run(): never
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? null;
        $contentLength = $_SERVER['HTTP_CONTENT_LENGTH']
            ?? $_SERVER['CONTENT_LENGTH']
            ?? null;
        $request = new RokuAudioFallbackStatusEndpointRequest(
            $method,
            $contentType,
            $contentLength,
            $_GET,
            static function (): string {
                $stream = @fopen('php://input', 'rb');
                if ($stream === false) {
                    throw new RokuAudioFallbackStatusEndpointException(
                        400,
                        'INVALID_REQUEST'
                    );
                }
                try {
                    $body = @stream_get_contents($stream, 16385);
                } finally {
                    fclose($stream);
                }
                if (!is_string($body)) {
                    throw new RokuAudioFallbackStatusEndpointException(
                        400,
                        'INVALID_REQUEST'
                    );
                }
                return $body;
            }
        );
        $response = (new RokuAudioFallbackStatusEndpointHandler())->handle(
            $request,
            new RokuAudioFallbackStatusProductionDependencies()
        );
        http_response_code($response->getStatusHttp());
        foreach ($response->getHeaders() as $header) {
            header($header);
        }
        try {
            echo json_encode(
                $response->getBody(),
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            );
        } catch (Throwable) {
            http_response_code(500);
            echo '{"ok":false,"error":{"code":"INTERNAL_ERROR"}}';
        }
        exit;
    }
}

function rokuAudioFallbackStatusEndpointExecutedDirectly(): bool
{
    $script = $_SERVER['SCRIPT_FILENAME'] ?? null;
    return is_string($script)
        && $script !== ''
        && realpath($script) === __FILE__;
}

if (rokuAudioFallbackStatusEndpointExecutedDirectly()) {
    (new RokuAudioFallbackStatusEndpointRunner())->run();
}
