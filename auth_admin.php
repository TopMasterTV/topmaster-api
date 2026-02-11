<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

while (ob_get_level()) {
    ob_end_clean();
}

$usuario = trim($_POST['usuario'] ?? '');
$senha   = trim($_POST['senha'] ?? '');

if ($usuario === '' || $senha === '') {
    echo json_encode([
        "success" => false,
        "message" => "Usuário e senha são obrigatórios"
    ]);
    exit;
}

$DATABASE_URL = getenv("DATABASE_URL");

if (!$DATABASE_URL) {
    echo json_encode([
        "success" => false,
        "message" => "Erro interno"
    ]);
    exit;
}

$db = parse_url($DATABASE_URL);

$host   = $db['host'];
$port   = $db['port'] ?? 5432;
$dbname = ltrim($db['path'], '/');
$user   = $db['user'];
$pass   = $db['pass'];

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao conectar ao banco"
    ]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, usuario, senha, tipo
    FROM admins
    WHERE usuario = :usuario
    LIMIT 1
");

$stmt->execute([':usuario' => $usuario]);

$admin = $stmt->fetch();

if (!$admin || !password_verify($senha, $admin['senha'])) {
    echo json_encode([
        "success" => false,
        "message" => "Usuário ou senha inválidos"
    ]);
    exit;
}

echo json_encode([
    "success"  => true,
    "admin_id" => (int) $admin['id'],
    "usuario"  => $admin['usuario'],
    "tipo"     => $admin['tipo']
]);

exit;
