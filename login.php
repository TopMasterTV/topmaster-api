<?php
header('Content-Type: application/json');

$usuario = $_POST['usuario'] ?? '';
$senha   = $_POST['senha'] ?? '';

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
        "message" => "Variável DATABASE_URL não encontrada"
    ]);
    exit;
}

$db = parse_url($DATABASE_URL);

$host = $db['host'];
$port = $db['port'] ?? 5432;
$user = $db['user'];
$pass = $db['pass'];
$name = ltrim($db['path'], '/');

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$name",
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

/* =========================
   1️⃣ TENTA LOGIN COMO ADMIN
   ========================= */
$stmt = $pdo->prepare("
    SELECT id, tipo 
    FROM admins 
    WHERE usuario = :usuario 
      AND senha = :senha
");
$stmt->execute([
    ':usuario' => $usuario,
    ':senha'   => $senha
]);

$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if ($admin) {
    echo json_encode([
        "success"  => true,
        "tipo"     => "admin",
        "nivel"    => $admin['tipo'],
        "admin_id" => $admin['id']
    ]);
    exit;
}

/* =========================
   2️⃣ TENTA LOGIN COMO CLIENTE
   ========================= */
$stmt = $pdo->prepare("
    SELECT id, nome 
    FROM clientes 
    WHERE usuario = :usuario 
      AND senha = :senha
");
$stmt->execute([
    ':usuario' => $usuario,
    ':senha'   => $senha
]);

$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

if ($cliente) {
    echo json_encode([
        "success"    => true,
        "tipo"       => "cliente",
        "cliente_id" => $cliente['id'],
        "nome"       => $cliente['nome']
    ]);
    exit;
}

/* =========================
   3️⃣ NÃO ACHOU NINGUÉM
   ========================= */
echo json_encode([
    "success" => false,
    "message" => "Usuário ou senha inválidos"
]);
