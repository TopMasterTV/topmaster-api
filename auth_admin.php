<?php
header("Content-Type: application/json");
ob_clean();

/* =========================
   RECEBE DADOS
   ========================= */
$usuario = $_POST['usuario'] ?? '';
$senha   = $_POST['senha']   ?? '';

if ($usuario === '' || $senha === '') {
    echo json_encode([
        "success" => false,
        "message" => "Usuário e senha são obrigatórios"
    ]);
    exit;
}

/* =========================
   CONEXÃO COM BANCO (RENDER)
   ========================= */
$DATABASE_URL = getenv("DATABASE_URL");

if (!$DATABASE_URL) {
    echo json_encode([
        "success" => false,
        "message" => "DATABASE_URL não definida"
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
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao conectar ao banco de dados"
    ]);
    exit;
}

/* =========================
   BUSCA ADMIN
   ========================= */
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

/* =========================
   VERIFICA SENHA
   ========================= */
if (!password_verify($senha, $admin['senha'])) {
    echo json_encode([
        "success" => false,
        "message" => "Usuário ou senha inválidos"
    ]);
    exit;
}

/* =========================
   LOGIN OK (FORMATO QUE O FLUTTER ESPERA)
   ========================= */
echo json_encode([
    "success"   => true,
    "admin_id" => (int) $admin['id'],
    "usuario"  => $admin['usuario'],
    "tipo"     => $admin['tipo']
]);
exit;
