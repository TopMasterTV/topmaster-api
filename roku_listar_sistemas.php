<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/roku_token_auth.php';

function responderJsonRokuSistemas(int $statusHttp, array $conteudo): never
{
    http_response_code($statusHttp);
    echo json_encode($conteudo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function responderErroRokuSistemas(int $statusHttp, string $codigo, string $mensagem): never
{
    responderJsonRokuSistemas($statusHttp, [
        'success' => false,
        'error' => [
            'code' => $codigo,
            'message' => $mensagem,
        ],
    ]);
}

function obterSslmodeListagemSistemasRoku(string $host): string
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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    responderErroRokuSistemas(405, 'METHOD_NOT_ALLOWED', 'Método não permitido');
}

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
    $sslmode = obterSslmodeListagemSistemasRoku($host);

    $pdo = new PDO(
        "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode}",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    if (!$pdo->beginTransaction()) {
        throw new RuntimeException('Não foi possível iniciar a transação');
    }

    $autenticacao = autenticarTokenRoku($pdo);

    $consultaSistemas = $pdo->prepare(<<<'SQL'
        SELECT
            s.id,
            COALESCE(
                NULLIF(TRIM(m.nome), ''),
                NULLIF(TRIM(s.nome_sistema), ''),
                'Sistema'
            ) AS nome,
            COALESCE(s.status, 'Unknown') AS status,
            s.vencimento
        FROM sistemas AS s
        LEFT JOIN modelos_sistemas AS m
            ON m.id = s.modelo_id
        WHERE s.cliente_id = :cliente_id
        ORDER BY s.id DESC
        SQL);
    $consultaSistemas->execute([
        ':cliente_id' => $autenticacao['cliente_id'],
    ]);

    $sistemas = [];

    foreach ($consultaSistemas->fetchAll(PDO::FETCH_ASSOC) as $sistema) {
        $sistemas[] = [
            'id' => (int) $sistema['id'],
            'nome' => (string) $sistema['nome'],
            'status' => (string) $sistema['status'],
            'vencimento' => $sistema['vencimento'] !== null
                ? (string) $sistema['vencimento']
                : null,
        ];
    }

    $resposta = [
        'success' => true,
        'data' => [
            'cliente' => [
                'id' => $autenticacao['cliente_id'],
                'nome' => $autenticacao['nome'],
                'usuario' => $autenticacao['usuario'],
                'plano' => $autenticacao['plano'],
            ],
            'sistemas' => $sistemas,
        ],
    ];

    if (!$pdo->commit()) {
        throw new RuntimeException('Não foi possível confirmar a transação');
    }

    responderJsonRokuSistemas(200, $resposta);
} catch (RokuAuthException $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable $rollbackError) {
            // Preserva a resposta pública de autenticação mesmo se o rollback falhar.
        }
    }

    responderErroRokuSistemas(
        $e->getStatusHttp(),
        $e->getCodigoPublico(),
        $e->getMensagemPublica()
    );
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable $rollbackError) {
            // Mantém a resposta interna genérica mesmo se o rollback falhar.
        }
    }

    responderErroRokuSistemas(
        500,
        'INTERNAL_ERROR',
        'Não foi possível carregar os sistemas'
    );
}
