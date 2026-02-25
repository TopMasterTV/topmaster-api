<?php
header("Content-Type: application/json");

$id = $_REQUEST['id'] ?? '';

if ($id === '') {
    echo json_encode(["success" => false]);
    exit;
}

$DATABASE_URL = getenv("DATABASE_URL");
$db = parse_url($DATABASE_URL);

$pdo = new PDO(
    "pgsql:host={$db['host']};port=" . ($db['port'] ?? 5432) . ";dbname=" . ltrim($db['path'], '/') . ";sslmode=require",
    $db['user'],
    $db['pass']
);

$stmt = $pdo->prepare("SELECT nome, usuario, whatsapp FROM admins WHERE id = :id");
$stmt->execute([':id' => $id]);

$admin = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    "success" => true,
    "admin" => $admin
]);
