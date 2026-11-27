<?php

declare(strict_types=1);

interface TopGestorProbeApiClient
{
    /** @return array<string, mixed> */
    public function fetchCustomer(string $customerId): array;
}

final class TopGestorRenewalCapture
{
    private const MAX_SCHEMA_ENTRIES = 64;
    private const MAX_DEPTH = 3;
    private const MAX_KEY_BYTES = 64;
    private const IDENTIFIER_PATHS = [
        'delivery_id', 'renewal.id', 'renewal_id', 'event_id', 'transaction_id',
        'customer.id', 'data.customer.id', 'customer_id', 'product.id', 'access.id',
    ];
    private const CORRELATION_PATHS = [
        'delivery_id', 'renewal.id', 'renewal_id', 'event_id', 'transaction_id',
    ];
    private const TEMPORAL_PATHS = [
        'timestamp', 'created_at', 'occurred_at', 'sent_at', 'updated_at',
        'renewal.created_at', 'renewal.updated_at', 'renewal.new_expires_at',
    ];

    public function __construct(private readonly string $correlationKey)
    {
        if (strlen($this->correlationKey) < 32) throw new InvalidArgumentException('CAPTURE_CORRELATION_KEY_INVALID');
    }

    public static function isEnabled(mixed $value): bool
    {
        return $value === '1';
    }

    public function usesKey(string $candidate): bool
    {
        return hash_equals($this->correlationKey, $candidate);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function inspect(array $payload): array
    {
        $schema = [];
        $this->collectSchema($payload, '', 0, $schema);
        $identifiers = [];
        foreach (self::IDENTIFIER_PATHS as $path) {
            [$present, $value] = $this->valueAtPath($payload, $path);
            $descriptor = ['present'=>$present, 'type'=>$present ? $this->valueType($value) : 'absent'];
            if ($present) $descriptor['format'] = $this->identifierFormat($value);
            if ($present && in_array($path, self::CORRELATION_PATHS, true) && $this->scalarText($value) !== null) {
                $descriptor['correlation_hmac'] = hash_hmac(
                    'sha256',
                    "topgestor-capture:{$path}\0" . $this->scalarText($value),
                    $this->correlationKey
                );
            }
            $identifiers[$path] = $descriptor;
        }
        $temporal = [];
        foreach (self::TEMPORAL_PATHS as $path) {
            [$present, $value] = $this->valueAtPath($payload, $path);
            $temporal[$path] = $this->temporalDescriptor($present, $value);
        }
        return [
            'schema'=>$schema,
            'schema_entry_count'=>count($schema),
            'schema_truncated'=>count($schema) >= self::MAX_SCHEMA_ENTRIES,
            'identifiers'=>$identifiers,
            'temporal_fields'=>$temporal,
        ];
    }

    /** @return array{present:bool,type:string,format?:string} */
    public function describeTemporalValue(mixed $value): array
    {
        return $this->temporalDescriptor($value !== null, $value);
    }

    /** @param array<string, mixed> $values @param list<array{path:string,type:string}> $schema */
    private function collectSchema(array $values, string $prefix, int $depth, array &$schema): void
    {
        if ($depth > self::MAX_DEPTH || count($schema) >= self::MAX_SCHEMA_ENTRIES) return;
        foreach ($values as $key=>$value) {
            if (count($schema) >= self::MAX_SCHEMA_ENTRIES) return;
            $safeKey = $this->sanitizeKey((string)$key);
            $path = $prefix === '' ? $safeKey : $prefix . '.' . $safeKey;
            $schema[] = ['path'=>$path, 'type'=>$this->valueType($value)];
            if ($depth < self::MAX_DEPTH && is_array($value) && !array_is_list($value)) {
                $this->collectSchema($value, $path, $depth + 1, $schema);
            }
        }
    }

    private function sanitizeKey(string $key): string
    {
        if ($key === '' || strlen($key) > self::MAX_KEY_BYTES || !preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/', $key)) return 'invalid_key';
        if (preg_match('/(?:pass|password|senha|token|secret|authorization|username|usuario|email|phone|telefone|whatsapp|nome|name|url)/i', $key)) {
            return 'redacted_key';
        }
        return $key;
    }

    /** @param array<string, mixed> $payload @return array{bool,mixed} */
    private function valueAtPath(array $payload, string $path): array
    {
        $cursor = $payload;
        $parts = explode('.', $path);
        foreach ($parts as $index=>$part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) return [false, null];
            $cursor = $cursor[$part];
            if ($index < count($parts) - 1 && !is_array($cursor)) return [false, null];
        }
        return [true, $cursor];
    }

    /** @return array{present:bool,type:string,format?:string} */
    private function temporalDescriptor(bool $present, mixed $value): array
    {
        if (!$present) return ['present'=>false, 'type'=>'absent'];
        return ['present'=>true, 'type'=>$this->valueType($value), 'format'=>$this->temporalFormat($value)];
    }

    private function temporalFormat(mixed $value): string
    {
        if (is_int($value) && $value > 0) return $value >= 100000000000 ? 'unix_milliseconds' : 'unix_seconds';
        if (!is_string($value)) return 'unexpected';
        $text = trim($value);
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $text)) return 'date_yyyy_mm_dd';
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/', $text)) return 'iso8601';
        if (preg_match('/\A[1-9]\d{9}\z/', $text)) return 'unix_seconds';
        if (preg_match('/\A[1-9]\d{12}\z/', $text)) return 'unix_milliseconds';
        return 'unexpected';
    }

    private function identifierFormat(mixed $value): string
    {
        if (is_int($value) && $value > 0) return 'positive_integer';
        $text = $this->scalarText($value);
        if ($text === null) return 'unexpected';
        if (preg_match('/\A[1-9]\d*\z/', $text)) return 'positive_integer';
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $text)) return 'uuid';
        if (preg_match('/\A[A-Za-z][A-Za-z0-9_-]{7,127}\z/', $text)) return str_contains($text, '_') || str_contains($text, '-') ? 'prefixed_opaque' : 'opaque';
        return 'unexpected';
    }

    private function valueType(mixed $value): string
    {
        return match (true) {
            $value === null => 'null', is_array($value) && array_is_list($value) => 'array', is_array($value) => 'object',
            is_string($value) => 'string', is_int($value) => 'integer', is_float($value) => 'number',
            is_bool($value) => 'boolean', default => 'other',
        };
    }

    private function scalarText(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) return null;
        $text = trim((string)$value);
        return $text !== '' ? $text : null;
    }
}

