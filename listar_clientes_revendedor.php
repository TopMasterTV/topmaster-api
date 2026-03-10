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

if (!$DATABASE_URL) {
    echo json_encode([
        "success" => false,
        "message" => "DATABASE_URL não encontrada"
    ]);
    exit;
}

$db = parse_url($DATABASE_URL);

$host = $db['host'];
$dbname = ltrim($db['path'], '/');
$user = $db['user'];
$pass = $db['pass'];

try {

    $pdo = new PDO(
        "pgsql:host=$host;dbname=$dbname;sslmode=require",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => "Erro ao conectar ao banco"
    ]);
    exit;
}

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
