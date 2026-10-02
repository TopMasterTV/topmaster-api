<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responderSessaoDispositivo(int $statusHttp, array $conteudo): never
{
    http_response_code($statusHttp);
    echo json_encode($conteudo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function responderErroSessaoDispositivo(int $statusHttp, string $codigo, string $mensagem): never
{
    responderSessaoDispositivo($statusHttp, [
        'success' => false,
        'error' => [
            'code' => $codigo,
            'message' => $mensagem,
        ],
    ]);
}

function interpretarBooleanoSessaoDispositivo(mixed $valor): bool
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

    throw new UnexpectedValueException('Valor booleano invalido');
}

function obterSslmodeSessaoDispositivo(string $host): string
{
    $sslmodeConfigurado = getenv('ROKU_DATABASE_SSLMODE');

    if ($sslmodeConfigurado === false || trim($sslmodeConfigurado) === '') {
        return 'require';
    }

    $sslmode = strtolower(trim($sslmodeConfigurado));

    if ($sslmode === 'require') {
        return 'require';
    }

    if (
        $sslmode === 'disable'
        && getenv('ROKU_LOCAL_TEST_MODE') === '1'
        && $host === '127.0.0.1'
    ) {
        return 'disable';
    }

    throw new RuntimeException('Configuracao de banco invalida');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderErroSessaoDispositivo(405, 'METHOD_NOT_ALLOWED', 'Metodo nao permitido');
}

$entrada = fopen('php://input', 'rb');

if ($entrada === false) {
    responderErroSessaoDispositivo(400, 'INVALID_REQUEST', 'Requisicao invalida');
}

$corpoBruto = stream_get_contents($entrada, 4097);
fclose($entrada);

if ($corpoBruto === false) {
    responderErroSessaoDispositivo(400, 'INVALID_REQUEST', 'Requisicao invalida');
}

if (strlen($corpoBruto) > 4096) {
    responderErroSessaoDispositivo(413, 'PAYLOAD_TOO_LARGE', 'Requisicao muito grande');
}

$dados = json_decode($corpoBruto, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    responderErroSessaoDispositivo(400, 'INVALID_JSON', 'JSON invalido');
}

if (!is_array($dados) || array_key_exists('device_code', $dados)) {
    responderErroSessaoDispositivo(400, 'INVALID_REQUEST', 'Requisicao invalida');
}

$deviceUuid = $dados['device_uuid'] ?? null;
$deviceSecret = $dados['device_secret'] ?? null;

if (
    !is_string($deviceUuid)
    || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $deviceUuid) !== 1
) {
    responderErroSessaoDispositivo(400, 'INVALID_DEVICE_UUID', 'Identificador de dispositivo invalido');
}

if (!is_string($deviceSecret) || preg_match('/^[0-9a-f]{64}$/D', $deviceSecret) !== 1) {
    responderErroSessaoDispositivo(400, 'INVALID_DEVICE_SECRET', 'Credencial de dispositivo invalida');
}

$pdo = null;

try {
    $databaseUrl = getenv('DATABASE_URL');

    if (!is_string($databaseUrl) || $databaseUrl === '') {
        throw new RuntimeException('Configuracao do banco ausente');
    }

    $db = parse_url($databaseUrl);

    if (
        !is_array($db)
        || empty($db['host'])
        || empty($db['path'])
        || !isset($db['user'], $db['pass'])
    ) {
        throw new RuntimeException('Configuracao do banco invalida');
    }

    $host = $db['host'];
    $port = $db['port'] ?? 5432;
    $dbname = ltrim($db['path'], '/');
    $dbUser = rawurldecode($db['user']);
    $dbPass = rawurldecode($db['pass']);
    $sslmode = obterSslmodeSessaoDispositivo($host);

    $pdo = new PDO(
        "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode}",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $pdo->beginTransaction();

    $consulta = $pdo->prepare(<<<'SQL'
        SELECT
            dispositivo.device_secret_hash,
            dispositivo.status AS device_status,
            dispositivo.cliente_id,
            cliente.id AS cliente_existente_id,
            cliente.nome,
            cliente.usuario,
            cliente.plano,
            cliente.ativo
        FROM client_devices AS dispositivo
        LEFT JOIN clientes AS cliente
            ON cliente.id = dispositivo.cliente_id
        WHERE dispositivo.device_uuid = :device_uuid
        LIMIT 1
        FOR UPDATE OF dispositivo
        SQL);
    $consulta->execute([':device_uuid' => $deviceUuid]);
    $registro = $consulta->fetch();

    $segredoValido = is_array($registro)
        && is_string($registro['device_secret_hash'] ?? null)
        && hash_equals((string) $registro['device_secret_hash'], hash('sha256', $deviceSecret));

    if (!$segredoValido) {
        $pdo->rollBack();
        responderErroSessaoDispositivo(401, 'DEVICE_AUTH_FAILED', 'Dispositivo ou credencial invalidos');
    }

    $deviceStatus = (string) ($registro['device_status'] ?? '');

    if ($deviceStatus === 'disabled') {
        $pdo->commit();
        responderErroSessaoDispositivo(403, 'DEVICE_DISABLED', 'Dispositivo desativado');
    }

    if (
        $deviceStatus !== 'active'
        || $registro['cliente_id'] === null
        || $registro['cliente_existente_id'] === null
    ) {
        $pdo->commit();
        responderSessaoDispositivo(200, [
            'success' => true,
            'activated' => false,
            'state' => 'PENDING_ACTIVATION',
        ]);
    }

    if (!interpretarBooleanoSessaoDispositivo($registro['ativo'] ?? null)) {
        $pdo->commit();
        responderErroSessaoDispositivo(
            403,
            'CLIENT_INACTIVE',
            'Acesso indisponivel. Entre em contato com o suporte.'
        );
    }

    $tokenOriginal = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $tokenOriginal);
    $deviceIdHash = hash('sha256', $deviceUuid);

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
        ':cliente_id' => $registro['cliente_existente_id'],
        ':token_hash' => $tokenHash,
        ':app_tipo' => 'roku',
        ':app_version' => 'device-session-v1',
        ':device_id_hash' => $deviceIdHash,
    ]);

    $pdo->commit();

    responderSessaoDispositivo(200, [
        'success' => true,
        'data' => [
            'access_token' => $tokenOriginal,
            'token_type' => 'Bearer',
            'expires_in' => 2592000,
            'cliente' => [
                'id' => (int) $registro['cliente_existente_id'],
                'nome' => $registro['nome'],
                'usuario' => $registro['usuario'],
                'plano' => $registro['plano'],
            ],
        ],
    ]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('roku_device_session_error=' . get_class($e));
    responderErroSessaoDispositivo(500, 'INTERNAL_ERROR', 'Nao foi possivel iniciar a sessao');
}
