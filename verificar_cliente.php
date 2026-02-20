<?php
header("Content-Type: application/json");
require_once _DIR_ . "/db.php";

$cliente_id = $_GET['cliente_id'] ?? $_POST['cliente_id'] ?? '';

if ($cliente_id == "") {
    echo json_encode([
        "success" => false,
        "message" => "cliente_id obrigatório"
    ]);
    exit;
}

try {

    $stmt = $pdo->prepare("SELECT ativo FROM clientes WHERE id = :id");
    $stmt->bindParam(":id", $cliente_id, PDO::PARAM_INT);
    $stmt->execute();

    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cliente) {
        echo json_encode([
            "success" => false,
            "message" => "Cliente não encontrado"
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "ativo" => (bool)$cliente["ativo"]
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro no servidor"
    ]);
}
