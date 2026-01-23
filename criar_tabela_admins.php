<?php
header('Content-Type: application/json');

$url = getenv('DATABASE_URL');

if (!$url) {
    echo json_encode([
        "success" => false,
        "message" => "DATABASE_URL não encontrada"
    ]);
    exit;
}

$db = parse_url($url);

$host = $db['host'];
$port = $db['port'] ?? 5432;
$dbname = ltrim($db['path'], '/');
$user = $db['user'];
$pass = $db['pass'];

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $sql = "
        CREATE TABLE IF NOT EXISTS admins (
            id SERIAL PRIMARY KEY,
            nome VARCHAR(100) NOT NULL,
            usuario VARCHAR(50) UNIQUE NOT NULL,
            senha VARCHAR(255) NOT NULL,
            tipo VARCHAR(20) NOT NULL,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ";

    $pdo->exec($sql);

    echo json_encode([
        "success" => true,
        "message" => "Tabela admins criada com sucesso"
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
