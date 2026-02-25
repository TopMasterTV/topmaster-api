<?php
header("Content-Type: application/json");

$id = $_REQUEST['id'] ?? '';

if ($id === '') {
    echo json_encode(["success" => false]);
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
        SELECT nome, usuario, whatsapp, senha_visivel
        FROM admins
        WHERE id = :id
    ");

    $stmt->execute([':id' => $id]);

    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        echo json_encode([
            "success" => false,
            "message" => "Admin não encontrado"
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "admin" => $admin
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao buscar admin"
    ]);
}
