<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/administrative_token_auth.php';
require_once __DIR__ . '/atalho_web_crypto.php';

function responderSalvamentoAtalhoWeb(int $statusHttp, array $resposta): never
{
    http_response_code($statusHttp);
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function erroSalvamentoAtalhoWeb(int $statusHttp, string $codigo): never
{
    responderSalvamentoAtalhoWeb($statusHttp, ['success' => false, 'error' => $codigo]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    erroSalvamentoAtalhoWeb(405, 'METHOD_NOT_ALLOWED');
}

$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($contentType !== 'application/json') {
    erroSalvamentoAtalhoWeb(400, 'INVALID_REQUEST');
}

$dados = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($dados)) {
    erroSalvamentoAtalhoWeb(400, 'INVALID_REQUEST');
}

$id = $dados['id'] ?? null;
$nome = $dados['nome'] ?? null;
$url = $dados['url'] ?? null;
$tipo = $dados['tipo'] ?? null;
$usuario = $dados['usuario'] ?? '';
$senha = $dados['senha'] ?? '';

if (!is_string($id) || trim($id) === '' || strlen($id) > 128) {
    erroSalvamentoAtalhoWeb(400, 'INVALID_ID');
}
if (!is_string($nome) || trim($nome) === '' || strlen($nome) > 200) {
    erroSalvamentoAtalhoWeb(400, 'INVALID_NAME');
}
if (!is_string($url) || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
    erroSalvamentoAtalhoWeb(400, 'INVALID_URL');
}
$urlPartes = parse_url($url);
if (
    !is_array($urlPartes)
    || !isset($urlPartes['scheme'], $urlPartes['host'])
    || !in_array(strtolower((string) $urlPartes['scheme']), ['http', 'https'], true)
    || (string) $urlPartes['host'] === ''
    || isset($urlPartes['user'])
    || isset($urlPartes['pass'])
) {
    erroSalvamentoAtalhoWeb(400, 'INVALID_URL');
}
if (!in_array($tipo, ['painel', 'aplicativo'], true)) {
    erroSalvamentoAtalhoWeb(400, 'INVALID_TYPE');
}
if (!is_string($usuario) || strlen($usuario) > 255) {
    erroSalvamentoAtalhoWeb(400, 'INVALID_USER');
}
if (!is_string($senha) || strlen($senha) > 4096) {
    erroSalvamentoAtalhoWeb(400, 'INVALID_PASSWORD');
}

$id = trim($id);
$nome = trim($nome);
$url = trim($url);
$usuario = trim($usuario);

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
        erroSalvamentoAtalhoWeb(403, 'ACCESS_DENIED');
    }

    $senhaCriptografada = criptografarSenhaAtalhoWeb(
        $senha,
        $sessao['actor_type'],
        $sessao['actor_id'],
        $id
    );

    $salvamento = $pdo->prepare(<<<'SQL'
        INSERT INTO atalhos_web (
            id, owner_type, owner_id, tipo, nome, url, usuario,
            senha_criptografada, created_at, updated_at
        ) VALUES (
            :id, :owner_type, :owner_id, :tipo, :nome, :url, :usuario,
            :senha_criptografada, clock_timestamp(), clock_timestamp()
        )
        ON CONFLICT (owner_type, owner_id, id)
        DO UPDATE SET
            tipo = EXCLUDED.tipo,
            nome = EXCLUDED.nome,
            url = EXCLUDED.url,
            usuario = EXCLUDED.usuario,
            senha_criptografada = EXCLUDED.senha_criptografada,
            updated_at = clock_timestamp()
        SQL);
    $salvamento->execute([
        ':id' => $id,
        ':owner_type' => $sessao['actor_type'],
        ':owner_id' => $sessao['actor_id'],
        ':tipo' => $tipo,
        ':nome' => $nome,
        ':url' => $url,
        ':usuario' => $usuario,
        ':senha_criptografada' => $senhaCriptografada,
    ]);

    responderSalvamentoAtalhoWeb(200, ['success' => true]);
} catch (AdministrativeAuthException $e) {
    erroSalvamentoAtalhoWeb($e->getStatusHttp(), $e->getCodigoPublico());
} catch (Throwable $e) {
    erroSalvamentoAtalhoWeb(500, 'INTERNAL_ERROR');
}
