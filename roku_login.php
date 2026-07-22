<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responderJson(int $statusHttp, array $conteudo): never
{
    http_response_code($statusHttp);
    echo json_encode($conteudo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function responderErro(int $statusHttp, string $codigo, string $mensagem): never
{
    responderJson($statusHttp, [
        'success' => false,
        'error' => [
            'code' => $codigo,
            'message' => $mensagem,
        ],
    ]);
}

function interpretarBooleanoPostgres(mixed $valor): bool
{
    if (is_bool($valor)) {
        return $valor;
    }

    if ($valor === 1 || $valor === '1' || $valor === 't' || $valor === 'true') {
        return true;
    }

    if ($valor === 0 || $valor === '0' || $valor === 'f' || $valor === 'false' || $valor === null) {
        return false;
    }

    throw new UnexpectedValueException('Valor booleano inválido');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderErro(405, 'METHOD_NOT_ALLOWED', 'Método não permitido');
}

$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));

if ($contentType !== 'application/json') {
    responderErro(400, 'INVALID_REQUEST', 'Requisição inválida');
}

$limiteCorpo = 16384;
$contentLength = $_SERVER['HTTP_CONTENT_LENGTH'] ?? $_SERVER['CONTENT_LENGTH'] ?? null;

if ($contentLength !== null && is_numeric($contentLength) && (float) $contentLength > $limiteCorpo) {
    responderErro(413, 'PAYLOAD_TOO_LARGE', 'Requisição muito grande');
}

$entrada = @fopen('php://input', 'rb');

if ($entrada === false) {
    responderErro(400, 'INVALID_REQUEST', 'Requisição inválida');
}

$corpoBruto = @stream_get_contents($entrada, $limiteCorpo + 1);
fclose($entrada);

if ($corpoBruto === false) {
    responderErro(400, 'INVALID_REQUEST', 'Requisição inválida');
}

if (strlen($corpoBruto) > $limiteCorpo) {
    responderErro(413, 'PAYLOAD_TOO_LARGE', 'Requisição muito grande');
}

$dados = json_decode($corpoBruto, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    responderErro(400, 'INVALID_JSON', 'JSON inválido');
}

if (!is_array($dados)) {
    responderErro(400, 'INVALID_REQUEST', 'Requisição inválida');
}

$usuario = $dados['usuario'] ?? null;
$senha = $dados['senha'] ?? null;
$appTipo = $dados['app_tipo'] ?? null;
$appVersion = $dados['app_version'] ?? null;
$deviceId = $dados['device_id'] ?? null;

if (!is_string($usuario) || !is_string($senha) || !is_string($appTipo) || !is_string($appVersion)) {
    responderErro(400, 'INVALID_REQUEST', 'Requisição inválida');
}

if ($deviceId !== null && !is_string($deviceId)) {
    responderErro(400, 'INVALID_REQUEST', 'Requisição inválida');
}

$usuario = trim($usuario);
$appVersion = trim($appVersion);

if ($deviceId !== null) {
    $deviceId = trim($deviceId);

    if ($deviceId === '') {
        $deviceId = null;
    } elseif (strlen($deviceId) > 255) {
        responderErro(400, 'INVALID_REQUEST', 'Requisição inválida');
    }
}

if (
    $usuario === '' ||
    strlen($usuario) > 100 ||
    $senha === '' ||
    strlen($senha) > 255 ||
    $appTipo !== 'roku' ||
    $appVersion === '' ||
    strlen($appVersion) > 30
) {
    responderErro(400, 'INVALID_REQUEST', 'Requisição inválida');
}

// TODO: implementar rate limiting antes da publicação.
$pdo = null;

