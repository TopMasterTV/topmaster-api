<?php

declare(strict_types=1);

// Hash artificial fixo para reduzir enumeração temporal de usuários.
const ROKU_DUMMY_PASSWORD_HASH = '$2y$10$sktftvotwo75oQ8nmJXfUOXEv/ocR0wA/F.FuVldLCEhJuh1Cp85u';

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

function responderRateLimited(int $retryAfter): never
{
    $retryAfter = max(1, $retryAfter);
    header('Retry-After: ' . $retryAfter);

    responderJson(429, [
        'success' => false,
        'error' => [
            'code' => 'RATE_LIMITED',
            'message' => 'Muitas tentativas. Aguarde e tente novamente.',
            'retry_after' => $retryAfter,
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

function obterSslmodeBancoRoku(string $host): string
{
    $sslmodeConfigurado = getenv('ROKU_DATABASE_SSLMODE');

    if (
        $sslmodeConfigurado === false
        || trim($sslmodeConfigurado) === ''
    ) {
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

    throw new RuntimeException('Configuração de banco inválida');
}

function identificarEnderecoIp(): string
{
    $encaminhado = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;

    if (is_string($encaminhado)) {
        $primeiroIp = trim(explode(',', $encaminhado, 2)[0]);

        if (filter_var($primeiroIp, FILTER_VALIDATE_IP) !== false) {
            return $primeiroIp;
        }
    }

    $enderecoRemoto = $_SERVER['REMOTE_ADDR'] ?? null;

    if (is_string($enderecoRemoto)) {
        $enderecoRemoto = trim($enderecoRemoto);

        if (filter_var($enderecoRemoto, FILTER_VALIDATE_IP) !== false) {
            return $enderecoRemoto;
        }
    }

    throw new RuntimeException('Endereço IP indisponível');
}

function garantirEBloquearRateLimits(PDO $pdo, string $hashUsuario, string $hashIp): void
{
    $insercao = $pdo->prepare(<<<'SQL'
        WITH momento AS (
            SELECT clock_timestamp() AS agora
        )
        INSERT INTO login_rate_limits (
            app_tipo,
            escopo,
            chave_hash,
            tentativas_falhas,
            janela_inicio,
            ultima_tentativa_em,
            criado_em,
            atualizado_em
        )
        SELECT 'roku', 'ip', :hash_ip, 0, agora, agora, agora, agora
        FROM momento
        UNION ALL
        SELECT 'roku', 'usuario', :hash_usuario, 0, agora, agora, agora, agora
        FROM momento
        ON CONFLICT (app_tipo, escopo, chave_hash) DO NOTHING
        SQL);
    $insercao->execute([
        ':hash_ip' => $hashIp,
        ':hash_usuario' => $hashUsuario,
    ]);

    $bloqueio = $pdo->prepare(<<<'SQL'
        SELECT escopo
        FROM login_rate_limits
        WHERE app_tipo = 'roku'
          AND (
              (escopo = 'ip' AND chave_hash = :hash_ip)
              OR (escopo = 'usuario' AND chave_hash = :hash_usuario)
          )
        ORDER BY escopo
        FOR UPDATE
        SQL);
    $bloqueio->execute([
        ':hash_ip' => $hashIp,
        ':hash_usuario' => $hashUsuario,
    ]);

    if (count($bloqueio->fetchAll()) !== 2) {
        throw new RuntimeException('Falha ao preparar controle de tentativas');
    }
}

function normalizarJanelasRateLimit(PDO $pdo, string $hashUsuario, string $hashIp): void
{
    $normalizacao = $pdo->prepare(<<<'SQL'
        WITH momento AS (
            SELECT clock_timestamp() AS agora
        )
        UPDATE login_rate_limits AS limite
        SET
            tentativas_falhas = 0,
            janela_inicio = momento.agora,
            bloqueado_ate = NULL,
            atualizado_em = momento.agora
        FROM momento
        WHERE limite.app_tipo = 'roku'
          AND (
              (limite.escopo = 'ip' AND limite.chave_hash = :hash_ip)
              OR (limite.escopo = 'usuario' AND limite.chave_hash = :hash_usuario)
          )
          AND (
              (
                  limite.bloqueado_ate IS NOT NULL
                  AND limite.bloqueado_ate <= momento.agora
              )
              OR (
                  limite.bloqueado_ate IS NULL
                  AND limite.janela_inicio < momento.agora - INTERVAL '15 minutes'
              )
          )
        SQL);
    $normalizacao->execute([
        ':hash_ip' => $hashIp,
        ':hash_usuario' => $hashUsuario,
    ]);
}

function carregarRateLimits(PDO $pdo, string $hashUsuario, string $hashIp): array
{
    $consulta = $pdo->prepare(<<<'SQL'
        WITH momento AS (
            SELECT clock_timestamp() AS agora
        )
        SELECT
            limite.escopo,
            limite.tentativas_falhas,
            CASE
                WHEN limite.bloqueado_ate > momento.agora
                THEN GREATEST(
                    1,
                    CEIL(
                        EXTRACT(EPOCH FROM (limite.bloqueado_ate - momento.agora))
                    )::INTEGER
                )
                ELSE 0
            END AS retry_after
        FROM login_rate_limits AS limite
        CROSS JOIN momento
        WHERE limite.app_tipo = 'roku'
          AND (
              (limite.escopo = 'ip' AND limite.chave_hash = :hash_ip)
              OR (limite.escopo = 'usuario' AND limite.chave_hash = :hash_usuario)
          )
        ORDER BY limite.escopo
        SQL);
    $consulta->execute([
        ':hash_ip' => $hashIp,
        ':hash_usuario' => $hashUsuario,
    ]);

    $registros = $consulta->fetchAll();

    if (count($registros) !== 2) {
        throw new RuntimeException('Controle de tentativas incompleto');
    }

    return $registros;
}

function maiorRetryAfter(array $registros): int
{
    $maior = 0;

    foreach ($registros as $registro) {
        $maior = max($maior, (int) ($registro['retry_after'] ?? 0));
    }

    return $maior;
}

function registrarFalhaRateLimit(PDO $pdo, string $hashUsuario, string $hashIp): int
{
    normalizarJanelasRateLimit($pdo, $hashUsuario, $hashIp);
    $registros = carregarRateLimits($pdo, $hashUsuario, $hashIp);

    $atualizacao = $pdo->prepare(<<<'SQL'
        WITH momento AS (
            SELECT clock_timestamp() AS agora
        )
        UPDATE login_rate_limits AS limite
        SET
            tentativas_falhas = :tentativas_falhas,
            bloqueado_ate = CASE
                WHEN CAST(:bloquear AS INTEGER) = 1
                THEN momento.agora + CASE
                    WHEN limite.escopo = 'usuario' THEN INTERVAL '15 minutes'
                    ELSE INTERVAL '30 minutes'
                END
                ELSE NULL
            END,
            ultima_tentativa_em = momento.agora,
            atualizado_em = momento.agora
        FROM momento
        WHERE limite.app_tipo = 'roku'
          AND limite.escopo = :escopo
          AND limite.chave_hash = :chave_hash
        SQL);

    foreach ($registros as $registro) {
        $escopo = (string) $registro['escopo'];
        $tentativas = (int) $registro['tentativas_falhas'] + 1;
        $limite = $escopo === 'usuario' ? 5 : 20;
        $hash = $escopo === 'usuario' ? $hashUsuario : $hashIp;

        $atualizacao->execute([
            ':tentativas_falhas' => $tentativas,
            ':bloquear' => $tentativas >= $limite ? 1 : 0,
            ':escopo' => $escopo,
            ':chave_hash' => $hash,
        ]);
    }

    return maiorRetryAfter(carregarRateLimits($pdo, $hashUsuario, $hashIp));
}

function limparRateLimits(PDO $pdo, string $hashUsuario, string $hashIp): void
{
    $limpeza = $pdo->prepare(<<<'SQL'
        WITH momento AS (
            SELECT clock_timestamp() AS agora
        )
        UPDATE login_rate_limits AS limite
        SET
            tentativas_falhas = 0,
            janela_inicio = momento.agora,
            bloqueado_ate = NULL,
            ultima_tentativa_em = momento.agora,
            atualizado_em = momento.agora
        FROM momento
        WHERE limite.app_tipo = 'roku'
          AND (
              (limite.escopo = 'ip' AND limite.chave_hash = :hash_ip)
              OR (limite.escopo = 'usuario' AND limite.chave_hash = :hash_usuario)
          )
        SQL);
    $limpeza->execute([
        ':hash_ip' => $hashIp,
        ':hash_usuario' => $hashUsuario,
    ]);
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

$pdo = null;

try {
    $hmacKey = getenv('LOGIN_RATE_LIMIT_HMAC_KEY');

    if (!is_string($hmacKey) || strlen($hmacKey) < 32) {
        throw new RuntimeException('Configuração de segurança ausente');
    }

    $enderecoIp = identificarEnderecoIp();
    $hashUsuarioRateLimit = hash_hmac('sha256', $usuario, $hmacKey);
    $hashIpRateLimit = hash_hmac('sha256', $enderecoIp, $hmacKey);

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
    $sslmode = obterSslmodeBancoRoku($host);

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

    garantirEBloquearRateLimits($pdo, $hashUsuarioRateLimit, $hashIpRateLimit);
    normalizarJanelasRateLimit($pdo, $hashUsuarioRateLimit, $hashIpRateLimit);
    $retryAfter = maiorRetryAfter(
        carregarRateLimits($pdo, $hashUsuarioRateLimit, $hashIpRateLimit)
    );

    if ($retryAfter > 0) {
        $pdo->commit();
        responderRateLimited($retryAfter);
    }

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

    $senhaValida = false;
    $atualizarHash = false;

    if ($cliente) {
        $senhaArmazenada = (string) $cliente['senha'];
        $senhaValida = password_verify($senha, $senhaArmazenada);
        $atualizarHash = $senhaValida && password_needs_rehash($senhaArmazenada, PASSWORD_DEFAULT);

        // Fallback temporário para senhas legadas em texto simples; remover após a migração completa.
        if (!$senhaValida && (password_get_info($senhaArmazenada)['algoName'] ?? 'unknown') === 'unknown') {
            $senhaValida = hash_equals($senhaArmazenada, $senha);
            $atualizarHash = $senhaValida;
        }
    } else {
        password_verify($senha, ROKU_DUMMY_PASSWORD_HASH);
    }

    if (!$senhaValida) {
        $retryAfter = registrarFalhaRateLimit(
            $pdo,
            $hashUsuarioRateLimit,
            $hashIpRateLimit
        );
        $pdo->commit();

        if ($retryAfter > 0) {
            responderRateLimited($retryAfter);
        }

        responderErro(401, 'INVALID_CREDENTIALS', 'Usuário ou senha inválidos');
    }

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

    limparRateLimits($pdo, $hashUsuarioRateLimit, $hashIpRateLimit);

    if (!interpretarBooleanoPostgres($cliente['ativo'] ?? null)) {
        $pdo->commit();
        responderErro(403, 'CLIENT_INACTIVE', 'Acesso indisponível. Entre em contato com o suporte.');
    }

    $tokenOriginal = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $tokenOriginal);
    $deviceIdHash = $deviceId !== null ? hash('sha256', $deviceId) : null;

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
