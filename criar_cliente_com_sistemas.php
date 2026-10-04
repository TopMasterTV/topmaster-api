<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/administrative_token_auth.php';
require_once __DIR__ . '/client_credential_crypto.php';
require_once __DIR__ . '/db.php';

function responderCadastroCliente(int $statusHttp, array $conteudo): never
{
    http_response_code($statusHttp);
    echo json_encode($conteudo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function textoCadastroCliente(mixed $valor): string
{
    return is_string($valor) ? trim($valor) : '';
}

function dataCadastroClienteValida(string $valor): bool
{
    $data = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    return $data !== false && $data->format('Y-m-d') === $valor;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderCadastroCliente(405, [
        'success' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'message' => 'Metodo nao permitido',
    ]);
}

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
if ($contentType !== 'application/json') {
    responderCadastroCliente(415, [
        'success' => false,
        'code' => 'UNSUPPORTED_MEDIA_TYPE',
        'message' => 'Content-Type deve ser application/json',
    ]);
}

try {
    $ator = autenticarTokenAdministrativo($pdo);
} catch (AdministrativeAuthException $e) {
    responderCadastroCliente($e->getStatusHttp(), [
        'success' => false,
        'code' => $e->getCodigoPublico(),
        'message' => $e->getMensagemPublica(),
    ]);
}

if (($ator['actor_type'] ?? null) !== 'master') {
    responderCadastroCliente(403, [
        'success' => false,
        'code' => 'ACCESS_DENIED',
        'message' => 'Acesso nao autorizado',
    ]);
}

try {
    $payload = json_decode(
        (string) file_get_contents('php://input'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException $e) {
    $payload = null;
}

$cliente = is_array($payload) && is_array($payload['cliente'] ?? null)
    ? $payload['cliente']
    : null;
$sistemasRecebidos = is_array($payload) && is_array($payload['sistemas'] ?? null)
    ? $payload['sistemas']
    : null;

if ($cliente === null || $sistemasRecebidos === null) {
    responderCadastroCliente(400, [
        'success' => false,
        'code' => 'INVALID_REQUEST',
        'message' => 'Cadastro invalido',
    ]);
}

$nome = textoCadastroCliente($cliente['nome'] ?? null);
$usuario = textoCadastroCliente($cliente['usuario'] ?? null);
$senha = textoCadastroCliente($cliente['senha'] ?? null);
$whatsapp = textoCadastroCliente($cliente['whatsapp'] ?? null);
$linkPagamento = textoCadastroCliente($cliente['link_pagamento'] ?? null);
$plano = textoCadastroCliente($cliente['plano'] ?? null);
$tipoCliente = strtolower(textoCadastroCliente($cliente['tipo_cliente'] ?? 'normal'));

if ($nome === '' || $usuario === '' || $senha === '') {
    responderCadastroCliente(400, [
        'success' => false,
        'code' => 'INVALID_CLIENT',
        'message' => 'Nome, usuario e senha sao obrigatorios',
    ]);
}

if (!in_array($tipoCliente, ['normal', 'teste'], true)) {
    responderCadastroCliente(400, [
        'success' => false,
        'code' => 'INVALID_CLIENT_TYPE',
        'message' => 'Tipo de cliente invalido',
    ]);
}

if (count($sistemasRecebidos) < 1) {
    responderCadastroCliente(400, [
        'success' => false,
        'code' => 'SYSTEM_REQUIRED',
        'message' => 'Adicione pelo menos um sistema antes de salvar o cliente.',
    ]);
}

$sistemas = [];
$modelosIds = [];
foreach ($sistemasRecebidos as $sistema) {
    if (!is_array($sistema)) {
        responderCadastroCliente(400, [
            'success' => false,
            'code' => 'INVALID_SYSTEM',
            'message' => 'Sistema invalido',
        ]);
    }

    $modeloId = filter_var(
        $sistema['modelo_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $url = textoCadastroCliente($sistema['url'] ?? null);
    $usuarioSistema = textoCadastroCliente($sistema['usuario'] ?? null);
    $senhaSistema = textoCadastroCliente($sistema['senha'] ?? null);
    $vencimento = textoCadastroCliente($sistema['vencimento'] ?? null);
    $m3uUrl = textoCadastroCliente($sistema['m3u_url'] ?? null);

    if (
        $modeloId === false
        || $url === ''
        || $usuarioSistema === ''
        || $senhaSistema === ''
        || !dataCadastroClienteValida($vencimento)
    ) {
        responderCadastroCliente(400, [
            'success' => false,
            'code' => 'INVALID_SYSTEM',
            'message' => 'Dados de sistema invalidos',
        ]);
    }

    $modelosIds[$modeloId] = $modeloId;
    $sistemas[] = [
        'modelo_id' => $modeloId,
        'url' => $url,
        'usuario' => $usuarioSistema,
        'senha' => $senhaSistema,
        'vencimento' => $vencimento,
        'm3u_url' => $m3uUrl,
    ];
}

$marcadores = implode(',', array_fill(0, count($modelosIds), '?'));
$consultaModelos = $pdo->prepare(
    "SELECT id, nome FROM public.modelos_sistemas WHERE id IN ($marcadores)"
);
$consultaModelos->execute(array_values($modelosIds));
$nomesModelos = [];
foreach ($consultaModelos->fetchAll(PDO::FETCH_ASSOC) as $modelo) {
    $nomesModelos[(int) $modelo['id']] = (string) $modelo['nome'];
}
if (count($nomesModelos) !== count($modelosIds)) {
    responderCadastroCliente(400, [
        'success' => false,
        'code' => 'INVALID_SYSTEM_MODEL',
        'message' => 'Modelo de sistema invalido',
    ]);
}

try {
    $pdo->beginTransaction();

    $inserirCliente = $pdo->prepare(<<<'SQL'
        INSERT INTO public.clientes (
            nome,
            usuario,
            senha,
            m3u_url,
            whatsapp,
            link_pagamento,
            plano,
            admin_id,
            revendedor_id,
            revendedor_nome,
            tipo_cliente
        ) VALUES (
            :nome,
            :usuario,
            :senha,
            'pendente',
            :whatsapp,
            :link_pagamento,
            :plano,
            :admin_id,
            NULL,
            NULL,
            :tipo_cliente
        )
        RETURNING id
        SQL);
    $inserirCliente->execute([
        ':nome' => $nome,
        ':usuario' => $usuario,
        ':senha' => password_hash($senha, PASSWORD_DEFAULT),
        ':whatsapp' => $whatsapp,
        ':link_pagamento' => $linkPagamento,
        ':plano' => $plano,
        ':admin_id' => $ator['actor_id'],
        ':tipo_cliente' => $tipoCliente,
    ]);

    $clienteId = filter_var(
        $inserirCliente->fetchColumn(),
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    if ($clienteId === false) {
        throw new RuntimeException('Cliente criado sem identificador valido');
    }

    $atualizarCredencial = $pdo->prepare(<<<'SQL'
        UPDATE public.clientes
        SET senha_recuperavel = :senha_recuperavel
        WHERE id = :cliente_id
        SQL);
    $atualizarCredencial->execute([
        ':senha_recuperavel' => criptografarSenhaRecuperavelCliente($senha, $clienteId),
        ':cliente_id' => $clienteId,
    ]);
    if ($atualizarCredencial->rowCount() !== 1) {
        throw new RuntimeException('Credencial do cliente nao foi gravada');
    }

    $inserirSistema = $pdo->prepare(<<<'SQL'
        INSERT INTO public.sistemas (
            cliente_id,
            modelo_id,
            nome_sistema,
            usuario,
            senha,
            url,
            vencimento,
            m3u_url
        ) VALUES (
            :cliente_id,
            :modelo_id,
            :nome_sistema,
            :usuario,
            :senha,
            :url,
            :vencimento,
            :m3u_url
        )
        SQL);

    foreach ($sistemas as $sistema) {
        $inserirSistema->execute([
            ':cliente_id' => $clienteId,
            ':modelo_id' => $sistema['modelo_id'],
            ':nome_sistema' => $nomesModelos[$sistema['modelo_id']],
            ':usuario' => $sistema['usuario'],
            ':senha' => $sistema['senha'],
            ':url' => $sistema['url'],
            ':vencimento' => $sistema['vencimento'],
            ':m3u_url' => $sistema['m3u_url'],
        ]);
        if ($inserirSistema->rowCount() !== 1) {
            throw new RuntimeException('Sistema nao foi criado');
        }
    }

    $pdo->commit();

    responderCadastroCliente(200, [
        'success' => true,
        'cliente_id' => $clienteId,
        'sistemas_criados' => count($sistemas),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($e instanceof PDOException && $e->getCode() === '23505') {
        responderCadastroCliente(409, [
            'success' => false,
            'code' => 'CLIENT_ALREADY_EXISTS',
            'message' => 'Usuario de cliente ja cadastrado',
        ]);
    }

    responderCadastroCliente(500, [
        'success' => false,
        'code' => 'INTERNAL_ERROR',
        'message' => 'Erro ao criar cliente e sistemas',
    ]);
}
