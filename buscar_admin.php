<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/administrative_token_auth.php';

function responderBuscaAdmin(int $statusHttp, array $conteudo): never
{
    http_response_code($statusHttp);
    echo json_encode($conteudo, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderBuscaAdmin(405, [
        'success' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'message' => 'Método não permitido',
    ]);
}

try {
    $ator = autenticarTokenAdministrativo($pdo);
} catch (AdministrativeAuthException $e) {
    responderBuscaAdmin($e->getStatusHttp(), [
        'success' => false,
        'code' => $e->getCodigoPublico(),
        'message' => $e->getMensagemPublica(),
    ]);
}

if (($ator['actor_type'] ?? null) !== 'master') {
    responderBuscaAdmin(403, [
        'success' => false,
        'code' => 'FORBIDDEN',
        'message' => 'Acesso não autorizado',
    ]);
}

$id = filter_var(
    $_POST['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($id === false) {
    responderBuscaAdmin(400, [
        'success' => false,
        'code' => 'INVALID_ADMIN_ID',
        'message' => 'Administrador inválido',
    ]);
}

try {
    $stmt = $pdo->prepare(<<<'SQL'
        SELECT nome, usuario, whatsapp
        FROM admins
        WHERE id = :id
        LIMIT 1
        SQL);
    $stmt->execute([':id' => $id]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    responderBuscaAdmin(500, [
        'success' => false,
        'code' => 'INTERNAL_ERROR',
        'message' => 'Erro ao buscar administrador',
    ]);
}

if (!$admin) {
    responderBuscaAdmin(404, [
        'success' => false,
        'code' => 'ADMIN_NOT_FOUND',
        'message' => 'Administrador não encontrado',
    ]);
}

responderBuscaAdmin(200, [
    'success' => true,
    'admin' => $admin,
]);