final class TopGestorWebhookDiagnostic
{
    private const MAX_BODY_BYTES = 65536;
    /** @var Closure(array<string, mixed>):void */
    private readonly Closure $logger;

    public function __construct(?Closure $logger = null)
    {
        $this->logger = $logger ?? static fn (array $metadata) => error_log((string) json_encode($metadata, JSON_UNESCAPED_SLASHES));
    }

    public static function isEnabled(mixed $value): bool
    {
        return $value === '1';
    }

    /** @param array<string, string> $headers @return array{status:int, body:array<string, mixed>} */
    public function observe(string $method, string $path, array $headers, string $rawBody): array
    {
        $requestId = bin2hex(random_bytes(12));
        $normalized = [];
        foreach ($headers as $name => $value) $normalized[strtolower(trim($name))] = trim($value);
        $signature = $normalized['x-hub-signature-256'] ?? '';
        $webhookSecretPresent = ($normalized['x-webhook-secret'] ?? '') !== '';
        $contentTypeValue = strtolower(trim(explode(';', $normalized['content-type'] ?? '', 2)[0]));
        $contentType = match ($contentTypeValue) {
            '' => 'absent',
            'application/json' => 'application_json',
            default => 'other',
        };
        $bodyBytes = strlen($rawBody);
        $resultCode = 'DIAGNOSTIC_OBSERVED_NOT_AUTHENTICATED';
        $status = 200;

        if (strtoupper($method) !== 'POST' || $path !== '/webhooks/top-gestor') {
            $resultCode = 'DIAGNOSTIC_ROUTE_REJECTED';
            $status = 404;
        } elseif ($bodyBytes === 0 || $bodyBytes > self::MAX_BODY_BYTES) {
            $resultCode = 'DIAGNOSTIC_BODY_REJECTED';
            $status = 413;
        }

        ($this->logger)([
            'component' => 'top_gestor_webhook_probe',
            'mode' => 'diagnostic',
            'request_id' => $requestId,
            'hmac_header_present' => $signature !== '',
            'hmac_header_format' => $this->signatureFormat($signature),
            'webhook_secret_header_present' => $webhookSecretPresent,
            'content_type' => $contentType,
            'body_bytes' => $bodyBytes,
            'validation_result' => $resultCode,
            'authenticated' => false,
            'processed' => false,
            'database_accessed' => false,
        ]);

        return ['status' => $status, 'body' => [
            'accepted' => false,
            'authenticated' => false,
            'processed' => false,
            'code' => $resultCode,
            'request_id' => $requestId,
            'database_accessed' => false,
        ]];
    }

