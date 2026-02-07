<?php

// =========================
// HEADERS (CORS + JSON)
// =========================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

// Preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// =========================
// LIMPA QUALQUER SAÍDA
// =========================
while (ob_get_level()) {
    ob_end_clean();
}

// =========================
// RECEBE DADOS
// =========================
$usuario = $_POST['usuario'] ?? '';
$senha   = $_POST['senha'] ?? '';

if ($usuario === '' || $senha === '') {
    echo json_encode([
        "success" => false,
        "message" => "Usuário e senha são obrigatórios"
    ]);
    exit;
}

// =========================
// CONEXÃO COM BANCO (RENDER)
// =========================
$DATABASE_URL = getenv("DATABASE_URL");

if (!$DATABASE_URL) {
    echo json_encode([
        "success" => false,
        "message" => "DATABASE_URL não definida"
    ]);
    exit;
}

$db = parse_url($DATABASE_URL);

$host   = $db['host'] ?? null;
$port   = $db['port'] ?? 5432;
$dbname = isset($db['path']) ? ltrim($db['path'], '/') : null;
$user   = $db['user'] ?? null;
$pass   = $db['pass'] ?? null;

if (!$host || !$dbname || !$user || !$pass) {
    echo json_encode([
        "success" => false,
        "message" => "Configuração do banco inválida"
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

// =========================
// BUSCA ADMIN
// =========================
$stmt = $pdo->prepare("
    SELECT id, usuario, senha, tipo
    FROM admins
    WHERE usuario = :usuario
    LIMIT 1
");

$stmt->execute([
    ':usuario' => $usuario
]);

$admin = $stmt->fetch();

if (!$admin) {
    echo json_encode([
        "success" => false,
        "message" => "Usuário ou senha inválidos"
    ]);
    exit;
}

// =========================
// VERIFICA SENHA
// =========================
if (!password_verify($senha, $admin['senha'])) {
    echo json_encode([
        "success" => false,
        "message" => "Usuário ou senha inválidos"
    ]);
    exit;
}

// =========================
// LOGIN OK (FORMATO LIMPO)
// =========================
echo json_encode([
    "success"   => true,
    "admin_id" => (int) $admin['id'],
    "usuario"  => $admin['usuario'],
    "tipo"     => $admin['tipo']
]);

exit;
