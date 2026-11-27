<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Probe.php';
header('Content-Type: application/json; charset=utf-8');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

if ($method === 'GET' && $path === '/health') {
    http_response_code(200);
    echo json_encode(['ok'=>true,'service'=>'top-gestor-webhook-probe','database_accessed'=>false], JSON_UNESCAPED_SLASHES);
    exit;
}
if ($method !== 'POST' || $path !== '/webhooks/top-gestor') {
    http_response_code(404); echo json_encode(['accepted'=>false,'code'=>'NOT_FOUND']); exit;
}

function requiredProbeEnvironment(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') throw new RuntimeException('PROBE_CONFIGURATION_INVALID');
    return trim($value);
}

try {
    $allowedCustomer = requiredProbeEnvironment('TOPGESTOR_ALLOWED_CUSTOMER_ID');
    if (!hash_equals(TopGestorWebhookProbe::ALLOWED_CUSTOMER_ID, $allowedCustomer)) throw new RuntimeException('PROBE_CONFIGURATION_INVALID');
    $secret = requiredProbeEnvironment('TOPGESTOR_WEBHOOK_SECRET');
    $apiClient = new TopGestorProbeCurlApiClient(requiredProbeEnvironment('TOPGESTOR_API_TOKEN'));
    $guard = new TopGestorProbeDeliveryGuard(
        getenv('TOPGESTOR_DELIVERY_DIR') ?: sys_get_temp_dir() . '/topgestor-webhook-probe-deliveries', $secret
    );
    $baseline = getenv('TOPGESTOR_BASELINE_EXPIRATION');
    $probe = new TopGestorWebhookProbe(
        $apiClient, $guard, $secret, requiredProbeEnvironment('TOPGESTOR_EXPECTED_GP_PRODUCT_ID'),
        getenv('TOPGESTOR_EXPECTED_GP_PRODUCT_NAME') ?: 'Sistema GP',
        is_string($baseline) && trim($baseline) !== '' ? trim($baseline) : null,
    );
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if (!is_array($headers)) {
        $headers = [];
    }
    foreach ($_SERVER as $name => $value) {
        if (str_starts_with($name, 'HTTP_') && is_string($value)) {
            $headerName = str_replace('_', '-', substr($name, 5));
            $headers[$headerName] = $value;
        }
    }
    $safeHeaders = [];
    foreach ($headers as $name=>$value) if (is_string($name) && is_string($value)) $safeHeaders[$name] = $value;
    $rawBody = file_get_contents('php://input');
    if (!is_string($rawBody)) throw new RuntimeException('REQUEST_BODY_UNAVAILABLE');
    $result = $probe->handle($method, $path, $safeHeaders, $rawBody);
    http_response_code($result['status']);
    echo json_encode($result['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable) {
    error_log(json_encode(['component'=>'top_gestor_webhook_probe','result'=>'safe_bootstrap_failure','database_accessed'=>false]));
    http_response_code(503);
    echo json_encode(['accepted'=>false,'code'=>'PROBE_UNAVAILABLE','database_accessed'=>false]);
}
