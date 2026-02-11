<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(0);

require 'db.php';

/* =========================
   RECEBE E NORMALIZA IDS
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
   CAMPOS EDITÁVEIS
   ========================= */

$nome     = $_POST['nome'] ?? '';
$usuario  = $_POST['usuario'] ?? '';
$senha    = $_POST['senha'] ?? '';
$whatsapp = $_POST['whatsapp'] ?? '';
$m3u_url  = $_POST['m3u_url'] ?? '';

/* =========================
   VERIFICA PROPRIEDADE
   ========================= */

$stmt = $pdo->prepare("
    SELECT id FROM clientes
    WHERE id = :cliente_id
      AND admin_id = :admin_id
");
$stmt->execute([
    ':cliente_id' => $cliente_id,
    ':admin_id'   => $admin_id
]);

if ($stmt->rowCount() === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Cliente não encontrado ou acesso negado'
    ]);
    exit;
}

/* =========================
   ATUALIZA DADOS
   ========================= */

$update = $pdo->prepare("
    UPDATE clientes SET
        nome = :nome,
        usuario = :usuario,
        senha = :senha,
        whatsapp = :whatsapp,
        m3u_url = :m3u_url
    WHERE id = :cliente_id
");

$update->execute([
    ':nome'       => $nome,
    ':usuario'    => $usuario,
    ':senha'      => $senha,
    ':whatsapp'   => $whatsapp,
    ':m3u_url'    => $m3u_url,
    ':cliente_id' => $cliente_id
]);

echo json_encode([
    'success' => true,
    'message' => 'Cliente atualizado com sucesso'
]);
exit;
