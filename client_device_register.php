<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function responderJson(int $statusHttp, array $resposta): void
{
    http_response_code($statusHttp);
    echo json_encode($resposta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function responderErro(int $statusHttp, string $codigo): void
{
    responderJson($statusHttp, [
        'success' => false,
        'error' => $codigo,
    ]);
}

function uuidCanonicoValido(string $uuid): bool
{
    return preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
        $uuid
    ) === 1;
}

function gerarDeviceCode(): string
{
    $letras = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $numeros = '0123456789';
    $codigo = '';

    for ($i = 0; $i < 3; $i++) {
        $codigo .= $letras[random_int(0, 25)];
    }

    $codigo .= '-';

    for ($i = 0; $i < 3; $i++) {
        $codigo .= $numeros[random_int(0, 9)];
    }

    $codigo .= '-';

    for ($i = 0; $i < 3; $i++) {
        $codigo .= $letras[random_int(0, 25)];
    }

    return $codigo;
}

function buscarDispositivo(PDO $pdo, string $deviceUuid): ?array
{
    $consulta = $pdo->prepare(<<<'SQL'
        SELECT device_code, device_secret_hash
        FROM client_devices
        WHERE device_uuid = :device_uuid
        LIMIT 1
        SQL);
    $consulta->execute([':device_uuid' => $deviceUuid]);
    $dispositivo = $consulta->fetch();

    return is_array($dispositivo) ? $dispositivo : null;
}

function responderDispositivoExistente(array $dispositivo, string $secretHash): void
{
    $hashArmazenado = $dispositivo['device_secret_hash'] ?? null;
    $deviceCode = $dispositivo['device_code'] ?? null;

    if (
        !is_string($hashArmazenado)
        || !is_string($deviceCode)
        || !hash_equals($hashArmazenado, $secretHash)
    ) {
        responderErro(403, 'DEVICE_REGISTRATION_DENIED');
    }

    responderJson(200, [
        'success' => true,
        'device_code' => $deviceCode,
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderErro(405, 'METHOD_NOT_ALLOWED');
}

$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));

if ($contentType !== 'application/json') {
    responderErro(400, 'INVALID_REQUEST');
}

$limiteCorpo = 4096;
$contentLength = $_SERVER['HTTP_CONTENT_LENGTH'] ?? $_SERVER['CONTENT_LENGTH'] ?? null;

if ($contentLength !== null && is_numeric($contentLength) && (float) $contentLength > $limiteCorpo) {
    responderErro(413, 'PAYLOAD_TOO_LARGE');
}

$entrada = @fopen('php://input', 'rb');

if ($entrada === false) {
    responderErro(400, 'INVALID_REQUEST');
}

$corpoBruto = @stream_get_contents($entrada, $limiteCorpo + 1);
fclose($entrada);

if ($corpoBruto === false) {
    responderErro(400, 'INVALID_REQUEST');
}

if (strlen($corpoBruto) > $limiteCorpo) {
    responderErro(413, 'PAYLOAD_TOO_LARGE');
}

$dados = json_decode($corpoBruto, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    responderErro(400, 'INVALID_JSON');
}

if (!is_array($dados) || array_key_exists('device_code', $dados)) {
    responderErro(400, 'INVALID_REQUEST');
}

$deviceUuid = $dados['device_uuid'] ?? null;
$deviceSecret = $dados['device_secret'] ?? null;

if (!is_string($deviceUuid) || !is_string($deviceSecret)) {
    responderErro(400, 'INVALID_REQUEST');
}

if (!uuidCanonicoValido($deviceUuid)) {
    responderErro(400, 'INVALID_DEVICE_UUID');
}

if (preg_match('/^[0-9a-f]{64}$/D', $deviceSecret) !== 1) {
    responderErro(400, 'INVALID_DEVICE_SECRET');
}

$deviceSecretHash = hash('sha256', $deviceSecret);
$pdo = null;

try {
    $databaseUrl = getenv('DATABASE_URL');

    if (!is_string($databaseUrl) || $databaseUrl === '') {
        throw new RuntimeException('Configuracao do banco ausente');
    }

    $db = parse_url($databaseUrl);

    if (
        !is_array($db)
        || empty($db['host'])
        || empty($db['path'])
        || !isset($db['user'], $db['pass'])
    ) {
        throw new RuntimeException('Configuracao do banco invalida');
    }

    $host = $db['host'];
    $port = $db['port'] ?? 5432;
    $dbname = ltrim($db['path'], '/');
    $dbUser = rawurldecode($db['user']);
    $dbPass = rawurldecode($db['pass']);

    $pdo = new PDO(
        "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $dispositivo = buscarDispositivo($pdo, $deviceUuid);

    if ($dispositivo !== null) {
        responderDispositivoExistente($dispositivo, $deviceSecretHash);
    }

    $maxTentativas = 5;
    $inserir = $pdo->prepare(<<<'SQL'
        INSERT INTO client_devices (
            device_uuid,
            device_code,
            device_secret_hash,
            cliente_id,
            owner_actor_type,
            owner_actor_id,
            status
        ) VALUES (
            :device_uuid,
            :device_code,
            :device_secret_hash,
            NULL,
            NULL,
            NULL,
            'registered'
        )
        ON CONFLICT (device_code) DO NOTHING
        RETURNING device_code
        SQL);

    for ($tentativa = 1; $tentativa <= $maxTentativas; $tentativa++) {
        $deviceCode = gerarDeviceCode();
        try {
            $inserir->execute([
                ':device_uuid' => $deviceUuid,
                ':device_code' => $deviceCode,
                ':device_secret_hash' => $deviceSecretHash,
            ]);
        } catch (PDOException $e) {
            $sqlState = $e->errorInfo[0] ?? $e->getCode();

            if ($sqlState !== '23505') {
                throw $e;
            }

            $dispositivo = buscarDispositivo($pdo, $deviceUuid);

            if ($dispositivo !== null) {
                responderDispositivoExistente($dispositivo, $deviceSecretHash);
            }

            responderErro(403, 'DEVICE_REGISTRATION_DENIED');
        }

        $inserido = $inserir->fetch();

        if (is_array($inserido) && is_string($inserido['device_code'] ?? null)) {
            responderJson(201, [
                'success' => true,
                'device_code' => $inserido['device_code'],
            ]);
        }

        $dispositivo = buscarDispositivo($pdo, $deviceUuid);

        if ($dispositivo !== null) {
            responderDispositivoExistente($dispositivo, $deviceSecretHash);
        }
    }

    responderErro(503, 'DEVICE_CODE_UNAVAILABLE');
} catch (Throwable $e) {
    responderErro(500, 'INTERNAL_ERROR');
}
