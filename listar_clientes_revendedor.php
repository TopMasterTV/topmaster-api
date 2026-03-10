<?php
header("Content-Type: application/json");

$revendedor_id = $_POST['revendedor_id'] ?? $_GET['revendedor_id'] ?? '';

if ($revendedor_id == '') {
    echo json_encode([
        "success" => false,
        "message" => "revendedor_id obrigatório"
    ]);
    exit;
}

$DATABASE_URL = getenv("DATABASE_URL");
$db = parse_url($DATABASE_URL);

$pdo = new PDO(
    "pgsql:host={$db['host']};port={$db['port']};dbname=" . ltrim($db['path'],'/').";sslmode=require",
    $db['user'],
    $db['pass']
);

$stmt = $pdo->prepare("
    SELECT * FROM clientes
    WHERE revendedor_id = :revendedor_id
    ORDER BY nome ASC
");

$stmt->execute([
    ':revendedor_id' => $revendedor_id
]);

$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    "success" => true,
    "clientes" => $clientes
]);
