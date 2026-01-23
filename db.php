<?php

$databaseUrl = getenv('DATABASE_URL');

if (!$databaseUrl) {
    die(json_encode([
        "success" => false,
        "message" => "DATABASE_URL não definida"
    ]));
}

$db = parse_url($databaseUrl);

$host = $db['host'];
$port = $db['port'];
$dbname = ltrim($db['path'], '/');
$user = $db['user'];
$pass = $db['pass'];

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
        "error" => $e->getMessage()
    ]));
}
