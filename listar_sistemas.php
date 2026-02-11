<?php
header("Content-Type: application/json");

$cliente_id = $_POST['cliente_id'] ?? '';

if ($cliente_id === '') {
    echo json_encode([
        "success" => false,
        "message" => "cliente_id obrigatório"
    ]);
    exit;
}

$DATABASE_URL = getenv("DATABASE_URL");

if (!$DATABASE_URL) {
    echo json_encode([
        "success" => false,
        "message" => "DATABASE_URL não definida"
    ]);
    exit;
}

$db = parse_url($DATABASE_URL);

try {
    $pdo = new PDO(
        "pgsql:host={$db['host']};port=" . ($db['port'] ?? 5432) . ";dbname=" . ltrim($db['path'], '/') . ";sslmode=require",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->prepare("
        SELECT *
        FROM sistemas
        WHERE cliente_id = :cliente_id
        ORDER BY id DESC
    ");

    $stmt->execute([
        ':cliente_id' => $cliente_id
    ]);

    $sistemas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "sistemas" => $sistemas
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao listar sistemas",
        "error" => $e->getMessage()
    ]);
}
