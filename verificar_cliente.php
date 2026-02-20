<?php
header("Content-Type: application/json");
require_once "conexao.php";

$cliente_id = $_POST['cliente_id'] ?? '';

if ($cliente_id == '') {
    echo json_encode([
        "success" => false,
        "message" => "cliente_id obrigatório"
    ]);
    exit;
}

$sql = "SELECT ativo FROM clientes WHERE id = $1";
$result = pg_query_params($conn, $sql, [$cliente_id]);

if (!$result || pg_num_rows($result) == 0) {
    echo json_encode([
        "success" => false,
        "message" => "Cliente não encontrado"
    ]);
    exit;
}

$cliente = pg_fetch_assoc($result);

echo json_encode([
    "success" => true,
    "ativo" => $cliente['ativo']
]);
