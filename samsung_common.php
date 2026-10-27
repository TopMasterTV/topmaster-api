<?php

declare(strict_types=1);

const SAMSUNG_APP_TYPE = 'samsung';
const SAMSUNG_TOKEN_TTL_SECONDS = 2592000;

function samsungApplyCors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    $allowedOrigin = getenv('SAMSUNG_ALLOWED_ORIGIN');

    if (is_string($origin) && $origin !== '') {
        if (!is_string($allowedOrigin) || $allowedOrigin === '' || !hash_equals($allowedOrigin, $origin)) {
            samsungJsonError(403, 'ORIGIN_NOT_ALLOWED', 'Origem não permitida');
        }

        header('Access-Control-Allow-Origin: ' . $allowedOrigin);
        header('Vary: Origin');
    }

    $policy = samsungCorsPolicy();
    if ($policy['methods'] !== []) {
        header('Access-Control-Allow-Methods: ' . implode(', ', array_merge($policy['methods'], ['OPTIONS'])));
    }
    if ($policy['headers'] !== []) {
        header('Access-Control-Allow-Headers: ' . implode(', ', $policy['headers']));
    }
    header('Access-Control-Max-Age: 600');
}

function samsungCorsPolicy(): array
{
    return match (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
        'samsung_login.php' => ['methods' => ['POST'], 'headers' => ['Content-Type']],
        'samsung_logout.php' => ['methods' => ['POST'], 'headers' => ['Authorization']],
        'samsung_session.php', 'samsung_listar_sistemas.php',
        'samsung_live_categories.php', 'samsung_live_streams.php' => [
            'methods' => ['GET'],
            'headers' => ['Authorization'],
        ],
        default => ['methods' => [], 'headers' => []],
    };
}

function samsungHandlePreflight(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'OPTIONS') {
        return;
    }

    $requestedMethod = strtoupper(trim((string) ($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? '')));
    $policy = samsungCorsPolicy();

    if (!in_array($requestedMethod, $policy['methods'], true)) {
        header('Allow: ' . implode(', ', array_merge($policy['methods'], ['OPTIONS'])));
        samsungJsonError(405, 'METHOD_NOT_ALLOWED', 'Método não permitido');
    }

    http_response_code(204);
    exit;
}

function samsungRequireHttps(): void
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https === 'on' || $https === '1') {
        return;
    }

    /*
     * Pressupoe o servico Web do Render como ponto de entrada publica, onde
     * o TLS e encerrado. Acesso direto pela rede privada nao e validado aqui.
     */
    if (getenv('RENDER') === 'true') {
        return;
    }

    /*
     * SAMSUNG_TRUSTED_PROXY_IPS deve conter somente os IPs exatos dos proxies
     * controlados pela producao, separados por virgula. Esses proxies tambem
     * devem remover o X-Forwarded-Proto recebido do cliente e grava-lo com o
     * protocolo real. Sem essa configuracao, cabecalhos de proxy sao ignorados.
     */
    if (samsungRequestCameFromTrustedProxy()) {
        $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        if ($forwardedProto === 'https') {
            return;
        }
    }

    samsungJsonError(400, 'HTTPS_REQUIRED', 'HTTPS obrigatório');
}

function samsungRequestCameFromTrustedProxy(): bool
{
    $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if (filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    $configured = getenv('SAMSUNG_TRUSTED_PROXY_IPS');
    if (!is_string($configured) || trim($configured) === '') {
        return false;
    }

    foreach (explode(',', $configured) as $proxyAddress) {
        $proxyAddress = trim($proxyAddress);
        if (filter_var($proxyAddress, FILTER_VALIDATE_IP) !== false
            && hash_equals($proxyAddress, $remoteAddress)) {
            return true;
        }
    }

    return false;
}

function samsungRequireMethod(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: ' . $method . ', OPTIONS');
        samsungJsonError(405, 'METHOD_NOT_ALLOWED', 'Método não permitido');
    }
}

