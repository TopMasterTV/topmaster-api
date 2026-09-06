<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/administrative_token_auth.php';

function responderListaDispositivos(int $statusHttp, array $resposta): never
{
    http_response_code($statusHttp);
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function erroListaDispositivos(int $statusHttp, string $codigo): never
{
    responderListaDispositivos($statusHttp, ['success' => false, 'error' => $codigo]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    erroListaDispositivos(405, 'METHOD_NOT_ALLOWED');
}

$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($contentType !== 'application/json') {
    erroListaDispositivos(400, 'INVALID_REQUEST');
}

$dados = json_decode((string) file_get_contents('php://input'), true);
$clienteId = is_array($dados) ? ($dados['cliente_id'] ?? null) : null;
$clienteId = filter_var($clienteId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($clienteId === false) {
    erroListaDispositivos(400, 'INVALID_CLIENTE_ID');
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
        erroListaDispositivos(403, 'ACCESS_DENIED');
    }

    $consulta = $pdo->prepare(<<<'SQL'
        SELECT device_code, status
        FROM client_devices
        WHERE cliente_id = :cliente_id
        ORDER BY first_activated_at DESC NULLS LAST, created_at DESC
        SQL);
    $consulta->execute([':cliente_id' => $clienteId]);

    responderListaDispositivos(200, ['success' => true, 'devices' => $consulta->fetchAll()]);
} catch (AdministrativeAuthException $e) {
    erroListaDispositivos($e->getStatusHttp(), $e->getCodigoPublico());
} catch (Throwable $e) {
    erroListaDispositivos(500, 'INTERNAL_ERROR');
}
