<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function responderJsonCredencialCliente(int $statusHttp, array $conteudo): never
{
    http_response_code($statusHttp);
    echo json_encode($conteudo, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderJsonCredencialCliente(405, [
        'success' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'message' => 'Método não permitido',
    ]);
}

require_once __DIR__ . '/administrative_token_auth.php';
require_once __DIR__ . '/client_credential_crypto.php';
require_once __DIR__ . '/db.php';

try {
    $ator = autenticarTokenAdministrativo($pdo);
} catch (AdministrativeAuthException $e) {
    responderJsonCredencialCliente($e->getStatusHttp(), [
        'success' => false,
        'code' => $e->getCodigoPublico(),
        'message' => $e->getMensagemPublica(),
    ]);
}

if (!in_array(($ator['actor_type'] ?? null), ['master', 'revendedor'], true)) {
    responderJsonCredencialCliente(403, [
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

$clienteId = is_array($corpo) ? ($corpo['cliente_id'] ?? null) : null;
if (!is_int($clienteId) || $clienteId <= 0) {
    responderJsonCredencialCliente(400, [
        'success' => false,
        'code' => 'INVALID_CLIENT_ID',
        'message' => 'Cliente inválido',
    ]);
}

try {
    $consulta = $pdo->prepare(<<<'SQL'
        SELECT
            id,
            usuario,
            senha_recuperavel,
            revendedor_id
        FROM clientes
        WHERE id = :cliente_id
        LIMIT 1
        SQL);
    $consulta->execute([':cliente_id' => $clienteId]);
    $cliente = $consulta->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    responderJsonCredencialCliente(500, [
        'success' => false,
        'code' => 'INTERNAL_ERROR',
        'message' => 'Erro interno',
    ]);
}

if (!$cliente) {
    responderJsonCredencialCliente(404, [
        'success' => false,
        'code' => 'CLIENT_NOT_FOUND',
        'message' => 'Cliente não encontrado',
    ]);
}

if (
    $ator['actor_type'] === 'revendedor'
    && (int) ($cliente['revendedor_id'] ?? 0) !== $ator['actor_id']
) {
    responderJsonCredencialCliente(403, [
        'success' => false,
        'code' => 'FORBIDDEN',
        'message' => 'Acesso não autorizado',
    ]);
}

$senhaRecuperavel = $cliente['senha_recuperavel'] ?? null;
if ($senhaRecuperavel === null || $senhaRecuperavel === '') {
    responderJsonCredencialCliente(409, [
        'success' => false,
        'code' => 'CREDENTIAL_UNAVAILABLE',
        'message' => 'Senha não disponível. Defina uma nova senha para este cliente.',
    ]);
}

try {
    $senha = descriptografarSenhaRecuperavelCliente($senhaRecuperavel, $clienteId);
} catch (Throwable $e) {
    responderJsonCredencialCliente(500, [
        'success' => false,
        'code' => 'CREDENTIAL_DECRYPTION_FAILED',
        'message' => 'Não foi possível obter a credencial do cliente',
    ]);
}

responderJsonCredencialCliente(200, [
    'success' => true,
    'usuario' => (string) $cliente['usuario'],
    'senha' => $senha,
]);
