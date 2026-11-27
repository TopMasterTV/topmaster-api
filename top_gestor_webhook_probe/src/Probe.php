<?php

declare(strict_types=1);

interface TopGestorProbeApiClient
{
    /** @return array<string, mixed> */
    public function fetchCustomer(string $customerId): array;
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
    ) {
        if (strlen($this->webhookSecret) < 32) throw new InvalidArgumentException('WEBHOOK_SECRET_INVALID');
        if ($this->expectedProductId === '') throw new InvalidArgumentException('EXPECTED_PRODUCT_ID_INVALID');
        $this->logger = $logger ?? static fn (array $metadata) => error_log((string) json_encode($metadata, JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, string> $headers @return array{status:int, body:array<string, mixed>} */
    public function handle(string $method, string $path, array $headers, string $rawBody): array
    {
        $requestId = bin2hex(random_bytes(12));
        $baseLog = ['component'=>'top_gestor_webhook_probe','request_id'=>$requestId,'method'=>strtoupper($method),
            'path_valid'=>$path === '/webhooks/top-gestor','body_bytes'=>strlen($rawBody),'body_sha256'=>hash('sha256', $rawBody)];
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
            'delivery_id_hash'=>$deliveryId !== null ? $this->safeHash($deliveryId) : null,
            'customer_id_present'=>$customerId !== null,'customer_id_hash'=>$customerId !== null ? $this->safeHash($customerId) : null,
            'payload_keys'=>array_values(array_map('strval', array_keys($payload)))];
        if ($event !== self::EVENT) { ($this->logger)($safeLog + ['result'=>'event_ignored']); return $this->respond(202, 'EVENT_IGNORED', $requestId); }
        if ($deliveryId === null) { ($this->logger)($safeLog + ['result'=>'delivery_id_missing']); return $this->respond(400, 'DELIVERY_ID_MISSING', $requestId); }
        if ($customerId !== self::ALLOWED_CUSTOMER_ID) { ($this->logger)($safeLog + ['result'=>'customer_rejected']); return $this->respond(403, 'CUSTOMER_REJECTED', $requestId); }
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
            ($this->logger)($safeLog + ['result'=>'probe_confirmed','main_product_id_hash'=>$this->safeHash($productId),
                'main_product_name'=>$productName,'main_expiration'=>$expiration,'source_updated_at_present'=>$updatedAt !== null,
                'baseline_present'=>$this->baselineExpiration !== null,'expiration_changed_from_baseline'=>$changed,'database_accessed'=>false]);
            return ['status'=>200,'body'=>['accepted'=>true,'code'=>'PROBE_CONFIRMED','request_id'=>$requestId,
                'main_product'=>$productName,'main_expiration'=>$expiration,'expiration_changed_from_baseline'=>$changed,'database_accessed'=>false]];
        } catch (Throwable $exception) {
            $this->deliveryGuard->release($deliveryId);
            ($this->logger)($safeLog + ['result'=>'probe_failed','failure_code'=>$this->safeFailureCode($exception),'database_accessed'=>false]);
            return $this->respond(502, 'PROBE_FAILED', $requestId);
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
