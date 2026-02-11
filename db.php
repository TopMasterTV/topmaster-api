<?php
ini_set('display_errors', 0);
error_reporting(0);

$databaseUrl = getenv('DATABASE_URL');

if (!$databaseUrl) {
    throw new Exception('DATABASE_URL não definida');
}

$db = parse_url($databaseUrl);

$host   = $db['host'] ?? null;
$port   = $db['port'] ?? 5432;
$dbname = isset($db['path']) ? ltrim($db['path'], '/') : null;
$user   = $db['user'] ?? null;
$pass   = $db['pass'] ?? null;

if (!$host || !$dbname || !$user || !$pass) {
    throw new Exception('Dados de conexão incompletos');
}

$pdo = new PDO(
    "pgsql:host=$host;port=$port;dbname=$dbname",
    $user,
    $pass,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);
