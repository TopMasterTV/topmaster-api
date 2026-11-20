<?php

declare(strict_types=1);

require_once __DIR__ . '/administrative_token_auth.php';

final class ProvedorBlocosException extends RuntimeException
{
    public function __construct(
        public readonly int $statusHttp,
        public readonly string $codigoPublico
    ) {
        parent::__construct($codigoPublico);
    }
}

function responderProvedorBlocos(int $statusHttp, array $corpo): never
{
    http_response_code($statusHttp);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function prepararRequisicaoProvedorBlocos(): array
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new ProvedorBlocosException(405, 'METHOD_NOT_ALLOWED');
    }

    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        throw new ProvedorBlocosException(400, 'INVALID_REQUEST');
    }

    $corpo = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($corpo)) {
        throw new ProvedorBlocosException(400, 'INVALID_REQUEST');
    }
    return $corpo;
}

function conectarProvedorBlocos(): PDO
{
    $databaseUrl = getenv('DATABASE_URL');
    if (!is_string($databaseUrl) || $databaseUrl === '') {
        throw new RuntimeException('Configuracao do banco ausente');
    }
    $db = parse_url($databaseUrl);
    if (!is_array($db) || empty($db['host']) || empty($db['path']) || !isset($db['user'], $db['pass'])) {
        throw new RuntimeException('Configuracao do banco invalida');
    }
    return new PDO(
        sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
            $db['host'],
            $db['port'] ?? 5432,
            ltrim($db['path'], '/')
        ),
        rawurldecode((string) $db['user']),
        rawurldecode((string) $db['pass']),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

function exigirMasterProvedorBlocos(PDO $pdo): array
{
    $sessao = autenticarTokenAdministrativo($pdo);
    if (($sessao['actor_type'] ?? null) !== 'master') {
        throw new ProvedorBlocosException(403, 'ACCESS_DENIED');
    }
    return $sessao;
}

function textoProvedorBlocos(array $dados, string $campo, int $maximo, bool $obrigatorio = true): string
{
    $valor = $dados[$campo] ?? null;
    if (!is_string($valor)) {
        if (!$obrigatorio && $valor === null) {
            return '';
        }
        throw new ProvedorBlocosException(400, 'INVALID_REQUEST');
    }
    $valor = trim($valor);
    if (($obrigatorio && $valor === '') || strlen($valor) > $maximo || preg_match('/[\x00-\x1F\x7F]/', $valor)) {
        throw new ProvedorBlocosException(400, 'INVALID_REQUEST');
    }
    return $valor;
}

function idProvedorBlocos(array $dados, string $campo, bool $aceitaNulo = false): ?int
{
    $valor = $dados[$campo] ?? null;
    if ($aceitaNulo && $valor === null) {
        return null;
    }
    if (is_int($valor)) {
        $id = $valor;
    } elseif (is_string($valor) && preg_match('/\A[1-9][0-9]*\z/D', $valor)) {
        $id = (int) $valor;
    } else {
        throw new ProvedorBlocosException(400, 'INVALID_REQUEST');
    }
    if ($id <= 0) {
        throw new ProvedorBlocosException(400, 'INVALID_REQUEST');
    }
    return $id;
}

function executarEndpointProvedorBlocos(callable $acao): never
{
    try {
        $dados = prepararRequisicaoProvedorBlocos();
        $pdo = conectarProvedorBlocos();
        exigirMasterProvedorBlocos($pdo);
        [$status, $corpo] = $acao($pdo, $dados);
        responderProvedorBlocos($status, $corpo);
    } catch (AdministrativeAuthException $e) {
        responderProvedorBlocos($e->getStatusHttp(), ['success' => false, 'error' => $e->getCodigoPublico()]);
    } catch (ProvedorBlocosException $e) {
        responderProvedorBlocos($e->statusHttp, ['success' => false, 'error' => $e->codigoPublico]);
    } catch (PDOException $e) {
        $codigo = $e->getCode();
        if ($codigo === '23505') {
            responderProvedorBlocos(409, ['success' => false, 'error' => 'ALREADY_EXISTS']);
        }
        if ($codigo === '23503') {
            responderProvedorBlocos(409, ['success' => false, 'error' => 'INVALID_RELATION']);
        }
        responderProvedorBlocos(500, ['success' => false, 'error' => 'INTERNAL_ERROR']);
    } catch (Throwable $e) {
        responderProvedorBlocos(500, ['success' => false, 'error' => 'INTERNAL_ERROR']);
    }
}
