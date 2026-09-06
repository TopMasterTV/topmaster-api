<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function responderStatus(int $statusHttp, array $resposta): never
{
    http_response_code($statusHttp);
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function erroStatus(int $statusHttp, string $codigo): never
{
    responderStatus($statusHttp, ['success' => false, 'error' => $codigo]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    erroStatus(405, 'METHOD_NOT_ALLOWED');
}

$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($contentType !== 'application/json') {
    erroStatus(400, 'INVALID_REQUEST');
}

$dados = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($dados)) {
    erroStatus(400, 'INVALID_REQUEST');
}

$deviceUuid = $dados['device_uuid'] ?? null;
$deviceSecret = $dados['device_secret'] ?? null;

if (!is_string($deviceUuid) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $deviceUuid) !== 1) {
    erroStatus(400, 'INVALID_DEVICE_UUID');
}

if (!is_string($deviceSecret) || preg_match('/^[0-9a-f]{64}$/D', $deviceSecret) !== 1) {
    erroStatus(400, 'INVALID_DEVICE_SECRET');
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
        sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
            $db['host'],
            $db['port'] ?? 5432,
            ltrim($db['path'], '/')
        ),
        rawurldecode($db['user']),
        rawurldecode($db['pass']),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $consulta = $pdo->prepare(<<<'SQL'
        SELECT
            dispositivo.device_secret_hash,
            dispositivo.status,
            dispositivo.cliente_id,
            cliente.id,
            cliente.nome,
            cliente.usuario,
            cliente.m3u_url,
            cliente.admin_id,
            cliente.link_pagamento,
            cliente.plano
        FROM client_devices AS dispositivo
        LEFT JOIN clientes AS cliente ON cliente.id = dispositivo.cliente_id
        WHERE dispositivo.device_uuid = :device_uuid
        LIMIT 1
        SQL);
    $consulta->execute([':device_uuid' => $deviceUuid]);
    $registro = $consulta->fetch();

    if (!$registro || !hash_equals((string) $registro['device_secret_hash'], hash('sha256', $deviceSecret))) {
        erroStatus(403, 'DEVICE_REGISTRATION_DENIED');
    }

    if ($registro['status'] !== 'active' || $registro['cliente_id'] === null || $registro['id'] === null) {
        responderStatus(200, ['success' => true, 'activated' => false]);
    }

    responderStatus(200, [
        'success' => true,
        'activated' => true,
        'cliente' => [
            'id' => (int) $registro['id'],
            'nome' => $registro['nome'],
            'usuario' => $registro['usuario'],
            'm3u_url' => $registro['m3u_url'],
            'admin_id' => (int) $registro['admin_id'],
            'link_pagamento' => $registro['link_pagamento'] ?? '',
            'plano' => $registro['plano'] ?? '',
        ],
    ]);
} catch (Throwable $e) {
    erroStatus(500, 'INTERNAL_ERROR');
}
