<?php
header("Content-Type: application/json");
require __DIR__ . "/conexao.php";

try {
    $stmt = $pdo->query("SELECT id, nome, url_padrao FROM modelos_sistemas ORDER BY nome ASC");
    $modelos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "modelos" => $modelos
    ]);
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao listar modelos"
    ]);
}
