<?php
header('Content-Type: application/json');

$URL = getenv('URL_DO_BANCO_DE_DADOS');

if (!$URL) {
    echo json_encode([
        "success" => false,
        "message" => "URL_DO_BANCO_DE_DADOS não definida"
    ]);
    exit;
}

$db = parse_url($URL);

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

    $stmt = $pdo->query("SELECT id, nome, usuario, m3u_url, criado_em FROM clientes ORDER BY id DESC");
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "clientes" => $clientes
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao listar clientes"
    ]);
}
