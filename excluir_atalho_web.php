<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/administrative_token_auth.php';

function responderExclusaoAtalhoWeb(int $statusHttp, array $resposta): never
{
    http_response_code($statusHttp);
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function erroExclusaoAtalhoWeb(int $statusHttp, string $codigo): never
{
    responderExclusaoAtalhoWeb($statusHttp, ['success' => false, 'error' => $codigo]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    erroExclusaoAtalhoWeb(405, 'METHOD_NOT_ALLOWED');
}

$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($contentType !== 'application/json') {
    erroExclusaoAtalhoWeb(400, 'INVALID_REQUEST');
}

$dados = json_decode((string) file_get_contents('php://input'), true);
$id = is_array($dados) ? ($dados['id'] ?? null) : null;
if (!is_string($id) || trim($id) === '' || strlen($id) > 128) {
    erroExclusaoAtalhoWeb(400, 'INVALID_ID');
}
$id = trim($id);

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
    if (!in_array($sessao['actor_type'], ['master', 'revendedor'], true)) {
        erroExclusaoAtalhoWeb(403, 'ACCESS_DENIED');
    }

    $exclusao = $pdo->prepare(<<<'SQL'
        DELETE FROM atalhos_web
        WHERE owner_type = :owner_type
          AND owner_id = :owner_id
          AND id = :id
        RETURNING id
        SQL);
    $exclusao->execute([
        ':owner_type' => $sessao['actor_type'],
        ':owner_id' => $sessao['actor_id'],
        ':id' => $id,
    ]);
    if ($exclusao->fetch() === false) {
        erroExclusaoAtalhoWeb(404, 'SHORTCUT_NOT_FOUND');
    }

    responderExclusaoAtalhoWeb(200, ['success' => true]);
} catch (AdministrativeAuthException $e) {
    erroExclusaoAtalhoWeb($e->getStatusHttp(), $e->getCodigoPublico());
} catch (Throwable $e) {
    erroExclusaoAtalhoWeb(500, 'INTERNAL_ERROR');
}
