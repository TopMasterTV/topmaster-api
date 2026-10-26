<?php

declare(strict_types=1);

const SAMSUNG_DUMMY_PASSWORD_HASH = '$2y$10$sktftvotwo75oQ8nmJXfUOXEv/ocR0wA/F.FuVldLCEhJuh1Cp85u';

require_once __DIR__ . '/samsung_common.php';

function samsungClientIp(): string
{
    if (samsungRequestCameFromTrustedProxy()) {
        /* O proxy confiavel deve remover o X-Forwarded-For recebido do cliente. */
        $forwarded = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
        if (filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
            return $forwarded;
        }
    }

    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if (filter_var($remote, FILTER_VALIDATE_IP) !== false) {
        return $remote;
    }

    throw new RuntimeException('Endereço IP indisponível');
}

function samsungPrepareRateLimits(PDO $pdo, string $userHash, string $ipHash): array
{
    $insert = $pdo->prepare(<<<'SQL'
        INSERT INTO login_rate_limits (
            app_tipo, escopo, chave_hash, tentativas_falhas,
            janela_inicio, ultima_tentativa_em, criado_em, atualizado_em
        ) VALUES
            ('samsung', 'usuario', :user_hash, 0, clock_timestamp(), clock_timestamp(), clock_timestamp(), clock_timestamp()),
            ('samsung', 'ip', :ip_hash, 0, clock_timestamp(), clock_timestamp(), clock_timestamp(), clock_timestamp())
        ON CONFLICT (app_tipo, escopo, chave_hash) DO NOTHING
        SQL);
    $insert->execute([':user_hash' => $userHash, ':ip_hash' => $ipHash]);

    $reset = $pdo->prepare(<<<'SQL'
        UPDATE login_rate_limits
        SET tentativas_falhas = 0,
            janela_inicio = clock_timestamp(),
            bloqueado_ate = NULL,
            atualizado_em = clock_timestamp()
        WHERE app_tipo = 'samsung'
          AND ((escopo = 'usuario' AND chave_hash = :user_hash)
            OR (escopo = 'ip' AND chave_hash = :ip_hash))
          AND ((bloqueado_ate IS NOT NULL AND bloqueado_ate <= clock_timestamp())
            OR (bloqueado_ate IS NULL AND janela_inicio < clock_timestamp() - INTERVAL '15 minutes'))
        SQL);
    $reset->execute([':user_hash' => $userHash, ':ip_hash' => $ipHash]);

    $select = $pdo->prepare(<<<'SQL'
        SELECT escopo, chave_hash, tentativas_falhas,
            CASE WHEN bloqueado_ate > clock_timestamp()
                THEN GREATEST(1, CEIL(EXTRACT(EPOCH FROM (bloqueado_ate - clock_timestamp())))::INTEGER)
                ELSE 0 END AS retry_after
        FROM login_rate_limits
        WHERE app_tipo = 'samsung'
          AND ((escopo = 'usuario' AND chave_hash = :user_hash)
            OR (escopo = 'ip' AND chave_hash = :ip_hash))
        ORDER BY escopo
        FOR UPDATE
        SQL);
    $select->execute([':user_hash' => $userHash, ':ip_hash' => $ipHash]);
    $rows = $select->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) !== 2) {
        throw new RuntimeException('Controle de tentativas incompleto');
    }
    return $rows;
}

function samsungRetryAfter(array $rows): int
{
    $seconds = 0;
    foreach ($rows as $row) {
        $seconds = max($seconds, (int) ($row['retry_after'] ?? 0));
    }
    return $seconds;
}

function samsungRecordLoginFailure(PDO $pdo, array $rows): int
{
    $update = $pdo->prepare(<<<'SQL'
        UPDATE login_rate_limits
        SET tentativas_falhas = :attempts,
            bloqueado_ate = CASE WHEN CAST(:blocked AS INTEGER) = 1
                THEN clock_timestamp() + CASE WHEN escopo = 'usuario'
                    THEN INTERVAL '15 minutes' ELSE INTERVAL '30 minutes' END
                ELSE NULL END,
            ultima_tentativa_em = clock_timestamp(),
            atualizado_em = clock_timestamp()
        WHERE app_tipo = 'samsung' AND escopo = :scope AND chave_hash = :key_hash
        SQL);

    foreach ($rows as $row) {
        $scope = (string) $row['escopo'];
        $attempts = (int) $row['tentativas_falhas'] + 1;
        $threshold = $scope === 'usuario' ? 5 : 20;
        $update->execute([
            ':attempts' => $attempts,
            ':blocked' => $attempts >= $threshold ? 1 : 0,
            ':scope' => $scope,
            ':key_hash' => $row['chave_hash'],
        ]);
    }

    $retry = 0;
    foreach ($rows as $row) {
        $attempts = (int) $row['tentativas_falhas'] + 1;
        if ((string) $row['escopo'] === 'usuario' && $attempts >= 5) { $retry = max($retry, 900); }
        if ((string) $row['escopo'] === 'ip' && $attempts >= 20) { $retry = max($retry, 1800); }
    }
    return $retry;
}

