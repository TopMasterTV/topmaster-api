<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/administrative_token_auth.php';

function responderCredencialRevendedor(int $statusHttp, array $conteudo): never
{
    http_response_code($statusHttp);
    echo json_encode($conteudo, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderCredencialRevendedor(405, [
        'success' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'message' => 'Método não permitido',
    ]);
}

try {
    $ator = autenticarTokenAdministrativo($pdo);
} catch (AdministrativeAuthException $e) {
    responderCredencialRevendedor($e->getStatusHttp(), [
        'success' => false,
        'code' => $e->getCodigoPublico(),
        'message' => $e->getMensagemPublica(),
    ]);
}

if (($ator['actor_type'] ?? null) !== 'master') {
    responderCredencialRevendedor(403, [
        'success' => false,
        'code' => 'FORBIDDEN',
        'message' => 'Acesso não autorizado',
    ]);
}

$corpoBruto = file_get_contents('php://input');

try {
    $corpo = json_decode(
        is_string($corpoBruto) ? $corpoBruto : '',
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException $e) {
    $corpo = null;
}

$revendedorId = is_array($corpo) ? ($corpo['revendedor_id'] ?? null) : null;
if (!is_int($revendedorId) || $revendedorId <= 0) {
    responderCredencialRevendedor(400, [
        'success' => false,
        'code' => 'INVALID_RESELLER_ID',
        'message' => 'Revendedor inválido',
    ]);
}

try {
    $consulta = $pdo->prepare(<<<'SQL'
        SELECT usuario, senha_visivel
        FROM admins
        WHERE id = :revendedor_id
          AND tipo = 'revendedor'
        LIMIT 1
        SQL);
    $consulta->execute([':revendedor_id' => $revendedorId]);
    $revendedor = $consulta->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    responderCredencialRevendedor(500, [
        'success' => false,
        'code' => 'INTERNAL_ERROR',
        'message' => 'Erro interno',
    ]);
}

if (!$revendedor) {
    responderCredencialRevendedor(404, [
        'success' => false,
        'code' => 'RESELLER_NOT_FOUND',
        'message' => 'Revendedor não encontrado',
    ]);
}

$senhaVisivel = $revendedor['senha_visivel'] ?? null;
if (!is_string($senhaVisivel) || $senhaVisivel === '') {
    responderCredencialRevendedor(409, [
        'success' => false,
        'code' => 'CREDENTIAL_UNAVAILABLE',
        'message' => 'Senha não disponível. Defina uma nova senha para este revendedor.',
    ]);
}

responderCredencialRevendedor(200, [
    'success' => true,
    'usuario' => (string) $revendedor['usuario'],
    'senha' => $senhaVisivel,
]);
