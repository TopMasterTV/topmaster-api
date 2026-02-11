<?php
header('Content-Type: application/json');
require 'db.php'; // conexão com o PostgreSQL

/* =========================
   RECEBE E NORMALIZA IDS
   ========================= */

// Aceita cliente_id ou id (compatibilidade total)
$cliente_id = $_POST['cliente_id'] ?? $_POST['id'] ?? null;
$admin_id   = $_POST['admin_id'] ?? null;

// Normaliza
$cliente_id = is_numeric($cliente_id) ? (int)$cliente_id : null;
$admin_id   = is_numeric($admin_id) ? (int)$admin_id : null;

/* =========================
   VALIDAÇÃO OBRIGATÓRIA
   ========================= */

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
   ATUALIZA DADOS
   ========================= */

$sql = "
UPDATE clientes SET
    nome = :nome,
    usuario = :usuario,
    senha = :senha,
    whatsapp = :whatsapp,
    m3u_url = :m3u_url
WHERE id = :cliente_id
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
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
