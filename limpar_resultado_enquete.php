<?php
header("Content-Type: application/json");
date_default_timezone_set("America/Sao_Paulo");

$enquete_id = $_REQUEST["enquete_id"] ?? "";

if ($enquete_id === "") {
    echo json_encode([
        "success" => false,
        "message" => "enquete_id é obrigatório"
    ]);
    exit;
}

$DATABASE_URL = getenv("DATABASE_URL");

if (!$DATABASE_URL) {
    echo json_encode([
        "success" => false,
        "message" => "DATABASE_URL não configurada"
    ]);
    exit;
}

$db = parse_url($DATABASE_URL);

$conn = pg_connect(
    "host={$db["host"]} " .
    "port={$db["port"]} " .
    "dbname=" . ltrim($db["path"], "/") . " " .
    "user={$db["user"]} " .
    "password={$db["pass"]} " .
    "sslmode=require"
);

if (!$conn) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao conectar ao banco"
    ]);
    exit;
}

$sql = "UPDATE enquete_opcoes SET correta = false WHERE enquete_id = $1";

$result = pg_query_params($conn, $sql, [$enquete_id]);

if (!$result) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao limpar resultado da enquete"
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "message" => "Resultado da enquete limpo com sucesso"
]);
?>
