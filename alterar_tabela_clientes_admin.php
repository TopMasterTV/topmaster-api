<?php
header("Content-Type: application/json");

require_once __DIR__ . "/db.php";

try {
    // Adiciona coluna admin_id se não existir
    $sql = "
        ALTER TABLE clientes
        ADD COLUMN IF NOT EXISTS admin_id INTEGER
    ";
    $pdo->exec($sql);

    echo json_encode([
        "success" => true,
        "message" => "Coluna admin_id adicionada com sucesso"
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Erro ao alterar tabela clientes",
        "error" => "ALTERAR_TABELA_CLIENTES_ADMIN_INTERNAL_ERROR"
    ]);
}
