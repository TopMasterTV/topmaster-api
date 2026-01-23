<?php
header('Content-Type: application/json');

// Pega a variável de ambiente do Render
$DATABASE_URL = getenv('DATABASE_URL');

if (!$DATABASE_URL) {
    echo json_encode(array(
        "success" => false,
        "message" => "DATABASE_URL não encontrada"
    ));
    exit;
}

// Quebra a URL do banco
$db = parse_url($DATABASE_URL);

$host   = isset($db['host']) ? $db['host'] : null;
$port   = isset($db['port']) ? $db['port'] : 5432;
$dbname = isset($db['path']) ? ltrim($db['path'], '/') : null;
$user   = isset($db['user']) ? $db['user'] : null;
$pass   = isset($db['pass']) ? $db['pass'] : null;

if (!$host || !$dbname || !$user || !$pass) {
    echo json_encode(array(
        "success" => false,
        "message" => "Dados de conexão incompletos"
    ));
    exit;
}

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $user,
        $pass,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );

    $sql = "
        CREATE TABLE IF NOT EXISTS clientes (
            id SERIAL PRIMARY KEY,
            nome VARCHAR(100) NOT NULL,
            usuario VARCHAR(50) UNIQUE NOT NULL,
            senha VARCHAR(255) NOT NULL,
            m3u_url TEXT NOT NULL,
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ";

    $pdo->exec($sql);

    echo json_encode(array(
        "success" => true,
        "message" => "Tabela clientes criada com sucesso"
    ));

} catch (Exception $e) {
    echo json_encode(array(
        "success" => false,
        "message" => "Erro ao criar tabela",
        "erro" => $e->getMessage()
    ));
}
