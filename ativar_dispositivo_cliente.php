<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/administrative_token_auth.php';

function responderAtivacao(int $statusHttp, array $resposta): never
{
    http_response_code($statusHttp);
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function erroAtivacao(int $statusHttp, string $codigo): never
{
    responderAtivacao($statusHttp, ['success' => false, 'error' => $codigo]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    erroAtivacao(405, 'METHOD_NOT_ALLOWED');
}

$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($contentType !== 'application/json') {
    erroAtivacao(400, 'INVALID_REQUEST');
}

$dados = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($dados)) {
    erroAtivacao(400, 'INVALID_REQUEST');
}

$deviceCode = $dados['device_code'] ?? null;
$clienteId = $dados['cliente_id'] ?? null;

if (!is_string($deviceCode) || preg_match('/^[A-Z]{3}-[0-9]{3}-[A-Z]{3}$/D', $deviceCode) !== 1) {
    erroAtivacao(400, 'INVALID_DEVICE_CODE');
}

$clienteId = filter_var($clienteId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($clienteId === false) {
    erroAtivacao(400, 'INVALID_CLIENTE_ID');
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

    $sessao = autenticarTokenAdministrativo($pdo);
    if ($sessao['actor_type'] !== 'master') {
        erroAtivacao(403, 'ACCESS_DENIED');
    }

    $ativacao = $pdo->prepare(<<<'SQL'
        UPDATE client_devices AS dispositivo
        SET
            cliente_id = cliente.id,
            status = 'active',
            owner_actor_type = 'master',
            owner_actor_id = :actor_id,
            first_activated_at = COALESCE(dispositivo.first_activated_at, clock_timestamp()),
            disabled_at = NULL
        FROM clientes AS cliente
        WHERE dispositivo.device_code = :device_code
          AND cliente.id = :cliente_id
        RETURNING dispositivo.device_code
        SQL);
    $ativacao->execute([
        ':actor_id' => $sessao['actor_id'],
        ':device_code' => $deviceCode,
        ':cliente_id' => $clienteId,
    ]);

    if ($ativacao->fetch() === false) {
        erroAtivacao(404, 'DEVICE_OR_CLIENT_NOT_FOUND');
    }

    responderAtivacao(200, ['success' => true]);
} catch (AdministrativeAuthException $e) {
    erroAtivacao($e->getStatusHttp(), $e->getCodigoPublico());
} catch (Throwable $e) {
    erroAtivacao(500, 'INTERNAL_ERROR');
}
