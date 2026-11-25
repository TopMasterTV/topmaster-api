<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/administrative_token_auth.php';

function responderLogoutAdministrativo(int $statusHttp, array $conteudo): never
{
    http_response_code($statusHttp);
    echo json_encode($conteudo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderLogoutAdministrativo(405, [
        'success' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'message' => 'Metodo nao permitido',
    ]);
}

$conteudo = file_get_contents('php://input');
$json = is_string($conteudo) && $conteudo !== '' ? json_decode($conteudo, true) : null;
$refreshToken = $_POST['refresh_token'] ?? (is_array($json) ? ($json['refresh_token'] ?? null) : null);
if ($refreshToken !== null && !is_string($refreshToken)) {
    responderLogoutAdministrativo(400, [
        'success' => false,
        'code' => 'INVALID_REFRESH_TOKEN',
        'message' => 'Token de renovacao invalido',
    ]);
}

try {
    revogarCredenciaisAdministrativasApresentadas($pdo, $refreshToken);
} catch (AdministrativeAuthException $e) {
    responderLogoutAdministrativo($e->getStatusHttp(), [
        'success' => false,
        'code' => $e->getCodigoPublico(),
        'message' => $e->getMensagemPublica(),
    ]);
} catch (Throwable $e) {
    responderLogoutAdministrativo(500, [
        'success' => false,
        'code' => 'INTERNAL_ERROR',
        'message' => 'Erro ao encerrar sessao administrativa',
    ]);
}

responderLogoutAdministrativo(200, [
    'success' => true,
    'code' => 'SESSION_CLOSED',
    'message' => 'Sessao administrativa encerrada',
]);
