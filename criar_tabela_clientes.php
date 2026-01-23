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
$dbname = ltrim($db['path'], '/');
$user = $db['user'];
$pass = $db['pass'];
$port = $db['port'] ?? 5432;

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $sql = "
        CREATE TABLE IF NOT EXISTS clientes (
            id SERIAL PRIMARY KEY,
            nome VARCHAR(100),
            usuario VARCHAR(50) UNIQUE,
            senha VARCHAR(255),
            url_m3u TEXT,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ";

    $pdo->exec($sql);

    echo json_encode([
        "success" => true,
        "message" => "Tabela clientes criada com sucesso"
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
