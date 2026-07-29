<?php

declare(strict_types=1);

require_once __DIR__ . '/roku_audio_fallback_bootstrap.php';
require_once __DIR__ . '/roku_audio_fallback_idempotency.php';
require_once __DIR__ . '/roku_obter_contexto_xtream.php';

final class RokuAudioFallbackCreateEndpointException extends RuntimeException
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

final class RokuAudioFallbackCreateEndpointRequest
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
            throw new RokuAudioFallbackCreateEndpointException(
                400,
                'INVALID_REQUEST'
            );
        }
        $this->bodyRead = true;
        $body = ($this->bodyReader)();
        if (!is_string($body)) {
            throw new RokuAudioFallbackCreateEndpointException(
                400,
                'INVALID_REQUEST'
            );
        }
        return $body;
    }
}

final readonly class RokuAudioFallbackCreateEndpointResponse
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

interface RokuAudioFallbackCreateEndpointOperation
{
    public function createSession(
        int $clienteId,
        int $sistemaId,
        string $streamId,
        string $extension,
        string $requestId
    ): RokuAudioFallbackServiceResult;
}

interface RokuAudioFallbackCreateEndpointDependencies
{
    public function authenticate(): int;

    public function operation(): RokuAudioFallbackCreateEndpointOperation;
}

final class RokuAudioFallbackCreateServiceOperation
    implements RokuAudioFallbackCreateEndpointOperation
{
    public function __construct(private readonly RokuAudioFallbackService $service)
    {
    }

    public function createSession(
        int $clienteId,
        int $sistemaId,
        string $streamId,
        string $extension,
        string $requestId
    ): RokuAudioFallbackServiceResult {
        return $this->service->createSession(
            $clienteId,
            $sistemaId,
            $streamId,
            $extension,
            $requestId
        );
    }
}

final class RokuAudioFallbackCreateProductionDependencies
    implements RokuAudioFallbackCreateEndpointDependencies
{
    private ?PDO $pdo = null;

    public function authenticate(): int
    {
        try {
            $this->pdo = criarConexaoContextoXtreamRoku();
            $authenticated = autenticarTokenRoku($this->pdo);
        } catch (RokuAuthException $exception) {
            throw new RokuAudioFallbackCreateEndpointException(
                $exception->getStatusHttp(),
                $exception->getCodigoPublico()
            );
        } catch (Throwable) {
            throw new RokuAudioFallbackCreateEndpointException(
                500,
                'INTERNAL_ERROR'
            );
        }

        $clienteId = $authenticated['cliente_id'] ?? null;
        if (!is_int($clienteId) || $clienteId < 1 || $clienteId > 2147483647) {
            throw new RokuAudioFallbackCreateEndpointException(
                500,
                'INTERNAL_ERROR'
            );
        }
        return $clienteId;
    }

    public function operation(): RokuAudioFallbackCreateEndpointOperation
    {
        if (!$this->pdo instanceof PDO) {
            throw new RokuAudioFallbackCreateEndpointException(
                500,
                'INTERNAL_ERROR'
            );
        }
        try {
            $service = (new RokuAudioFallbackProductionBootstrap())->build($this->pdo);
            return new RokuAudioFallbackCreateServiceOperation($service);
        } catch (Throwable) {
            throw new RokuAudioFallbackCreateEndpointException(
                500,
                'INTERNAL_ERROR'
            );
        }
    }
}

final class RokuAudioFallbackCreateEndpointHandler
{
    private const MAX_BODY_BYTES = 16384;
    private const HEADERS = [
        'Content-Type: application/json; charset=utf-8',
        'Cache-Control: no-store, max-age=0',
        'Pragma: no-cache',
        'X-Content-Type-Options: nosniff',
    ];

