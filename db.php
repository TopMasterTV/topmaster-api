<?php

$databaseUrl = getenv('DATABASE_URL');

if (!$databaseUrl) {
    echo json_encode([
        "success" => false,
        "message" => "DATABASE_URL não definida"
    ]);
    exit;
}

$db = parse_url($databaseUrl);

$host   = $db['host'] ?? null;
$port   = $db['port'] ?? 5432;
$dbname = isset($db['path']) ? ltrim($db['path'], '/') : null;
$user   = $db['user'] ?? null;
$pass   = $db['pass'] ?? null;

if (!$host || !$dbname || !$user || !$pass) {
    echo json_encode([
        "success" => false,
        "message" => "Dados de conexão incompletos"
    ]);
    exit;
}

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao conectar ao banco"
    ]);
    exit;
}
