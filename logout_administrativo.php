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

try {
    revogarTokenAdministrativoApresentado($pdo);
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
