<?php

$databaseUrl = getenv('DATABASE_URL');

if (!$databaseUrl) {
    die(json_encode([
        "success" => false,
        "message" => "DATABASE_URL não definida"
    ]));
}

$db = parse_url($databaseUrl);

$host   = $db['host'] ?? null;
$port   = $db['port'] ?? 5432; // 👈 PORTA PADRÃO
$dbname = isset($db['path']) ? ltrim($db['path'], '/') : null;
$user   = $db['user'] ?? null;
$pass   = $db['pass'] ?? null;

if (!$host || !$dbname || !$user || !$pass) {
    die(json_encode([
        "success" => false,
        "message" => "Dados de conexão incompletos"
    ]));
}

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die(json_encode([
        "success" => false,
        "message" => "Erro ao conectar ao banco",
        "error"   => $e->getMessage()
    ]));
}