function samsungJson(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function samsungJsonError(int $status, string $code, string $message): never
{
    samsungJson($status, [
        'success' => false,
        'error' => ['code' => $code, 'message' => $message],
    ]);
}

function samsungReadJsonBody(int $limit = 16384): array
{
    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));

    if ($contentType !== 'application/json') {
        samsungJsonError(400, 'INVALID_REQUEST', 'Requisição inválida');
    }

    $contentLength = $_SERVER['HTTP_CONTENT_LENGTH'] ?? $_SERVER['CONTENT_LENGTH'] ?? null;
    if ($contentLength !== null && is_numeric($contentLength) && (float) $contentLength > $limit) {
        samsungJsonError(413, 'PAYLOAD_TOO_LARGE', 'Requisição muito grande');
    }

    $stream = @fopen('php://input', 'rb');
    $raw = $stream === false ? false : @stream_get_contents($stream, $limit + 1);
    if (is_resource($stream)) {
        fclose($stream);
    }

    if (!is_string($raw) || strlen($raw) > $limit) {
        samsungJsonError(400, 'INVALID_REQUEST', 'Requisição inválida');
    }

    try {
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        samsungJsonError(400, 'INVALID_JSON', 'JSON inválido');
    }

    if (!is_array($decoded) || array_is_list($decoded)) {
        samsungJsonError(400, 'INVALID_REQUEST', 'Requisição inválida');
    }

    return $decoded;
}

function samsungDatabase(): PDO
{
    $databaseUrl = getenv('DATABASE_URL');
    if (!is_string($databaseUrl) || $databaseUrl === '') {
        throw new RuntimeException('Configuração do banco ausente');
    }

    $parts = parse_url($databaseUrl);
    if (!is_array($parts) || empty($parts['host']) || empty($parts['path']) || !isset($parts['user'], $parts['pass'])) {
        throw new RuntimeException('Configuração do banco inválida');
    }

    $host = (string) $parts['host'];
    $port = (int) ($parts['port'] ?? 5432);
    $database = ltrim((string) $parts['path'], '/');

    return new PDO(
        "pgsql:host={$host};port={$port};dbname={$database};sslmode=require",
        rawurldecode((string) $parts['user']),
        rawurldecode((string) $parts['pass']),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function samsungPgBool(mixed $value): bool
{
    if ($value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true') {
        return true;
    }
    if ($value === false || $value === 0 || $value === '0' || $value === 'f' || $value === 'false' || $value === null) {
        return false;
    }
    throw new UnexpectedValueException('Booleano PostgreSQL inválido');
}

/**
 * Confirma o estado de uso de um sistema já restrito ao cliente autenticado.
 * Datas ausentes preservam o status existente; datas presentes são validadas
 * e nunca podem reautorizar um status diferente de Active.
 */
function samsungSystemIsAuthorized(array $context): bool
{
    if (strcasecmp(trim((string) ($context['status'] ?? '')), 'Active') !== 0) {
        return false;
    }

    $expirationTimestamp = $context['exp_date'] ?? null;
    if ($expirationTimestamp !== null) {
        $expirationTimestamp = trim((string) $expirationTimestamp);
        if ($expirationTimestamp === '' || !ctype_digit($expirationTimestamp)
            || (int) $expirationTimestamp <= time()) {
            return false;
        }
    }

    $expirationDate = $context['vencimento'] ?? null;
    if ($expirationDate !== null) {
        $expirationDate = trim((string) $expirationDate);
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $expirationDate);
        $parseErrors = DateTimeImmutable::getLastErrors();
        if ($parsedDate === false
            || ($parseErrors !== false && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0))
            || $parsedDate < new DateTimeImmutable('today')) {
            return false;
        }
    }

    return true;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
samsungApplyCors();
samsungHandlePreflight();
samsungRequireHttps();
