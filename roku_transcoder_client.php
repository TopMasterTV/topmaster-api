<?php

declare(strict_types=1);

require_once __DIR__ . '/roku_transcoder_hmac.php';

final class RokuTranscoderClientException extends RuntimeException
{
    private const ALLOWED_CODES = [
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

    public function __construct(string $errorCode, private readonly ?int $upstreamStatus = null)
    {
        if (!in_array($errorCode, self::ALLOWED_CODES, true)) {
            $errorCode = 'ROKU_TRANSCODER_CLIENT_TRANSPORT_FAILED';
        }

        parent::__construct($errorCode);
    }

    public function getUpstreamStatus(): ?int
    {
        return $this->upstreamStatus;
    }
}

final readonly class RokuTranscoderHttpRequest
{
    /**
     * @param array<string,string> $headers
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public string $body,
        public int $connectTimeoutMs,
        public int $timeoutMs,
        public int $maxResponseBytes
    ) {
    }
}

final readonly class RokuTranscoderHttpResponse
{
    public ?string $contentType;

    public function __construct(
        public int $status,
        ?string $contentType,
        public string $body
    ) {
        $mediaType = $contentType === null ? null : strtolower(trim(explode(';', $contentType, 2)[0]));
        $this->contentType = $mediaType === '' ? null : $mediaType;
    }
}

interface RokuTranscoderHttpTransport
{
    public function send(RokuTranscoderHttpRequest $request): RokuTranscoderHttpResponse;
}

final class RokuTranscoderCurlTransport implements RokuTranscoderHttpTransport
{
    public function send(RokuTranscoderHttpRequest $request): RokuTranscoderHttpResponse
    {
        if (!extension_loaded('curl') || !function_exists('curl_init')) {
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_CURL_UNAVAILABLE');
        }

        $handle = curl_init();
        if ($handle === false) {
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_TRANSPORT_FAILED');
        }

        $body = '';
        $tooLarge = false;
        $contentType = null;
        $headerLines = [];
        foreach ($request->headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT_MS => $request->connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $request->timeoutMs,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_NOPROGRESS => true,
            CURLOPT_VERBOSE => false,
            CURLOPT_FAILONERROR => false,
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => static function ($unused, string $chunk) use (
                &$body,
                &$tooLarge,
                $request
            ): int {
                if (strlen($body) + strlen($chunk) > $request->maxResponseBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($unused, string $line) use (&$contentType): int {
                if (stripos($line, 'Content-Type:') === 0) {
                    $contentType = trim(substr($line, strlen('Content-Type:')));
                }
                return strlen($line);
            },
        ];

        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS')) {
            $options[CURLOPT_REDIR_PROTOCOLS] = 0;
        }
        if ($request->body !== '') {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }

        try {
            if (!curl_setopt_array($handle, $options)) {
                throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_TRANSPORT_FAILED');
            }
            $executed = curl_exec($handle);
            if ($executed === false) {
                if ($tooLarge) {
                    throw new RokuTranscoderClientException(
                        'ROKU_TRANSCODER_CLIENT_RESPONSE_TOO_LARGE'
                    );
                }
                if (curl_errno($handle) === CURLE_OPERATION_TIMEDOUT) {
                    throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_TIMEOUT');
                }
                throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_TRANSPORT_FAILED');
            }
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if (!is_int($status)) {
                throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_INVALID_RESPONSE');
            }
        } finally {
            curl_close($handle);
        }

        return new RokuTranscoderHttpResponse($status, $contentType, $body);
    }
}

final class RokuTranscoderClient
{
    private const USER_AGENT = 'TopMaster-Roku-Backend/1.0';
    private const MAX_INTERNAL_BODY_BYTES = 65536;
    private const STATES = [
        'created', 'validating', 'starting', 'preparing', 'ready', 'streaming',
        'cancelling', 'cancelled', 'finished', 'expired', 'failed',
    ];

    private readonly string $baseUrl;
    private readonly string $hmacSecret;
    private readonly Closure $clock;
    private readonly Closure $nonceGenerator;

    public function __construct(
        mixed $baseUrl,
        mixed $hmacSecret,
        private readonly RokuTranscoderHttpTransport $transport,
        mixed $connectTimeoutMs = 2000,
        mixed $timeoutMs = 10000,
        mixed $maxResponseBytes = 65536,
        ?callable $clock = null,
        ?callable $nonceGenerator = null
    ) {
        $this->baseUrl = self::validateBaseUrl($baseUrl);
        $this->hmacSecret = self::validateSecret($hmacSecret);
        self::validateLimits($connectTimeoutMs, $timeoutMs, $maxResponseBytes);
        $this->connectTimeoutMs = $connectTimeoutMs;
        $this->timeoutMs = $timeoutMs;
        $this->maxResponseBytes = $maxResponseBytes;
        $this->clock = Closure::fromCallable($clock ?? static fn (): int => time());
        $this->nonceGenerator = Closure::fromCallable(
            $nonceGenerator ?? static fn (): string => RokuTranscoderHmac::generateNonce()
        );
    }

    private readonly int $connectTimeoutMs;
    private readonly int $timeoutMs;
    private readonly int $maxResponseBytes;

    /**
     * @return array{id:string,status:string,created_at:string,expires_at:string,last_access_at:string}
     */
    public function createSession(
        mixed $internalSessionId,
        mixed $publicTokenHash,
        mixed $clienteId,
        mixed $sistemaId,
        mixed $streamId,
        mixed $sourceUrl,
        mixed $extension,
        mixed $expiresAt
    ): array {
        $payload = [
            'internal_session_id' => self::validateIdentifier($internalSessionId),
            'public_token_hash' => self::validateLowerHexHash($publicTokenHash),
            'cliente_id' => self::validateDatabaseId($clienteId),
            'sistema_id' => self::validateDatabaseId($sistemaId),
            'stream_id' => self::validateStreamId($streamId),
            'source_url' => self::validateSourceUrl($sourceUrl),
            'extension' => self::validateExtension($extension),
            'expires_at' => self::validateRfc3339($expiresAt),
        ];

        try {
            $body = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException) {
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_INVALID_PAYLOAD');
        }
        if (!is_string($body) || strlen($body) > self::MAX_INTERNAL_BODY_BYTES) {
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_INVALID_PAYLOAD');
        }

        return $this->execute('POST', '/internal/sessions', $body, 202, true);
    }

    /**
     * @return array{id:string,status:string,created_at:string,expires_at:string,last_access_at:string}
     */
    public function getStatus(mixed $internalSessionId): array
    {
        $path = '/internal/sessions/' . self::validateIdentifier($internalSessionId) . '/status';
        return $this->execute('GET', $path, '', 200, false);
    }

    /**
     * @return array{id:string,status:string,created_at:string,expires_at:string,last_access_at:string}
     */
    public function cancelSession(mixed $internalSessionId): array
    {
        $path = '/internal/sessions/' . self::validateIdentifier($internalSessionId);
        return $this->execute('DELETE', $path, '', 200, false);
    }

    /**
     * @return array{id:string,status:string,created_at:string,expires_at:string,last_access_at:string}
     */
    private function execute(
        string $method,
        string $path,
        string $body,
        int $expectedStatus,
        bool $hasJsonBody
    ): array {
        $timestamp = ($this->clock)();
        $nonce = ($this->nonceGenerator)();
        try {
            $signed = RokuTranscoderHmac::sign(
                $method,
                $path,
                $timestamp,
                $nonce,
                $body,
                $this->hmacSecret
            );
        } catch (RokuTranscoderHmacException) {
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_INVALID_CONFIG');
        }

        $headers = [
            'Accept' => 'application/json',
            'Accept-Encoding' => 'identity',
            'User-Agent' => self::USER_AGENT,
        ];
        if ($hasJsonBody) {
            $headers = ['Content-Type' => 'application/json'] + $headers;
        }
        $headers += $signed['headers'];

        $request = new RokuTranscoderHttpRequest(
            $method,
            $this->baseUrl . $path,
            $headers,
            $body,
            $this->connectTimeoutMs,
            $this->timeoutMs,
            $this->maxResponseBytes
        );

        try {
            $response = $this->transport->send($request);
        } catch (RokuTranscoderClientException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_TRANSPORT_FAILED');
        }

        if (strlen($response->body) > $this->maxResponseBytes) {
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_RESPONSE_TOO_LARGE');
        }
        if ($response->contentType !== 'application/json') {
            throw new RokuTranscoderClientException(
                'ROKU_TRANSCODER_CLIENT_INVALID_CONTENT_TYPE',
                $response->status
            );
        }

        try {
            $decoded = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RokuTranscoderClientException(
                'ROKU_TRANSCODER_CLIENT_INVALID_RESPONSE',
                $response->status
            );
        }
        if (!is_array($decoded)) {
            throw new RokuTranscoderClientException(
                'ROKU_TRANSCODER_CLIENT_INVALID_RESPONSE',
                $response->status
            );
        }

        if ($response->status !== $expectedStatus) {
            self::throwMappedStatus($response->status, $decoded);
        }

        return self::validateSuccessResponse($decoded);
    }

    /**
     * @param array<mixed> $decoded
     * @return never
     */
    private static function throwMappedStatus(int $status, array $decoded): never
    {
        if (
            ($decoded['ok'] ?? null) !== false
            || !is_string($decoded['error'] ?? null)
            || count($decoded) !== 2
        ) {
            throw new RokuTranscoderClientException(
                'ROKU_TRANSCODER_CLIENT_INVALID_RESPONSE',
                $status
            );
        }

        $code = match ($status) {
            401 => 'ROKU_TRANSCODER_CLIENT_UNAUTHORIZED',
            403 => 'ROKU_TRANSCODER_CLIENT_FORBIDDEN',
            404 => 'ROKU_TRANSCODER_CLIENT_NOT_FOUND',
            409 => 'ROKU_TRANSCODER_CLIENT_CONFLICT',
            429 => 'ROKU_TRANSCODER_CLIENT_CAPACITY_EXCEEDED',
            500, 502, 503, 504 => 'ROKU_TRANSCODER_CLIENT_UPSTREAM_FAILED',
            default => 'ROKU_TRANSCODER_CLIENT_UPSTREAM_REJECTED',
        };
        throw new RokuTranscoderClientException($code, $status);
    }

    /**
     * @param array<mixed> $decoded
     * @return array{id:string,status:string,created_at:string,expires_at:string,last_access_at:string}
     */
    private static function validateSuccessResponse(array $decoded): array
    {
        if (($decoded['ok'] ?? null) !== true || !is_array($decoded['session'] ?? null)) {
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_INVALID_RESPONSE');
        }
        $session = $decoded['session'];
        if (
            count($decoded) !== 2
            || count($session) !== 5
            || !is_string($session['id'] ?? null)
            || preg_match('/\A[A-Za-z0-9_-]{16,128}\z/', $session['id']) !== 1
            || !is_string($session['status'] ?? null)
            || !in_array($session['status'], self::STATES, true)
        ) {
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_INVALID_RESPONSE');
        }
        foreach (['created_at', 'expires_at', 'last_access_at'] as $field) {
            try {
                self::validateRfc3339($session[$field] ?? null);
            } catch (RokuTranscoderClientException) {
                throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_INVALID_RESPONSE');
            }
        }

        return [
            'id' => $session['id'],
            'status' => $session['status'],
            'created_at' => $session['created_at'],
            'expires_at' => $session['expires_at'],
            'last_access_at' => $session['last_access_at'],
        ];
    }

    private static function validateBaseUrl(mixed $baseUrl): string
    {
        if (
            !is_string($baseUrl)
            || $baseUrl === ''
            || strlen($baseUrl) > 2048
            || preg_match('/[\x00\r\n\t\\\\%]/', $baseUrl) === 1
        ) {
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_INVALID_BASE_URL');
        }
        try {
            $parts = parse_url($baseUrl);
        } catch (Throwable) {
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_INVALID_BASE_URL');
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
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_INVALID_BASE_URL');
        }

        return str_ends_with($baseUrl, '/') ? substr($baseUrl, 0, -1) : $baseUrl;
    }

    private static function validateSecret(mixed $secret): string
    {
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_INVALID_CONFIG');
        }
        return $secret;
    }

