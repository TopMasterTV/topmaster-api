<?php
header('Content-Type: application/json');
require 'db.php'; // conexão com o PostgreSQL

$cliente_id = $_POST['cliente_id'] ?? null;
$admin_id   = $_POST['admin_id'] ?? null;

$nome     = $_POST['nome'] ?? null;
$usuario  = $_POST['usuario'] ?? null;
$senha    = $_POST['senha'] ?? null;
$whatsapp = $_POST['whatsapp'] ?? null;
$m3u_url  = $_POST['m3u_url'] ?? null;

if (!$cliente_id || !$admin_id) {
    echo json_encode([
        'success' => false,
        'message' => 'cliente_id e admin_id são obrigatórios'
    ]);
    exit;
}

/**
 * 🔒 Verifica se o cliente pertence ao admin
 */
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

/**
 * 🔧 Atualiza apenas dados permitidos
 */
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
