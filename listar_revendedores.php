<?php
header("Content-Type: application/json");

$DATABASE_URL = getenv("DATABASE_URL");
if (!$DATABASE_URL) {
    echo json_encode(['success' => false, 'message' => 'DATABASE_URL não definida']);
    exit;
}

$db = parse_url($DATABASE_URL);

$pdo = new PDO(
    "pgsql:host={$db['host']};port=" . ($db['port'] ?? 5432) . ";dbname=" . ltrim($db['path'], '/'),
    $db['user'],
    $db['pass'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);

$stmt = $pdo->prepare("
    SELECT id, nome, usuario, senha, whatsapp
    FROM admins
    WHERE tipo = 'revendedor'
    ORDER BY id ASC
");

$stmt->execute();

echo json_encode([
    "success" => true,
    "revendedores" => $stmt->fetchAll()
]);
exit;
