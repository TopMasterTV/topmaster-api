<?php
header("Content-Type: application/json");

$id = $_POST['id'] ?? '';

if ($id === '') {
    echo json_encode([
        "success" => false,
        "message" => "ID obrigatório"
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
        "pgsql:host={$db['host']};port=" . ($db['port'] ?? 5432) .
        ";dbname=" . ltrim($db['path'], '/') .
        ";sslmode=require",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->prepare("
        DELETE FROM sistemas
        WHERE id = :id
    ");

    $stmt->execute([
        ':id' => $id
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Sistema excluído com sucesso"
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao excluir sistema",
        "error" => $e->getMessage()
    ]);
}