    private function signatureFormat(string $signature): string
    {
        if ($signature === '') return 'absent';
        if (preg_match('/\Asha256=[a-f0-9]{64}\z/', $signature)) return 'sha256_equals_hex_64_lowercase';
        if (preg_match('/\Asha256=[A-Fa-f0-9]{64}\z/', $signature)) return 'sha256_equals_hex_64_mixed_case';
        if (preg_match('/\A[A-Fa-f0-9]{64}\z/', $signature)) return 'hex_64_without_algorithm';
        return 'unexpected';
    }
}

final class TopGestorProbeCurlApiClient implements TopGestorProbeApiClient
{
    private const API_BASE = 'https://topgestor.me/api/v1';

    public function __construct(private readonly string $token, private readonly int $timeoutSeconds = 10)
    {
        if (trim($this->token) === '') throw new InvalidArgumentException('TOPGESTOR_API_TOKEN_INVALID');
    }

    public function fetchCustomer(string $customerId): array
    {
        if (!preg_match('/\A[1-9][0-9]*\z/', $customerId)) throw new InvalidArgumentException('CUSTOMER_ID_INVALID');
        $handle = curl_init(self::API_BASE . '/customers/' . rawurlencode($customerId));
        if ($handle === false) throw new RuntimeException('TOPGESTOR_API_INIT_FAILED');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds, CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPGET => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token, 'Accept: application/json'],
        ]);
        $body = curl_exec($handle);
        $curlError = curl_errno($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($curlError !== 0 || !is_string($body)) throw new RuntimeException('TOPGESTOR_API_TRANSPORT_FAILED');
        if ($httpStatus < 200 || $httpStatus >= 300) throw new RuntimeException('TOPGESTOR_API_HTTP_' . $httpStatus);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) throw new RuntimeException('TOPGESTOR_API_JSON_INVALID');
        return $decoded;
    }
}

final class TopGestorProbeDeliveryGuard
{
    public function __construct(private readonly string $directory, private readonly string $hashKey)
    {
        if (strlen($this->hashKey) < 32) throw new InvalidArgumentException('DELIVERY_HASH_KEY_INVALID');
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('DELIVERY_DIRECTORY_UNAVAILABLE');
        }
    }

    public function claim(string $deliveryId): bool
    {
        $path = $this->path($deliveryId);
        $handle = @fopen($path, 'x');
        if ($handle === false) return false;
        fwrite($handle, gmdate(DATE_ATOM));
        fclose($handle);
        @chmod($path, 0600);
        return true;
    }

    public function release(string $deliveryId): void
    {
        $path = $this->path($deliveryId);
        if (is_file($path)) @unlink($path);
    }

    private function path(string $deliveryId): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . hash_hmac('sha256', $deliveryId, $this->hashKey) . '.claim';
    }
}

final class TopGestorWebhookProbe
{
    public const EVENT = 'customer.renewed';
    public const ALLOWED_CUSTOMER_ID = '143528';
    private const MAX_BODY_BYTES = 65536;
    /** @var Closure(array<string, mixed>):void */
    private readonly Closure $logger;

