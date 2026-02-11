<?php
header('Content-Type: application/json');

/* =========================
   DEBUG CONTROLADO (TEMPORÁRIO)
   ========================= */
ini_set('display_errors', 1);
error_reporting(E_ALL);

set_exception_handler(function ($e) {
    echo json_encode([
        'success' => false,
        'debug'   => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    exit;
});

set_error_handler(function ($severity, $message, $file, $line) {
    echo json_encode([
        'success' => false,
        'debug'   => $message,
        'file'    => $file,
        'line'    => $line,
    ]);
    exit;
});

/* =========================
   CONEXÃO
   ========================= */
require 'db.php';

/* =========================
   IDS
   ========================= */
$cliente_id = $_POST['cliente_id'] ?? $_POST['id'] ?? null;
$admin_id   = $_POST['admin_id'] ?? null;

$cliente_id = is_numeric($cliente_id) ? (int)$cliente_id : null;
$admin_id   = is_numeric($admin_id) ? (int)$admin_id : null;

if ($cliente_id === null || $admin_id === null) {
    echo json_encode([
        'success' => false,
        'message' => 'cliente_id e admin_id são obrigatórios'
    ]);
    exit;
}

/* =========================
   CAMPOS
   ========================= */
$nome     = trim($_POST['nome'] ?? '');
$usuario  = trim($_POST['usuario'] ?? '');
$senha    = trim($_POST['senha'] ?? '');
$whatsapp = trim($_POST['whatsapp'] ?? '');
$m3u_url  = trim($_POST['m3u_url'] ?? '');

/* =========================
   SEGURANÇA
   ========================= */
$check = $pdo->prepare("
    SELECT id FROM clientes
    WHERE id = :cliente_id
      AND admin_id = :admin_id
");
$check->execute([
    ':cliente_id' => $cliente_id,
    ':admin_id'   => $admin_id
]);

if ($check->rowCount() === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Cliente não encontrado ou acesso negado'
    ]);
    exit;
}

/* =========================
   UPDATE PRINCIPAL
   ========================= */
$sql = "
UPDATE clientes SET
    nome = :nome,
    usuario = :usuario,
    whatsapp = :whatsapp,
    m3u_url = :m3u_url
WHERE id = :cliente_id
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':nome'       => $nome,
    ':usuario'    => $usuario,
    ':whatsapp'   => $whatsapp,
    ':m3u_url'    => $m3u_url,
    ':cliente_id' => $cliente_id
]);

/* =========================
   UPDATE DE SENHA (OPCIONAL)
   ========================= */
if ($senha !== '') {
    $stmtSenha = $pdo->prepare("
        UPDATE clientes SET senha = :senha
        WHERE id = :cliente_id
    ");
    $stmtSenha->execute([
        ':senha'      => password_hash($senha, PASSWORD_DEFAULT),
        ':cliente_id' => $cliente_id
    ]);
}

echo json_encode([
    'success' => true,
    'message' => 'Cliente atualizado com sucesso'
]);
exit;
