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

if (!$DATABASE_URL) {
    echo json_encode([
        "success" => false,
        "message" => "DATABASE_URL não configurada"
    ]);
    exit;
}

try {
    $db = parse_url($DATABASE_URL);

    $host = $db["host"];
    $port = $db["port"] ?? 5432;
    $dbname = ltrim($db["path"], "/");
    $user = $db["user"];
    $pass = $db["pass"];

    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $stmt = $pdo->prepare("
        UPDATE enquete_opcoes
        SET correta = false
        WHERE enquete_id = :enquete_id
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
