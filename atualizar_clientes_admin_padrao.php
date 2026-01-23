<?php
header("Content-Type: application/json");

require_once _DIR_ . "/db.php";

try {
    $sql = "
        UPDATE clientes
        SET admin_id = 1
        WHERE admin_id IS NULL
    ";

    $total = $pdo->exec($sql);

    echo json_encode([
        "success" => true,
        "message" => "Clientes atualizados para admin master",
        "total"   => $total
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao atualizar clientes",
        "error"   => $e->getMessage()
    ]);
}
