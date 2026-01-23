<?php
header('Content-Type: application/json');

// Pega a URL do banco do Render
$DATABASE_URL = getenv("DATABASE_URL");

if (!$DATABASE_URL) {
    echo json_encode([
        "success" => false,
        "message" => "DATABASE_URL não definida"
    ]);
    exit;
}

// Quebra a URL do banco
$db = parse_url($DATABASE_URL);

$host = $db["host"];
$port = $db["port"] ?? 5432;
$dbname = ltrim($db["path"], "/");
$user = $db["user"];
$pass = $db["pass"];

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $sql = "SELECT id, nome, usuario, m3u_url, criado_em FROM clientes ORDER BY id DESC";
    $stmt = $pdo->query($sql);
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "total" => count($clientes),
        "clientes" => $clientes
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao listar clientes",
        "erro" => $e->getMessage()
    ]);
}
