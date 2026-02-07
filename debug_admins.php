<?php
header('Content-Type: application/json');

require_once './db.php';

// teste simples
$stmt = $pdo->query("SELECT id, nome, usuario, tipo FROM admins");
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'total' => count($admins),
    'admins' => $admins
]);
