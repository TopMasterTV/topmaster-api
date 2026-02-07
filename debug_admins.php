<?php
header('Content-Type: application/json');

require_once '/db.php';

try {
    $stmt = $pdo->query("
        SELECT id, nome, usuario, tipo 
        FROM admins
        ORDER BY id ASC
    ");

    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'total' => count($admins),
        'admins' => $admins
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
