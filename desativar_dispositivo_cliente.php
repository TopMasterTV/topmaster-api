<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/administrative_token_auth.php';

function responderDesativacao(int $statusHttp, array $resposta): never
{
    http_response_code($statusHttp);
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function erroDesativacao(int $statusHttp, string $codigo): never
{
    responderDesativacao($statusHttp, ['success' => false, 'error' => $codigo]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    erroDesativacao(405, 'METHOD_NOT_ALLOWED');
}

$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($contentType !== 'application/json') {
    erroDesativacao(400, 'INVALID_REQUEST');
}

$dados = json_decode((string) file_get_contents('php://input'), true);
$deviceCode = is_array($dados) ? ($dados['device_code'] ?? null) : null;
$clienteId = is_array($dados) ? ($dados['cliente_id'] ?? null) : null;
if (!is_string($deviceCode) || preg_match('/^[A-Z]{3}-[0-9]{3}-[A-Z]{3}$/D', $deviceCode) !== 1) {
    erroDesativacao(400, 'INVALID_DEVICE_CODE');
}
$clienteId = filter_var($clienteId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($clienteId === false) {
    erroDesativacao(400, 'INVALID_CLIENTE_ID');
}

try {
    $databaseUrl = getenv('DATABASE_URL');
    if (!is_string($databaseUrl) || $databaseUrl === '') {
        throw new RuntimeException('Configuracao do banco ausente');
    }

    $db = parse_url($databaseUrl);
    if (!is_array($db) || empty($db['host']) || empty($db['path']) || !isset($db['user'], $db['pass'])) {
        throw new RuntimeException('Configuracao do banco invalida');
    }

    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require', $db['host'], $db['port'] ?? 5432, ltrim($db['path'], '/')),
        rawurldecode($db['user']),
        rawurldecode($db['pass']),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $sessao = autenticarTokenAdministrativo($pdo);
    if ($sessao['actor_type'] !== 'master') {
        erroDesativacao(403, 'ACCESS_DENIED');
    }

    $desativacao = $pdo->prepare(<<<'SQL'
        UPDATE client_devices
        SET status = 'disabled', disabled_at = clock_timestamp()
        WHERE device_code = :device_code
          AND cliente_id = :cliente_id
        RETURNING device_code
        SQL);
    $desativacao->execute([':device_code' => $deviceCode, ':cliente_id' => $clienteId]);
    if ($desativacao->fetch() === false) {
        erroDesativacao(404, 'DEVICE_NOT_FOUND_FOR_CLIENT');
    }

    responderDesativacao(200, ['success' => true]);
} catch (AdministrativeAuthException $e) {
    erroDesativacao($e->getStatusHttp(), $e->getCodigoPublico());
} catch (Throwable $e) {
    erroDesativacao(500, 'INTERNAL_ERROR');
}
