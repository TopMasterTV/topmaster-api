<?php

declare(strict_types=1);

require_once __DIR__ . '/roku_token_auth.php';
require_once __DIR__ . '/roku_sistema_context.php';

final class RokuContextoXtreamEndpointException extends RuntimeException
{
    public function __construct(
        private readonly int $statusHttp,
        private readonly string $codigoPublico,
        private readonly string $mensagemPublica
    ) {
        parent::__construct($mensagemPublica);
    }

    public function getStatusHttp(): int
    {
        return $this->statusHttp;
    }

    public function getCodigoPublico(): string
    {
        return $this->codigoPublico;
    }

    public function getMensagemPublica(): string
    {
        return $this->mensagemPublica;
    }
}

/**
 * @return list<string>
 */
function obterCabecalhosContextoXtreamRoku(): array
{
    return [
        'Content-Type: application/json; charset=utf-8',
        'Cache-Control: no-store, max-age=0',
        'Pragma: no-cache',
        'X-Content-Type-Options: nosniff',
    ];
}

function validarMetodoContextoXtreamRoku(string $metodo): void
{
    if ($metodo !== 'GET') {
        throw new RokuContextoXtreamEndpointException(
            405,
            'METHOD_NOT_ALLOWED',
            'Método não permitido'
        );
    }
}

/**
 * @param array<mixed> $parametros
 */
function extrairSistemaIdContextoXtreamRoku(array $parametros): int
{
    if (
        count($parametros) !== 1
        || !array_key_exists('sistema_id', $parametros)
        || !is_string($parametros['sistema_id'])
        || preg_match('/^[1-9][0-9]{0,18}$/D', $parametros['sistema_id']) !== 1
    ) {
        throw new RokuContextoXtreamEndpointException(
            400,
            'INVALID_REQUEST',
            'Requisição inválida'
        );
    }

    $sistemaId = (int) $parametros['sistema_id'];

    if ($sistemaId <= 0 || (string) $sistemaId !== $parametros['sistema_id']) {
        throw new RokuContextoXtreamEndpointException(
            400,
            'INVALID_REQUEST',
            'Requisição inválida'
        );
    }

    return $sistemaId;
}

/**
 * @param array<mixed> $autenticacao
 * @return array{cliente_id: int, sistema_id: int}
 */
function obterIdentificadoresContextoXtreamRoku(
    array $autenticacao,
    int $sistemaId
): array {
    $clienteId = $autenticacao['cliente_id'] ?? null;

    if (!is_int($clienteId) || $clienteId <= 0 || $sistemaId <= 0) {
        throw new UnexpectedValueException('Contexto de autorização inválido');
    }

    return [
        'cliente_id' => $clienteId,
        'sistema_id' => $sistemaId,
    ];
}

/**
 * @param array<mixed> $contexto
 * @return array{
 *     sistema_id: int,
 *     tipo_acesso: 'xtream',
 *     base_url: string,
 *     username: string,
 *     password: string
 * }
 */
function projetarContextoXtreamRoku(
    array $contexto,
    int $clienteIdAutenticado,
    int $sistemaIdSolicitado
): array {
    if (
        ($contexto['cliente_id'] ?? null) !== $clienteIdAutenticado
        || ($contexto['sistema_id'] ?? null) !== $sistemaIdSolicitado
    ) {
        throw new RokuSistemaException(
            404,
            'SYSTEM_NOT_FOUND',
            'Sistema não encontrado'
        );
    }

    if (($contexto['tipo_acesso'] ?? null) !== 'xtream') {
        throw new RokuContextoXtreamEndpointException(
            409,
            'SYSTEM_ACCESS_UNAVAILABLE',
            'Sistema indisponível para esta operação'
        );
    }

    $baseUrl = $contexto['fornecedor_url'] ?? null;
    $username = $contexto['usuario'] ?? null;
    $password = $contexto['senha'] ?? null;

    if (
        !is_string($baseUrl)
        || trim($baseUrl) === ''
        || !is_string($username)
        || trim($username) === ''
        || !is_string($password)
        || trim($password) === ''
    ) {
        throw new RokuContextoXtreamEndpointException(
            409,
            'SYSTEM_ACCESS_UNAVAILABLE',
            'Sistema indisponível para esta operação'
        );
    }

    return [
        'sistema_id' => $sistemaIdSolicitado,
        'tipo_acesso' => 'xtream',
        'base_url' => $baseUrl,
        'username' => $username,
        'password' => $password,
    ];
}

/**
 * @param array<mixed> $parametros
 * @param callable(): array<mixed> $autenticar
 * @param callable(int, int): array<mixed> $obterContexto
 * @return array{
 *     sucesso: true,
 *     data: array{
 *         sistema_id: int,
 *         tipo_acesso: 'xtream',
 *         base_url: string,
 *         username: string,
 *         password: string
 *     }
 * }
 */
