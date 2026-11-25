<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/administrative_token_auth.php';

function responderRenovacaoMaster(int $statusHttp, array $conteudo): never
{
    http_response_code($statusHttp);
    echo json_encode($conteudo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderRenovacaoMaster(405, [
        'success' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'message' => 'Metodo nao permitido',
    ]);
}

$conteudo = file_get_contents('php://input');
$json = is_string($conteudo) && $conteudo !== '' ? json_decode($conteudo, true) : null;
$refreshToken = $_POST['refresh_token'] ?? (is_array($json) ? ($json['refresh_token'] ?? null) : null);
if (!is_string($refreshToken) || $refreshToken === '') {
    responderRenovacaoMaster(401, [
        'success' => false,
        'code' => 'REFRESH_REQUIRED',
        'message' => 'Token de renovacao necessario',
    ]);
}

try {
    $resultado = renovarSessaoMaster($pdo, $refreshToken);
} catch (AdministrativeAuthException $e) {
    responderRenovacaoMaster($e->getStatusHttp(), [
        'success' => false,
        'code' => $e->getCodigoPublico(),
        'message' => $e->getMensagemPublica(),
    ]);
} catch (Throwable $e) {
    responderRenovacaoMaster(500, [
        'success' => false,
        'code' => 'INTERNAL_ERROR',
        'message' => 'Erro ao renovar sessao administrativa',
    ]);
}

responderRenovacaoMaster(200, $resultado);
