<?php
header("Content-Type: application/json; charset=utf-8");
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

try {
    $db = parse_url($DATABASE_URL);

    $pdo = new PDO(
        "pgsql:host={$db["host"]};port=" . ($db["port"] ?? 5432) . ";dbname=" . ltrim($db["path"], "/") . ";sslmode=require",
        $db["user"],
        $db["pass"],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->prepare("
        UPDATE enquetes
        SET opcoes_corretas_ids = NULL
        WHERE id = :enquete_id
    ");

    $stmt->execute([
        ":enquete_id" => $enquete_id
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Resultado da enquete limpo com sucesso"
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao limpar resultado da enquete",
        "erro" => $e->getMessage()
    ]);
}
?>
