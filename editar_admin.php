<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/administrative_token_auth.php';

function responderEdicaoAdmin(int $statusHttp, array $conteudo): never
{
    http_response_code($statusHttp);
    echo json_encode($conteudo, JSON_UNESCAPED_UNICODE);
    exit;
}

function senhaAdminDentroDoLimite(string $senha): bool
{
    $tamanho = function_exists('mb_strlen')
        ? mb_strlen($senha, 'UTF-8')
        : strlen($senha);

    return $tamanho <= 50;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderEdicaoAdmin(405, [
        'success' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'message' => 'Método não permitido',
    ]);
}

try {
    $ator = autenticarTokenAdministrativo($pdo);
} catch (AdministrativeAuthException $e) {
    responderEdicaoAdmin($e->getStatusHttp(), [
        'success' => false,
        'code' => $e->getCodigoPublico(),
        'message' => $e->getMensagemPublica(),
    ]);
}

if (($ator['actor_type'] ?? null) !== 'master') {
    responderEdicaoAdmin(403, [
        'success' => false,
        'code' => 'FORBIDDEN',
        'message' => 'Acesso não autorizado',
    ]);
}

$id = filter_var(
    $_POST['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$nome = trim((string) ($_POST['nome'] ?? ''));
$usuario = trim((string) ($_POST['usuario'] ?? ''));
$senha = (string) ($_POST['senha'] ?? '');

if ($id === false || $nome === '' || $usuario === '') {
    responderEdicaoAdmin(400, [
        'success' => false,
        'code' => 'INVALID_ADMIN',
        'message' => 'Dados obrigatórios não preenchidos',
    ]);
}

if ($senha !== '' && !senhaAdminDentroDoLimite($senha)) {
    responderEdicaoAdmin(400, [
        'success' => false,
        'code' => 'PASSWORD_TOO_LONG',
        'message' => 'A senha deve ter no máximo 50 caracteres',
    ]);
}

try {
    if ($senha !== '') {
        $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
        if ($senhaHash === false) {
            throw new RuntimeException('Falha ao gerar hash');
        }

        $stmt = $pdo->prepare(<<<'SQL'
            UPDATE admins
            SET nome = :nome,
                usuario = :usuario,
                senha = :senha,
                senha_visivel = :senha_visivel
            WHERE id = :id
            SQL);
        $stmt->execute([
            ':nome' => $nome,
            ':usuario' => $usuario,
            ':senha' => $senhaHash,
            ':senha_visivel' => $senha,
            ':id' => $id,
        ]);
    } else {
        $stmt = $pdo->prepare(<<<'SQL'
            UPDATE admins
            SET nome = :nome,
                usuario = :usuario
            WHERE id = :id
            SQL);
        $stmt->execute([
            ':nome' => $nome,
            ':usuario' => $usuario,
            ':id' => $id,
        ]);
    }
} catch (Throwable $e) {
    responderEdicaoAdmin(500, [
        'success' => false,
        'code' => 'INTERNAL_ERROR',
        'message' => 'Erro ao atualizar administrador',
    ]);
}

if ($stmt->rowCount() === 0) {
    responderEdicaoAdmin(404, [
        'success' => false,
        'code' => 'ADMIN_NOT_FOUND',
        'message' => 'Administrador não encontrado',
    ]);
}

responderEdicaoAdmin(200, [
    'success' => true,
    'message' => 'Dados atualizados com sucesso',
]);