    public function __construct(
        private readonly TopGestorProbeApiClient $apiClient,
        private readonly TopGestorProbeDeliveryGuard $deliveryGuard,
        private readonly string $webhookSecret,
        private readonly string $expectedProductId,
        private readonly string $expectedProductName = 'Sistema GP',
        private readonly ?string $baselineExpiration = null,
        ?Closure $logger = null,
        private readonly ?TopGestorRenewalCapture $renewalCapture = null,
    ) {
        if (strlen($this->webhookSecret) < 32) throw new InvalidArgumentException('WEBHOOK_SECRET_INVALID');
        if ($this->renewalCapture !== null && $this->renewalCapture->usesKey($this->webhookSecret)) {
            throw new InvalidArgumentException('CAPTURE_CORRELATION_KEY_MUST_BE_DISTINCT');
        }
        if ($this->expectedProductId === '') throw new InvalidArgumentException('EXPECTED_PRODUCT_ID_INVALID');
        $this->logger = $logger ?? static fn (array $metadata) => error_log((string) json_encode($metadata, JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, string> $headers @return array{status:int, body:array<string, mixed>} */
    public function handle(string $method, string $path, array $headers, string $rawBody): array
    {
        $requestId = bin2hex(random_bytes(12));
        $baseLog = ['component'=>'top_gestor_webhook_probe','request_id'=>$requestId,'method'=>strtoupper($method),
            'path_valid'=>$path === '/webhooks/top-gestor','body_bytes'=>strlen($rawBody)];
        if (strtoupper($method) !== 'POST' || $path !== '/webhooks/top-gestor') return $this->respond(404, 'NOT_FOUND', $requestId);
        if (strlen($rawBody) === 0 || strlen($rawBody) > self::MAX_BODY_BYTES) {
            ($this->logger)($baseLog + ['result'=>'body_rejected']); return $this->respond(413, 'BODY_REJECTED', $requestId);
        }
        $normalized = [];
        foreach ($headers as $name=>$value) $normalized[strtolower(trim($name))] = trim($value);
        if (!str_starts_with(strtolower($normalized['content-type'] ?? ''), 'application/json')) {
            ($this->logger)($baseLog + ['result'=>'content_type_rejected']); return $this->respond(415, 'CONTENT_TYPE_REJECTED', $requestId);
        }
        $receivedSignature = $normalized['x-hub-signature-256'] ?? '';
        if (!$this->signatureIsValid($rawBody, $receivedSignature)) {
            ($this->logger)($baseLog + ['signature_present'=>$receivedSignature !== '',
                'signature_prefix_valid'=>str_starts_with($receivedSignature, 'sha256='),'signature_valid'=>false,'result'=>'signature_rejected']);
            return $this->respond(401, 'SIGNATURE_REJECTED', $requestId);
        }
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            ($this->logger)($baseLog + ['signature_valid'=>true,'result'=>'json_rejected']); return $this->respond(400, 'JSON_REJECTED', $requestId);
        }
        $event = $this->scalarText($payload['event'] ?? $payload['type'] ?? $payload['event_type'] ?? null);
        $deliveryId = $this->scalarText($payload['delivery_id'] ?? null);
        $customerId = $this->extractCustomerId($payload);
        $safeLog = $baseLog + ['signature_valid'=>true,'event'=>$event,'delivery_id_present'=>$deliveryId !== null,
            'customer_id_present'=>$customerId !== null];
        if ($this->renewalCapture === null) {
            $safeLog += ['delivery_id_hash'=>$deliveryId !== null ? $this->safeHash($deliveryId) : null,
                'customer_id_hash'=>$customerId !== null ? $this->safeHash($customerId) : null,
                'payload_keys'=>array_values(array_map('strval', array_keys($payload)))];
        }
        if ($event !== self::EVENT) { ($this->logger)($safeLog + ['result'=>'event_ignored']); return $this->respond(202, 'EVENT_IGNORED', $requestId); }
        if ($deliveryId === null) { ($this->logger)($safeLog + ['result'=>'delivery_id_missing']); return $this->respond(400, 'DELIVERY_ID_MISSING', $requestId); }
        if ($customerId !== self::ALLOWED_CUSTOMER_ID) { ($this->logger)($safeLog + ['result'=>'customer_rejected']); return $this->respond(403, 'CUSTOMER_REJECTED', $requestId); }
        $captureMetadata = $this->renewalCapture?->inspect($payload);
        if (!$this->deliveryGuard->claim($deliveryId)) { ($this->logger)($safeLog + ['result'=>'duplicate_ignored']); return $this->respond(200, 'DUPLICATE_IGNORED', $requestId); }

        try {
            $response = $this->apiClient->fetchCustomer(self::ALLOWED_CUSTOMER_ID);
            $customer = $response['data'] ?? $response;
            if (!is_array($customer)) throw new RuntimeException('CUSTOMER_RESPONSE_INVALID');
            $apiCustomerId = $this->scalarText($customer['id'] ?? null);
            $product = $customer['product'] ?? null;
            $productId = is_array($product) ? $this->scalarText($product['id'] ?? null) : null;
            $productName = is_array($product) ? $this->scalarText($product['name'] ?? null) : null;
            $expiration = $this->validDate($customer['data_de_vencimento'] ?? null);
            $updatedAt = $this->scalarText($customer['updated_at'] ?? null);
            if ($apiCustomerId !== self::ALLOWED_CUSTOMER_ID) throw new RuntimeException('API_CUSTOMER_MISMATCH');
            if ($productId !== $this->expectedProductId || $productName !== $this->expectedProductName) throw new RuntimeException('MAIN_PRODUCT_NOT_GP');
            if ($expiration === null) throw new RuntimeException('MAIN_EXPIRATION_INVALID');
            $changed = $this->baselineExpiration !== null ? $expiration !== $this->baselineExpiration : null;
            if ($this->renewalCapture !== null) {
                $comparison = $this->baselineExpiration === null ? null : ($expiration <=> $this->baselineExpiration);
                ($this->logger)($safeLog + ['result'=>'renewal_capture_confirmed','capture'=>$captureMetadata,
                    'rest_updated_at'=>$this->renewalCapture->describeTemporalValue($updatedAt),
                    'expiration_vs_baseline'=>$comparison === null ? null : ($comparison === 0 ? 'equal' : ($comparison < 0 ? 'before' : 'after')),
                    'database_accessed'=>false]);
                return ['status'=>200,'body'=>['accepted'=>false,'authenticated'=>true,'processed'=>false,
                    'code'=>'RENEWAL_CAPTURE_CONFIRMED','request_id'=>$requestId,'database_accessed'=>false]];
            }
            ($this->logger)($safeLog + ['result'=>'probe_confirmed','main_product_id_hash'=>$this->safeHash($productId),
                'main_product_name'=>$productName,'main_expiration'=>$expiration,'source_updated_at_present'=>$updatedAt !== null,
                'baseline_present'=>$this->baselineExpiration !== null,'expiration_changed_from_baseline'=>$changed,'database_accessed'=>false]);
            return ['status'=>200,'body'=>['accepted'=>true,'code'=>'PROBE_CONFIRMED','request_id'=>$requestId,
                'main_product'=>$productName,'main_expiration'=>$expiration,'expiration_changed_from_baseline'=>$changed,'database_accessed'=>false]];
        } catch (Throwable $exception) {
            $this->deliveryGuard->release($deliveryId);
            ($this->logger)($safeLog + ['result'=>$this->renewalCapture !== null ? 'renewal_capture_rest_failed' : 'probe_failed',
                'capture'=>$captureMetadata,'failure_code'=>$this->safeFailureCode($exception),'database_accessed'=>false]);
            return $this->respond(502, $this->renewalCapture !== null ? 'RENEWAL_CAPTURE_REST_FAILED' : 'PROBE_FAILED', $requestId);
        }
    }

    private function signatureIsValid(string $body, string $received): bool
    {
        if (!preg_match('/\Asha256=[a-f0-9]{64}\z/', $received)) return false;
        return hash_equals('sha256=' . hash_hmac('sha256', $body, $this->webhookSecret), $received);
    }
    /** @param array<string, mixed> $payload */
    private function extractCustomerId(array $payload): ?string
    {
        if (isset($payload['customer']) && is_array($payload['customer'])) return $this->scalarText($payload['customer']['id'] ?? null);
        if (isset($payload['data']['customer']) && is_array($payload['data']['customer'])) return $this->scalarText($payload['data']['customer']['id'] ?? null);
        return $this->scalarText($payload['customer_id'] ?? null);
    }
    private function scalarText(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) return null;
        $text = trim((string) $value); return $text !== '' ? $text : null;
    }
    private function validDate(mixed $value): ?string
    {
        $text = $this->scalarText($value); if ($text === null) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text, new DateTimeZone('America/Sao_Paulo'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) return null;
        return $date->format('Y-m-d') === $text ? $text : null;
    }
    private function safeHash(string $value): string { return hash_hmac('sha256', $value, $this->webhookSecret); }
    private function safeFailureCode(Throwable $exception): string
    {
        $code = strtoupper($exception->getMessage()); return preg_match('/\A[A-Z0-9_]{3,80}\z/', $code) ? $code : 'INTERNAL_PROBE_FAILURE';
    }
    /** @return array{status:int, body:array<string, mixed>} */
    private function respond(int $status, string $code, string $requestId): array
    {
        return ['status'=>$status,'body'=>['accepted'=>false,'code'=>$code,'request_id'=>$requestId,'database_accessed'=>false]];
    }
}
