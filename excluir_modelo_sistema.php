<?php
header("Content-Type: application/json");

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
        "pgsql:host={$db['host']};port=" . ($db['port'] ?? 5432) . ";dbname=" . ltrim($db['path'], '/'),
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $id = $_POST['id'] ?? $_GET['id'] ?? '';

    if ($id == '') {
        echo json_encode([
            "success" => false,
            "message" => "ID obrigatório"
        ]);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM modelos_sistemas WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode([
        "success" => true
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao excluir modelo"
    ]);
}
