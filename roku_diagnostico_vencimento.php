<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/roku_token_auth.php';
require_once __DIR__ . '/roku_sistema_context.php';
require_once __DIR__ . '/roku_xtream_client.php';

function responderDiagnostico(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode(
        $body,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    responderDiagnostico(405, [
        'success' => false,
        'error' => 'METHOD_NOT_ALLOWED',
    ]);
}

$sistemaId = filter_input(INPUT_GET, 'sistema_id', FILTER_VALIDATE_INT);

if (!$sistemaId || $sistemaId <= 0) {
    responderDiagnostico(400, [
        'success' => false,
        'error' => 'INVALID_SYSTEM_ID',
    ]);
}

$databaseUrl = getenv('DATABASE_URL');

if (!is_string($databaseUrl) || $databaseUrl === '') {
    responderDiagnostico(500, [
        'success' => false,
        'error' => 'DATABASE_CONFIG',
    ]);
}

$db = parse_url($databaseUrl);

if (
    !is_array($db)
    || empty($db['host'])
    || empty($db['path'])
    || !isset($db['user'], $db['pass'])
) {
    responderDiagnostico(500, [
        'success' => false,
        'error' => 'DATABASE_CONFIG',
    ]);
}

$pdo = new PDO(
    'pgsql:host=' . $db['host']
        . ';port=' . ($db['port'] ?? 5432)
        . ';dbname=' . ltrim($db['path'], '/')
        . ';sslmode=require',
    rawurldecode((string) $db['user']),
    rawurldecode((string) $db['pass']),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$pdo->beginTransaction();

try {
    $auth = autenticarTokenRoku($pdo);

    $contexto = obterContextoSistemaRoku(
        $pdo,
        (int) $auth['cliente_id'],
        (int) $sistemaId
    );

    $resultado = [
        'success' => true,
        'sistema_id' => (int) $sistemaId,
        'nome' => $contexto['nome'],
        'tipo_acesso' => $contexto['tipo_acesso'],
        'credentials_present' => [
            'url' => !empty($contexto['fornecedor_url']),
            'username' => !empty($contexto['usuario']),
            'password' => !empty($contexto['senha']),
        ],
        'roku_method' => [
            'success' => false,
            'status' => null,
            'exp_date_found' => false,
            'expiration' => null,
            'error' => null,
        ],
        'simple_method' => [
            'success' => false,
            'status' => null,
            'exp_date_found' => false,
            'expiration' => null,
            'error' => null,
        ],
    ];

    if ($contexto['tipo_acesso'] !== 'xtream') {
        $pdo->rollBack();
        responderDiagnostico(200, $resultado);
    }

    /*
     * A - cliente HTTP atual do Roku
     */
    try {
        $data = requisitarJsonXtreamRoku(
            $contexto['fornecedor_url'],
            $contexto['usuario'],
            $contexto['senha']
        );

        $info = $data['user_info'] ?? null;

        if (is_array($info)) {
            $exp = $info['exp_date'] ?? null;

            $resultado['roku_method']['success'] = true;
            $resultado['roku_method']['status'] =
                isset($info['status']) ? (string) $info['status'] : null;

            if (
                (is_string($exp) || is_int($exp))
                && ctype_digit((string) $exp)
                && (int) $exp > 0
            ) {
                $resultado['roku_method']['exp_date_found'] = true;
                $resultado['roku_method']['expiration'] =
                    gmdate('Y-m-d', (int) $exp);
            }
        }
    } catch (RokuXtreamException $e) {
        $resultado['roku_method']['error'] = [
            'public_code' => $e->getCodigoPublico(),
            'internal_category' => $e->getCategoriaInterna(),
            'http_status' => $e->getStatusHttp(),
        ];
    } catch (Throwable $e) {
        $resultado['roku_method']['error'] = [
            'public_code' => 'UNEXPECTED_ERROR',
        ];
    }

    /*
     * B - mesma estrategia simples usada pelo fluxo legado/Android:
     * URL/player_api.php?username=...&password=...
     */
    try {
        $base = rtrim((string) $contexto['fornecedor_url'], '/');

        if (str_ends_with(strtolower($base), '/player_api.php')) {
            $apiUrl = $base;
        } else {
            $apiUrl = $base . '/player_api.php';
        }

        $apiUrl .= '?username='
            . urlencode((string) $contexto['usuario'])
            . '&password='
            . urlencode((string) $contexto['senha']);

        $httpContext = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'ignore_errors' => true,
                'header' =>
                    "User-Agent: Mozilla/5.0\r\n" .
                    "Accept: application/json,*/*\r\n" .
                    "Connection: close\r\n",
            ],
        ]);

        $body = @file_get_contents($apiUrl, false, $httpContext);

        $httpStatus = null;

        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $headerLine) {
                if (
                    preg_match(
                        '#^HTTP/\S+\s+(\d{3})#',
                        $headerLine,
                        $match
                    )
                ) {
                    $httpStatus = (int) $match[1];
                }
            }
        }

        if (is_string($body) && $body !== '') {
            $data = json_decode($body, true);
            $info = is_array($data)
                ? ($data['user_info'] ?? null)
                : null;

            if (is_array($info)) {
                $exp = $info['exp_date'] ?? null;

                $resultado['simple_method']['success'] = true;
                $resultado['simple_method']['status'] =
                    isset($info['status']) ? (string) $info['status'] : null;

                if (
                    (is_string($exp) || is_int($exp))
                    && ctype_digit((string) $exp)
                    && (int) $exp > 0
                ) {
                    $resultado['simple_method']['exp_date_found'] = true;
                    $resultado['simple_method']['expiration'] =
                        gmdate('Y-m-d', (int) $exp);
                }
            }
        }

        if (!$resultado['simple_method']['success']) {
            $resultado['simple_method']['error'] = [
                'http_status' => $httpStatus,
                'body_received' => is_string($body) && $body !== '',
            ];
        }
    } catch (Throwable $e) {
        $resultado['simple_method']['error'] = [
            'type' => 'UNEXPECTED_ERROR',
        ];
    }

    /*
     * Diagnostico estritamente read-only.
     */
    $pdo->rollBack();

    responderDiagnostico(200, $resultado);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    responderDiagnostico(500, [
        'success' => false,
        'error' => 'INTERNAL_ERROR',
    ]);
}