try {
    $databaseUrl = getenv('DATABASE_URL');

    if (!is_string($databaseUrl) || $databaseUrl === '') {
        throw new RuntimeException('Configuração do banco ausente');
    }

    $db = parse_url($databaseUrl);

    if (
        !is_array($db) ||
        empty($db['host']) ||
        empty($db['path']) ||
        !isset($db['user'], $db['pass'])
    ) {
        throw new RuntimeException('Configuração do banco inválida');
    }

    $host = $db['host'];
    $port = $db['port'] ?? 5432;
    $dbname = ltrim($db['path'], '/');
    $dbUser = rawurldecode($db['user']);
    $dbPass = rawurldecode($db['pass']);

    $pdo = new PDO(
        "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $consultaCliente = $pdo->prepare(<<<'SQL'
        SELECT
            id,
            nome,
            usuario,
            senha,
            plano,
            ativo
        FROM clientes
        WHERE usuario = :usuario
        LIMIT 1
        SQL);
    $consultaCliente->execute([':usuario' => $usuario]);
    $cliente = $consultaCliente->fetch();

    if (!$cliente) {
        responderErro(401, 'INVALID_CREDENTIALS', 'Usuário ou senha inválidos');
    }

    $senhaArmazenada = (string) $cliente['senha'];
    $senhaValida = password_verify($senha, $senhaArmazenada);
    $atualizarHash = $senhaValida && password_needs_rehash($senhaArmazenada, PASSWORD_DEFAULT);

    // Fallback temporário para senhas legadas em texto simples; remover após a migração completa.
    if (!$senhaValida && (password_get_info($senhaArmazenada)['algoName'] ?? 'unknown') === 'unknown') {
        $senhaValida = hash_equals($senhaArmazenada, $senha);
        $atualizarHash = $senhaValida;
    }

    if (!$senhaValida) {
        responderErro(401, 'INVALID_CREDENTIALS', 'Usuário ou senha inválidos');
    }

    if (!interpretarBooleanoPostgres($cliente['ativo'] ?? null)) {
        responderErro(403, 'CLIENT_INACTIVE', 'Acesso indisponível. Entre em contato com o suporte.');
    }

    $tokenOriginal = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $tokenOriginal);
    $deviceIdHash = $deviceId !== null ? hash('sha256', $deviceId) : null;

    $pdo->beginTransaction();

    if ($atualizarHash) {
        $novoHashSenha = password_hash($senha, PASSWORD_DEFAULT);

        if ($novoHashSenha === false) {
            throw new RuntimeException('Falha ao atualizar credencial');
        }

        $atualizacaoSenha = $pdo->prepare(
            'UPDATE clientes SET senha = :senha WHERE id = :id'
        );
        $atualizacaoSenha->execute([
            ':senha' => $novoHashSenha,
            ':id' => $cliente['id'],
        ]);
    }

    $insercaoToken = $pdo->prepare(<<<'SQL'
        INSERT INTO cliente_tokens (
            cliente_id,
            token_hash,
            app_tipo,
            app_version,
            device_id_hash,
            expira_em
        )
        VALUES (
            :cliente_id,
            :token_hash,
            :app_tipo,
            :app_version,
            :device_id_hash,
            NOW() + INTERVAL '30 days'
        )
        SQL);
    $insercaoToken->execute([
        ':cliente_id' => $cliente['id'],
        ':token_hash' => $tokenHash,
        ':app_tipo' => 'roku',
        ':app_version' => $appVersion,
        ':device_id_hash' => $deviceIdHash,
    ]);

    $pdo->commit();

    responderJson(200, [
        'success' => true,
        'data' => [
            'access_token' => $tokenOriginal,
            'token_type' => 'Bearer',
            'expires_in' => 2592000,
            'cliente' => [
                'id' => (int) $cliente['id'],
                'nome' => $cliente['nome'],
                'usuario' => $cliente['usuario'],
                'plano' => $cliente['plano'],
            ],
        ],
    ]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable $rollbackError) {
            // Mantém a resposta genérica mesmo se o rollback falhar.
        }
    }

    responderErro(500, 'INTERNAL_ERROR', 'Não foi possível concluir o login');
}