function orquestrarContextoXtreamRoku(
    string $metodo,
    array $parametros,
    callable $autenticar,
    callable $obterContexto
): array {
    validarMetodoContextoXtreamRoku($metodo);
    $sistemaId = extrairSistemaIdContextoXtreamRoku($parametros);
    $autenticacao = $autenticar();
    $identificadores = obterIdentificadoresContextoXtreamRoku(
        $autenticacao,
        $sistemaId
    );
    $contexto = $obterContexto(
        $identificadores['cliente_id'],
        $identificadores['sistema_id']
    );

    return [
        'sucesso' => true,
        'data' => projetarContextoXtreamRoku(
            $contexto,
            $identificadores['cliente_id'],
            $identificadores['sistema_id']
        ),
    ];
}

function responderContextoXtreamRoku(int $statusHttp, array $conteudo): never
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

function responderErroContextoXtreamRoku(
    int $statusHttp,
    string $codigo,
    string $mensagem
): never {
    responderContextoXtreamRoku($statusHttp, [
        'sucesso' => false,
        'erro' => [
            'codigo' => $codigo,
            'mensagem' => $mensagem,
        ],
    ]);
}

function desfazerTransacaoContextoXtreamRoku(?PDO $pdo): void
{
    if (!$pdo instanceof PDO || !$pdo->inTransaction()) {
        return;
    }

    try {
        $pdo->rollBack();
    } catch (Throwable) {
        // Preserva somente a resposta pública sanitizada.
    }
}

function criarConexaoContextoXtreamRoku(): PDO
{
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

    return new PDO(
        sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
            $db['host'],
            $db['port'] ?? 5432,
            ltrim($db['path'], '/')
        ),
        rawurldecode($db['user']),
        rawurldecode($db['pass']),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function executarEndpointContextoXtreamRoku(): never
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');

    foreach (obterCabecalhosContextoXtreamRoku() as $cabecalho) {
        header($cabecalho);
    }

    $metodoRecebido = $_SERVER['REQUEST_METHOD'] ?? '';
    $metodo = is_string($metodoRecebido) ? $metodoRecebido : '';
    $pdo = null;

    try {
        $resposta = orquestrarContextoXtreamRoku(
            $metodo,
            $_GET,
            static function () use (&$pdo): array {
                $pdo = criarConexaoContextoXtreamRoku();

                if (!$pdo->beginTransaction()) {
                    throw new RuntimeException('Não foi possível iniciar a transação');
                }

                return autenticarTokenRoku($pdo);
            },
            static function (int $clienteId, int $sistemaId) use (&$pdo): array {
                if (!$pdo instanceof PDO || !$pdo->inTransaction()) {
                    throw new RuntimeException('Contexto transacional ausente');
                }

                return obterContextoSistemaRoku($pdo, $clienteId, $sistemaId);
            }
        );

        if (!$pdo instanceof PDO || !$pdo->inTransaction() || !$pdo->commit()) {
            throw new RuntimeException('Não foi possível confirmar a transação');
        }

        responderContextoXtreamRoku(200, $resposta);
    } catch (RokuAuthException $e) {
        desfazerTransacaoContextoXtreamRoku($pdo);
        responderErroContextoXtreamRoku(
            $e->getStatusHttp(),
            $e->getCodigoPublico(),
            $e->getMensagemPublica()
        );
    } catch (RokuSistemaException $e) {
        desfazerTransacaoContextoXtreamRoku($pdo);
        responderErroContextoXtreamRoku(
            $e->getStatusHttp(),
            $e->getCodigoPublico(),
            $e->getMensagemPublica()
        );
    } catch (RokuContextoXtreamEndpointException $e) {
        desfazerTransacaoContextoXtreamRoku($pdo);

        if ($e->getStatusHttp() === 405) {
            header('Allow: GET');
        }

        responderErroContextoXtreamRoku(
            $e->getStatusHttp(),
            $e->getCodigoPublico(),
            $e->getMensagemPublica()
        );
    } catch (Throwable) {
        desfazerTransacaoContextoXtreamRoku($pdo);
        responderErroContextoXtreamRoku(500, 'INTERNAL_ERROR', 'Erro interno');
    }
}

function contextoXtreamRokuExecutadoDiretamente(): bool
{
    $scriptExecutado = $_SERVER['SCRIPT_FILENAME'] ?? null;

    if (!is_string($scriptExecutado) || $scriptExecutado === '') {
        return false;
    }

    $caminhoExecutado = realpath($scriptExecutado);

    return $caminhoExecutado !== false && $caminhoExecutado === __FILE__;
}

if (contextoXtreamRokuExecutadoDiretamente()) {
    executarEndpointContextoXtreamRoku();
}