function samsungClearRateLimits(PDO $pdo, string $userHash, string $ipHash): void
{
    $clear = $pdo->prepare(<<<'SQL'
        UPDATE login_rate_limits
        SET tentativas_falhas = 0, janela_inicio = clock_timestamp(), bloqueado_ate = NULL,
            ultima_tentativa_em = clock_timestamp(), atualizado_em = clock_timestamp()
        WHERE app_tipo = 'samsung'
          AND ((escopo = 'usuario' AND chave_hash = :user_hash)
            OR (escopo = 'ip' AND chave_hash = :ip_hash))
        SQL);
    $clear->execute([':user_hash' => $userHash, ':ip_hash' => $ipHash]);
}

function samsungRateLimited(int $retryAfter): never
{
    header('Retry-After: ' . max(1, $retryAfter));
    samsungJson(429, [
        'success' => false,
        'error' => [
            'code' => 'RATE_LIMITED',
            'message' => 'Muitas tentativas. Aguarde e tente novamente.',
            'retry_after' => max(1, $retryAfter),
        ],
    ]);
}

samsungRequireMethod('POST');
$input = samsungReadJsonBody();
$allowedKeys = ['usuario', 'senha', 'app_version', 'device_id'];
foreach (array_keys($input) as $key) {
    if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
        samsungJsonError(400, 'INVALID_REQUEST', 'Requisição inválida');
    }
}

$user = $input['usuario'] ?? null;
$password = $input['senha'] ?? null;
$appVersion = $input['app_version'] ?? null;
$deviceId = $input['device_id'] ?? null;

if (!is_string($user) || !is_string($password) || !is_string($appVersion) || ($deviceId !== null && !is_string($deviceId))) {
    samsungJsonError(400, 'INVALID_REQUEST', 'Requisição inválida');
}

$user = trim($user);
$appVersion = trim($appVersion);
$deviceId = $deviceId !== null ? trim($deviceId) : null;
if ($deviceId === '') { $deviceId = null; }

if ($user === '' || strlen($user) > 100 || $password === '' || strlen($password) > 255
    || $appVersion === '' || strlen($appVersion) > 30 || ($deviceId !== null && strlen($deviceId) > 255)) {
    samsungJsonError(400, 'INVALID_REQUEST', 'Requisição inválida');
}

$pdo = null;
try {
    $hmacKey = getenv('LOGIN_RATE_LIMIT_HMAC_KEY');
    if (!is_string($hmacKey) || strlen($hmacKey) < 32) {
        throw new RuntimeException('Configuração de segurança ausente');
    }

    $userHash = hash_hmac('sha256', $user, $hmacKey);
    $ipHash = hash_hmac('sha256', samsungClientIp(), $hmacKey);
    $pdo = samsungDatabase();
    $pdo->beginTransaction();

    $rateRows = samsungPrepareRateLimits($pdo, $userHash, $ipHash);
    $retryAfter = samsungRetryAfter($rateRows);
    if ($retryAfter > 0) {
        $pdo->commit();
        samsungRateLimited($retryAfter);
    }

    $statement = $pdo->prepare('SELECT id, nome, usuario, senha, plano, ativo FROM clientes WHERE usuario = :usuario LIMIT 1');
    $statement->execute([':usuario' => $user]);
    $client = $statement->fetch(PDO::FETCH_ASSOC);

    $validPassword = false;
    if ($client) {
        $storedPassword = (string) $client['senha'];
        $validPassword = password_verify($password, $storedPassword);
        if (!$validPassword && (password_get_info($storedPassword)['algoName'] ?? 'unknown') === 'unknown') {
            $validPassword = hash_equals($storedPassword, $password);
        }
    } else {
        password_verify($password, SAMSUNG_DUMMY_PASSWORD_HASH);
    }

    if (!$validPassword) {
        $retryAfter = samsungRecordLoginFailure($pdo, $rateRows);
        $pdo->commit();
        if ($retryAfter > 0) { samsungRateLimited($retryAfter); }
        samsungJsonError(401, 'INVALID_CREDENTIALS', 'Usuário ou senha inválidos');
    }

    samsungClearRateLimits($pdo, $userHash, $ipHash);
    if (!samsungPgBool($client['ativo'] ?? null)) {
        $pdo->commit();
        samsungJsonError(403, 'CLIENT_INACTIVE', 'Acesso indisponível. Entre em contato com o suporte.');
    }

    $token = bin2hex(random_bytes(32));
    $insertToken = $pdo->prepare(<<<'SQL'
        INSERT INTO cliente_tokens (cliente_id, token_hash, app_tipo, app_version, device_id_hash, expira_em)
        VALUES (:cliente_id, :token_hash, 'samsung', :app_version, :device_id_hash,
            clock_timestamp() + INTERVAL '30 days')
        SQL);
    $insertToken->execute([
        ':cliente_id' => $client['id'],
        ':token_hash' => hash('sha256', $token),
        ':app_version' => $appVersion,
        ':device_id_hash' => $deviceId !== null ? hash('sha256', $deviceId) : null,
    ]);
    $pdo->commit();

    samsungJson(200, [
        'success' => true,
        'data' => [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => SAMSUNG_TOKEN_TTL_SECONDS,
            'cliente' => [
                'id' => (int) $client['id'],
                'nome' => (string) $client['nome'],
                'usuario' => (string) $client['usuario'],
                'plano' => $client['plano'],
            ],
        ],
    ]);
} catch (Throwable) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable) {}
    }
    samsungJsonError(500, 'INTERNAL_ERROR', 'Não foi possível concluir o login');
}
