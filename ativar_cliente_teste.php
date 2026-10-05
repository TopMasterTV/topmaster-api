<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/administrative_token_auth.php';
require_once __DIR__ . '/db.php';

function responderAtivacaoClienteTeste(int $statusHttp, array $conteudo): never
{
    http_response_code($statusHttp);
    echo json_encode($conteudo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderAtivacaoClienteTeste(405, [
        'success' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'message' => 'Metodo nao permitido',
    ]);
}

try {
    $ator = autenticarTokenAdministrativo($pdo);
} catch (AdministrativeAuthException $e) {
    responderAtivacaoClienteTeste($e->getStatusHttp(), [
        'success' => false,
        'code' => $e->getCodigoPublico(),
        'message' => $e->getMensagemPublica(),
    ]);
}

$actorType = $ator['actor_type'] ?? null;
if (!in_array($actorType, ['master', 'revendedor'], true)) {
    responderAtivacaoClienteTeste(403, [
        'success' => false,
        'code' => 'ACCESS_DENIED',
        'message' => 'Acesso nao autorizado',
    ]);
}

try {
    $dados = json_decode(
        (string) file_get_contents('php://input'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException $e) {
    $dados = null;
}

$clienteId = is_array($dados) ? ($dados['cliente_id'] ?? null) : null;
if (!is_int($clienteId) || $clienteId <= 0) {
    responderAtivacaoClienteTeste(400, [
        'success' => false,
        'code' => 'INVALID_CLIENT_ID',
        'message' => 'Cliente invalido',
    ]);
}

try {
    $sqlAtivacao = <<<'SQL'
        UPDATE public.clientes
        SET tipo_cliente = 'normal'
        WHERE id = :cliente_id
          AND tipo_cliente = 'teste'
        RETURNING id
        SQL;
    $parametrosAtivacao = [':cliente_id' => $clienteId];

    if ($actorType === 'revendedor') {
        $sqlAtivacao = <<<'SQL'
            UPDATE public.clientes
            SET tipo_cliente = 'normal'
            WHERE id = :cliente_id
              AND tipo_cliente = 'teste'
              AND revendedor_id = :actor_id
            RETURNING id
            SQL;
        $parametrosAtivacao[':actor_id'] = $ator['actor_id'];
    }

    $ativacao = $pdo->prepare($sqlAtivacao);
    $ativacao->execute($parametrosAtivacao);

    $clienteAtivado = $ativacao->fetch(PDO::FETCH_ASSOC);
    if ($ativacao->rowCount() === 1 && $clienteAtivado !== false) {
        responderAtivacaoClienteTeste(200, [
            'success' => true,
            'cliente_id' => $clienteId,
            'tipo_cliente' => 'normal',
        ]);
    }

    $sqlConsulta = <<<'SQL'
        SELECT tipo_cliente
        FROM public.clientes
        WHERE id = :cliente_id
        LIMIT 1
        SQL;
    $parametrosConsulta = [':cliente_id' => $clienteId];

    if ($actorType === 'revendedor') {
        $sqlConsulta = <<<'SQL'
            SELECT tipo_cliente
            FROM public.clientes
            WHERE id = :cliente_id
              AND revendedor_id = :actor_id
            LIMIT 1
            SQL;
        $parametrosConsulta[':actor_id'] = $ator['actor_id'];
    }

    $consulta = $pdo->prepare($sqlConsulta);
    $consulta->execute($parametrosConsulta);
    $cliente = $consulta->fetch(PDO::FETCH_ASSOC);

    if ($cliente === false) {
        responderAtivacaoClienteTeste(404, [
            'success' => false,
            'code' => 'CLIENT_NOT_FOUND',
            'message' => 'Cliente nao encontrado',
        ]);
    }

    responderAtivacaoClienteTeste(409, [
        'success' => false,
        'code' => 'CLIENT_NOT_TEST',
        'message' => 'Cliente nao esta classificado como teste',
    ]);
} catch (Throwable $e) {
    responderAtivacaoClienteTeste(500, [
        'success' => false,
        'code' => 'INTERNAL_ERROR',
        'message' => 'Erro interno',
    ]);
}
