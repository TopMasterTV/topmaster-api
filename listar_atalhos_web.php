<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/administrative_token_auth.php';
require_once __DIR__ . '/atalho_web_crypto.php';

function responderListaAtalhosWeb(int $statusHttp, array $resposta): never
{
    http_response_code($statusHttp);
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function erroListaAtalhosWeb(int $statusHttp, string $codigo): never
{
    responderListaAtalhosWeb($statusHttp, ['success' => false, 'error' => $codigo]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    erroListaAtalhosWeb(405, 'METHOD_NOT_ALLOWED');
}

$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($contentType !== 'application/json') {
    erroListaAtalhosWeb(400, 'INVALID_REQUEST');
}

$dados = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($dados)) {
    erroListaAtalhosWeb(400, 'INVALID_REQUEST');
}
$tipo = $dados['tipo'] ?? null;
if ($tipo !== null && !in_array($tipo, ['painel', 'aplicativo'], true)) {
    erroListaAtalhosWeb(400, 'INVALID_TYPE');
}

try {
    $databaseUrl = getenv('DATABASE_URL');
    if (!is_string($databaseUrl) || $databaseUrl === '') {
        throw new RuntimeException('Configuracao do banco ausente');
    }
    $db = parse_url($databaseUrl);
    if (!is_array($db) || empty($db['host']) || empty($db['path']) || !isset($db['user'], $db['pass'])) {
        throw new RuntimeException('Configuracao do banco invalida');
    }
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require', $db['host'], $db['port'] ?? 5432, ltrim($db['path'], '/')),
        rawurldecode($db['user']),
        rawurldecode($db['pass']),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $sessao = autenticarTokenAdministrativo($pdo);
    if (!in_array($sessao['actor_type'], ['master', 'revendedor'], true)) {
        erroListaAtalhosWeb(403, 'ACCESS_DENIED');
    }

    $sql = <<<'SQL'
        SELECT id, nome, url, tipo, usuario, senha_criptografada
        FROM atalhos_web
        WHERE owner_type = :owner_type
          AND owner_id = :owner_id
        SQL;
    $parametros = [
        ':owner_type' => $sessao['actor_type'],
        ':owner_id' => $sessao['actor_id'],
    ];
    if ($tipo !== null) {
        $sql .= ' AND tipo = :tipo';
        $parametros[':tipo'] = $tipo;
    }
    $sql .= ' ORDER BY updated_at DESC, created_at DESC, id ASC';

    $consulta = $pdo->prepare($sql);
    $consulta->execute($parametros);
    $atalhos = [];
    foreach ($consulta->fetchAll() as $registro) {
        $atalhos[] = [
            'id' => (string) $registro['id'],
            'nome' => (string) $registro['nome'],
            'url' => (string) $registro['url'],
            'tipo' => (string) $registro['tipo'],
            'usuario' => (string) $registro['usuario'],
            'senha' => descriptografarSenhaAtalhoWeb(
                $registro['senha_criptografada'],
                $sessao['actor_type'],
                $sessao['actor_id'],
                (string) $registro['id']
            ),
        ];
    }

    responderListaAtalhosWeb(200, ['success' => true, 'atalhos' => $atalhos]);
} catch (AdministrativeAuthException $e) {
    erroListaAtalhosWeb($e->getStatusHttp(), $e->getCodigoPublico());
} catch (Throwable $e) {
    erroListaAtalhosWeb(500, 'INTERNAL_ERROR');
}