    public function handle(
        RokuAudioFallbackCreateEndpointRequest $request,
        RokuAudioFallbackCreateEndpointDependencies $dependencies
    ): RokuAudioFallbackCreateEndpointResponse {
        try {
            $input = $this->validateRequest($request);
            $clienteId = $dependencies->authenticate();
            if ($clienteId < 1 || $clienteId > 2147483647) {
                throw new RokuAudioFallbackCreateEndpointException(
                    500,
                    'INTERNAL_ERROR'
                );
            }
            $result = $dependencies->operation()->createSession(
                $clienteId,
                $input['sistema_id'],
                $input['stream_id'],
                $input['extension'],
                $input['request_id']
            );
            return $this->success($result);
        } catch (RokuAudioFallbackCreateEndpointException $exception) {
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

    /** @return array{sistema_id:int,stream_id:string,extension:string,request_id:string} */
    private function validateRequest(
        RokuAudioFallbackCreateEndpointRequest $request
    ): array {
        if ($request->method !== 'POST') {
            throw new RokuAudioFallbackCreateEndpointException(
                405,
                'METHOD_NOT_ALLOWED'
            );
        }
        if ($request->query !== []) {
            throw new RokuAudioFallbackCreateEndpointException(
                400,
                'INVALID_REQUEST'
            );
        }
        if (!self::validContentType($request->contentType)) {
            throw new RokuAudioFallbackCreateEndpointException(
                415,
                'UNSUPPORTED_MEDIA_TYPE'
            );
        }
        self::validateContentLength($request->contentLength);
        $body = $request->readBody();
        $length = strlen($body);
        if ($length === 0 || $length > self::MAX_BODY_BYTES) {
            throw new RokuAudioFallbackCreateEndpointException(
                $length > self::MAX_BODY_BYTES ? 413 : 400,
                $length > self::MAX_BODY_BYTES ? 'PAYLOAD_TOO_LARGE' : 'INVALID_REQUEST'
            );
        }
        if (str_contains($body, "\0")) {
            throw new RokuAudioFallbackCreateEndpointException(
                400,
                'INVALID_REQUEST'
            );
        }
        try {
            $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RokuAudioFallbackCreateEndpointException(400, 'INVALID_JSON');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RokuAudioFallbackCreateEndpointException(
                400,
                'INVALID_REQUEST'
            );
        }
        $allowed = ['sistema_id', 'stream_id', 'extension', 'request_id'];
        if (
            count($decoded) !== 4
            || array_diff(array_keys($decoded), $allowed) !== []
            || array_diff($allowed, array_keys($decoded)) !== []
            || self::rootObjectHasDuplicateKeys($body)
        ) {
            throw new RokuAudioFallbackCreateEndpointException(
                400,
                'INVALID_REQUEST'
            );
        }

        $sistemaId = $decoded['sistema_id'];
        $streamId = $decoded['stream_id'];
        $extension = $decoded['extension'];
        $requestId = $decoded['request_id'];
        if (!is_int($sistemaId) || $sistemaId < 1 || $sistemaId > 2147483647) {
            throw new RokuAudioFallbackCreateEndpointException(400, 'INVALID_REQUEST');
        }
        if (
            !is_string($streamId)
            || $streamId === ''
            || strlen($streamId) > 512
            || preg_match('/[\x00\r\n\/\\\\?#]/', $streamId) === 1
            || str_contains($streamId, '..')
            || preg_match('/\A[A-Za-z][A-Za-z0-9+.-]*:/D', $streamId) === 1
        ) {
            throw new RokuAudioFallbackCreateEndpointException(400, 'INVALID_REQUEST');
        }
        if (
            !is_string($extension)
            || !in_array($extension, ['mp4', 'mov', 'm4v', 'mkv'], true)
        ) {
            throw new RokuAudioFallbackCreateEndpointException(400, 'INVALID_REQUEST');
        }
        try {
            RokuAudioFallbackIdempotency::validarRequestId($requestId);
        } catch (RokuAudioFallbackIdempotencyException) {
            throw new RokuAudioFallbackCreateEndpointException(400, 'INVALID_REQUEST');
        }
        return [
            'sistema_id' => $sistemaId,
            'stream_id' => $streamId,
            'extension' => $extension,
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
        if (
            !is_string($contentLength)
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $contentLength) !== 1
            || strlen($contentLength) > 5
            || (int) $contentLength > self::MAX_BODY_BYTES
        ) {
            throw new RokuAudioFallbackCreateEndpointException(
                is_string($contentLength)
                && ctype_digit($contentLength)
                && (int) $contentLength > self::MAX_BODY_BYTES
                    ? 413
                    : 400,
                is_string($contentLength)
                && ctype_digit($contentLength)
                && (int) $contentLength > self::MAX_BODY_BYTES
                    ? 'PAYLOAD_TOO_LARGE'
                    : 'INVALID_REQUEST'
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
        RokuAudioFallbackServiceResult $result
    ): RokuAudioFallbackCreateEndpointResponse {
        $status = $result->getStatus();
        $session = [
            'id' => $result->getId(),
            'status' => $status,
            'expires_at' => $result->getExpiresAt(),
        ];
        $playbackUrl = $result->getPlaybackUrl();
        if ($playbackUrl !== null) {
            $session['playback_url'] = $playbackUrl;
        }
        return new RokuAudioFallbackCreateEndpointResponse(
            $status === 'preparing' ? 202 : 200,
            ['ok' => true, 'session' => $session],
            self::HEADERS
        );
    }

    private function error(
        int $status,
        string $code,
        bool $allowPost = false
    ): RokuAudioFallbackCreateEndpointResponse {
        $headers = self::HEADERS;
        if ($allowPost) {
            $headers[] = 'Allow: POST';
        }
        return new RokuAudioFallbackCreateEndpointResponse(
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
            'ROKU_AUDIO_FALLBACK_NOT_FOUND',
            'ROKU_AUDIO_FALLBACK_SOURCE_FAILED' => [404, 'FALLBACK_SOURCE_NOT_FOUND'],
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

final class RokuAudioFallbackCreateEndpointRunner
{
    public function run(): never
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? null;
        $contentLength = $_SERVER['HTTP_CONTENT_LENGTH']
            ?? $_SERVER['CONTENT_LENGTH']
            ?? null;
        $request = new RokuAudioFallbackCreateEndpointRequest(
            $method,
            $contentType,
            $contentLength,
            $_GET,
            static function (): string {
                $stream = @fopen('php://input', 'rb');
                if ($stream === false) {
                    throw new RokuAudioFallbackCreateEndpointException(
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
                    throw new RokuAudioFallbackCreateEndpointException(
                        400,
                        'INVALID_REQUEST'
                    );
                }
                return $body;
            }
        );
        $response = (new RokuAudioFallbackCreateEndpointHandler())->handle(
            $request,
            new RokuAudioFallbackCreateProductionDependencies()
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

function rokuAudioFallbackCreateEndpointExecutedDirectly(): bool
{
    $script = $_SERVER['SCRIPT_FILENAME'] ?? null;
    return is_string($script)
        && $script !== ''
        && realpath($script) === __FILE__;
}

if (rokuAudioFallbackCreateEndpointExecutedDirectly()) {
    (new RokuAudioFallbackCreateEndpointRunner())->run();
}
