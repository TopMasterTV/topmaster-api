<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/roku_token_auth.php';
require_once __DIR__ . '/roku_sistema_context.php';
require_once __DIR__ . '/roku_xtream_categories.php';
require_once __DIR__ . '/roku_xtream_observability.php';

function responderCategoriasRoku(int $statusHttp, array $conteudo): never
{
    http_response_code($statusHttp);

    try {
        $json = json_encode(
            $conteudo,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    } catch (Throwable) {
        http_response_code(500);
        echo '{"sucesso":false,"erro":{"codigo":"INTERNAL_ERROR","mensagem":"Erro interno"}}';
        exit;
    }

    echo $json;
    exit;
}

function responderErroCategoriasRoku(
    int $statusHttp,
    string $codigo,
    string $mensagem
): never {
    responderCategoriasRoku($statusHttp, [
        'sucesso' => false,
        'erro' => [
            'codigo' => $codigo,
            'mensagem' => $mensagem,
        ],
    ]);
}

function desfazerTransacaoCategoriasRoku(?PDO $pdo): void
{
    if (!$pdo instanceof PDO || !$pdo->inTransaction()) {
        return;
    }

    try {
        $pdo->rollBack();
    } catch (Throwable) {
        // Mantém somente a resposta pública prevista quando o rollback falhar.
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    responderErroCategoriasRoku(
        405,
        'METHOD_NOT_ALLOWED',
        'Método não permitido'
    );
}

$parametrosPermitidos = ['sistema_id', 'tipo'];

foreach (array_keys($_GET) as $chave) {
    if (!is_string($chave) || !in_array($chave, $parametrosPermitidos, true)) {
        responderErroCategoriasRoku(400, 'INVALID_REQUEST', 'Requisição inválida');
    }
}

if (
    count($_GET) !== 2
    || !array_key_exists('sistema_id', $_GET)
    || !array_key_exists('tipo', $_GET)
    || !is_string($_GET['sistema_id'])
    || !is_string($_GET['tipo'])
    || preg_match('/^[1-9][0-9]{0,18}$/D', $_GET['sistema_id']) !== 1
    || !in_array($_GET['tipo'], ['live', 'vod', 'series'], true)
) {
    responderErroCategoriasRoku(400, 'INVALID_REQUEST', 'Requisição inválida');
}

$sistemaId = (int) $_GET['sistema_id'];
$tipo = $_GET['tipo'];

if ($sistemaId <= 0 || (string) $sistemaId !== $_GET['sistema_id']) {
    responderErroCategoriasRoku(400, 'INVALID_REQUEST', 'Requisição inválida');
}

$pdo = null;

try {
    $databaseUrl = getenv('DATABASE_URL');

    if (!is_string($databaseUrl) || $databaseUrl === '') {
        throw new RuntimeException('Configuração do banco ausente');
    }

    $db = parse_url($databaseUrl);

    if (
        !is_array($db)
        || empty($db['host'])
        || empty($db['path'])
        || !isset($db['user'], $db['pass'])
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
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    if (!$pdo->beginTransaction()) {
        throw new RuntimeException('Não foi possível iniciar a transação');
    }

    $autenticacao = autenticarTokenRoku($pdo);
    $clienteIdDoToken = $autenticacao['cliente_id'];
    $contexto = obterContextoSistemaRoku(
        $pdo,
        $clienteIdDoToken,
        $sistemaId
    );

    if (!$pdo->commit()) {
        throw new RuntimeException('Não foi possível confirmar a transação');
    }

    if (($contexto['tipo_acesso'] ?? null) !== 'xtream') {
        responderErroCategoriasRoku(
            409,
            'SYSTEM_ACCESS_UNAVAILABLE',
            'Sistema indisponível para esta operação'
        );
    }

    if (
        !isset($contexto['fornecedor_url'], $contexto['usuario'], $contexto['senha'])
        || !is_string($contexto['fornecedor_url'])
        || trim($contexto['fornecedor_url']) === ''
        || !is_string($contexto['usuario'])
        || trim($contexto['usuario']) === ''
        || !is_string($contexto['senha'])
        || $contexto['senha'] === ''
    ) {
        throw new UnexpectedValueException('Contexto Xtream inválido');
    }

    $requestIdXtream = gerarRequestIdObservabilidadeXtreamRoku();
    $inicioXtreamNanos = hrtime(true);
    $categorias = obterCategoriasXtreamRoku(
        $contexto['fornecedor_url'],
        $contexto['usuario'],
        $contexto['senha'],
        $tipo
    );

    responderCategoriasRoku(200, [
        'sucesso' => true,
        'categorias' => $categorias,
    ]);
} catch (RokuAuthException $e) {
    desfazerTransacaoCategoriasRoku($pdo);
    responderErroCategoriasRoku(
        $e->getStatusHttp(),
        $e->getCodigoPublico(),
        $e->getMensagemPublica()
    );
} catch (RokuSistemaException $e) {
    desfazerTransacaoCategoriasRoku($pdo);
    responderErroCategoriasRoku(
        $e->getStatusHttp(),
        $e->getCodigoPublico(),
        $e->getMensagemPublica()
    );
} catch (RokuXtreamException $e) {
    desfazerTransacaoCategoriasRoku($pdo);
    $duracaoXtreamMs = calcularDuracaoMsObservabilidadeXtreamRoku($inicioXtreamNanos);
    emitirLinhaObservabilidadeXtreamRoku(
        $requestIdXtream,
        $e->getStatusHttp(),
        $e->getCodigoPublico(),
        $e->getCategoriaInterna(),
        $duracaoXtreamMs
    );
    responderErroCategoriasRoku(
        $e->getStatusHttp(),
        $e->getCodigoPublico(),
        $e->getMensagemPublica()
    );
} catch (PDOException $e) {
    desfazerTransacaoCategoriasRoku($pdo);
    responderErroCategoriasRoku(500, 'INTERNAL_ERROR', 'Erro interno');
} catch (InvalidArgumentException $e) {
    desfazerTransacaoCategoriasRoku($pdo);
    responderErroCategoriasRoku(500, 'INTERNAL_ERROR', 'Erro interno');
} catch (JsonException $e) {
    desfazerTransacaoCategoriasRoku($pdo);
    responderErroCategoriasRoku(500, 'INTERNAL_ERROR', 'Erro interno');
} catch (Throwable $e) {
    desfazerTransacaoCategoriasRoku($pdo);
    responderErroCategoriasRoku(500, 'INTERNAL_ERROR', 'Erro interno');
}
