<?php
header("Content-Type: application/json; charset=utf-8");
date_default_timezone_set("America/Sao_Paulo");

$campanha_id = $_REQUEST["campanha_id"] ?? "";
$cliente_id = $_REQUEST["cliente_id"] ?? "";

if ($campanha_id === "" || $cliente_id === "") {
    echo json_encode([
        "success" => false,
        "message" => "campanha_id e cliente_id são obrigatórios"
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

try {
    $db = parse_url($DATABASE_URL);

    $pdo = new PDO(
        "pgsql:host={$db["host"]};port=" . ($db["port"] ?? 5432) . ";dbname=" . ltrim($db["path"], "/") . ";sslmode=require",
        $db["user"],
        $db["pass"],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->prepare("
        DELETE FROM enquete_respostas
        WHERE cliente_id = :cliente_id
        AND enquete_id IN (
            SELECT id FROM enquetes WHERE campanha_id = :campanha_id
        )
    ");

    $stmt->execute([
        ":cliente_id" => $cliente_id,
        ":campanha_id" => $campanha_id
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Votos limpos com sucesso",
        "votos_removidos" => $stmt->rowCount()
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao limpar votos",
        "erro" => $e->getMessage()
    ]);
}
?>
