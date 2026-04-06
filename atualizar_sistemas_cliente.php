<?php
header("Content-Type: application/json");

// 🔥 NÃO MOSTRAR ERRO NO OUTPUT
error_reporting(0);
ini_set('display_errors', 0);

// =========================
// VALIDAR CLIENTE
// =========================
$cliente_id = $_POST['cliente_id'] ?? '';

if (!$cliente_id) {
    echo json_encode([
        "success" => false,
        "message" => "cliente_id obrigatório"
    ]);
    exit;
}

// =========================
// CONEXÃO BANCO
// =========================
$DATABASE_URL = getenv("DATABASE_URL");

if (!$DATABASE_URL) {
    echo json_encode([
        "success" => false,
        "message" => "DATABASE_URL não encontrada"
    ]);
    exit;
}

$db = parse_url($DATABASE_URL);

$host = $db['host'] ?? '';
$port = $db['port'] ?? 5432;
$user = $db['user'] ?? '';
$pass = $db['pass'] ?? '';
$dbname = ltrim($db['path'] ?? '', '/');

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao conectar ao banco"
    ]);
    exit;
}

// =========================
// LIMPA DEBUG ANTIGO
// =========================
file_put_contents("debug_api.txt", "");

// =========================
// BUSCAR SISTEMAS
// =========================
$stmt = $pdo->prepare("SELECT * FROM sistemas WHERE cliente_id = :cliente_id");
$stmt->execute([':cliente_id' => $cliente_id]);

$sistemas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =========================
// ATUALIZAR SISTEMAS
// =========================
foreach ($sistemas as $s) {

    $url = isset($s['url']) ? rtrim($s['url'], '/') : '';
    $user = $s['usuario'] ?? '';
    $pass = $s['senha'] ?? '';

    if (!$url || !$user || !$pass) continue;

    $status = null