    private static function validateLimits(mixed $connect, mixed $total, mixed $response): void
    {
        if (
            !is_int($connect) || $connect < 100 || $connect > 10000
            || !is_int($total) || $total < 500 || $total > 30000 || $total < $connect
            || !is_int($response) || $response < 1024 || $response > 1048576
        ) {
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_INVALID_CONFIG');
        }
    }

    private static function validateIdentifier(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[A-Za-z0-9_-]{16,128}\z/', $value) !== 1) {
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_INVALID_ARGUMENT');
        }
        return $value;
    }

    private static function validateLowerHexHash(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[0-9a-f]{64}\z/', $value) !== 1) {
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_INVALID_PAYLOAD');
        }
        return $value;
    }

    private static function validateDatabaseId(mixed $value): int
    {
        if (!is_int($value) || $value < 1 || $value > 2147483647) {
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_INVALID_PAYLOAD');
        }
        return $value;
    }

    private static function validateStreamId(mixed $value): string
    {
        if (
            !is_string($value) || $value === '' || strlen($value) > 512
            || preg_match('/[\x00\r\n]/', $value) === 1
            || preg_match('/\A\s+\z/u', $value) === 1
            || preg_match('/\A[A-Za-z][A-Za-z0-9+.-]*:/', $value) === 1
            || str_starts_with($value, '//')
        ) {
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_INVALID_PAYLOAD');
        }
        return $value;
    }

    private static function validateSourceUrl(mixed $value): string
    {
        if (
            !is_string($value) || $value === '' || strlen($value) > 4096
            || preg_match('/[\x00\r\n\t\\\\]/', $value) === 1
        ) {
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_INVALID_PAYLOAD');
        }
        try {
            $parts = parse_url($value);
        } catch (Throwable) {
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_INVALID_PAYLOAD');
        }
        if (
            !is_array($parts)
            || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || preg_match('/\A[A-Za-z0-9.-]+\z/', $parts['host']) !== 1
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        ) {
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_INVALID_PAYLOAD');
        }
        return $value;
    }

    private static function validateExtension(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['mp4', 'mov', 'm4v', 'mkv'], true)) {
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_INVALID_PAYLOAD');
        }
        return $value;
    }

    private static function validateRfc3339(mixed $value): string
    {
        if (
            !is_string($value)
            || preg_match(
                '/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/',
                $value,
                $matches
            ) !== 1
            || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
            || (int) $matches[4] > 23
            || (int) $matches[5] > 59
            || (int) $matches[6] > 59
        ) {
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_INVALID_PAYLOAD');
        }
        try {
            new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new RokuTranscoderClientException('ROKU_TRANSCODER_CLIENT_INVALID_PAYLOAD');
        }
        return $value;
    }
}
