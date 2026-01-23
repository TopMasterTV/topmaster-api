<?php
header("Content-Type: application/json");

$database_url = getenv("DATABASE_URL");

if (!$database_url) {
    echo json_encode(["success" => false, "message" => "DATABASE_URL não encontrada"]);
    exit;
}

$db = parse_url($database_url);

$host = $db["host"];
$port = $db["port"];
$user = $db["user"];
$pass = $db["pass"];
$dbname = ltrim($db["path"], "/");

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => "Erro ao conectar ao banco"]);
    exit;
}

$sql = "
CREATE TABLE IF NOT EXISTS clientes (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    usuario VARCHAR(50) UNIQUE NOT NULL,
    senha_hash TEXT NOT NULL,
    plano VARCHAR(50),
    status VARCHAR(20) DEFAULT 'ativo',
    validade DATE,
    m3u_url TEXT,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
";

try {
    $pdo->exec($sql);
    echo json_encode([
        "success" => true,
        "message" => "Tabela clientes criada com sucesso"
    ]);
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao criar tabela"
    ]);
}
