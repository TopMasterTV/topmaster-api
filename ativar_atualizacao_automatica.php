<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/administrative_token_auth.php';
require_once __DIR__ . '/atualizacao_automatica_config_repository.php';

function responderAtivacaoAtualizacaoAutomatica(int $status, array $conteudo): never
{
    http_response_code($status);
    echo json_encode($conteudo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderAtivacaoAtualizacaoAutomatica(405, [
        'success' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'message' => 'Metodo nao permitido',
    ]);
}

try {
    $ator = autenticarTokenAdministrativo($pdo);
} catch (AdministrativeAuthException $e) {
    responderAtivacaoAtualizacaoAutomatica($e->getStatusHttp(), [
        'success' => false,
        'code' => $e->getCodigoPublico(),
        'message' => $e->getMensagemPublica(),
    ]);
} catch (Throwable) {
    responderAtivacaoAtualizacaoAutomatica(500, [
        'success' => false,
        'code' => 'INTERNAL_ERROR',
        'message' => 'Erro ao autenticar administrador',
    ]);
}

if (($ator['actor_type'] ?? null) !== 'master') {
    responderAtivacaoAtualizacaoAutomatica(403, [
        'success' => false,
        'code' => 'FORBIDDEN',
        'message' => 'Acesso nao autorizado',
    ]);
}

try {
    $repositorio = new AtualizacaoAutomaticaConfigRepository(
        new AtualizacaoAutomaticaPdoDatabase($pdo)
    );
    $resultado = $repositorio->ativar((int) $ator['actor_id']);
} catch (Throwable $e) {
    responderAtivacaoAtualizacaoAutomatica(500, [
        'success' => false,
        'code' => 'INTERNAL_ERROR',
        'message' => 'Erro ao ativar atualizacao automatica',
    ]);
}

$alterada = $resultado['alterada'];
responderAtivacaoAtualizacaoAutomatica(200, [
    'success' => true,
    'code' => $alterada ? 'AUTOMATION_ACTIVATED' : 'AUTOMATION_ALREADY_ACTIVE',
    'message' => $alterada
        ? 'Atualizacao automatica ativada'
        : 'Atualizacao automatica ja estava ativada',
    'automacao' => $resultado['estado'],
]);
