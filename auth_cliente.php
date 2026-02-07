<?php
header("Content-Type: application/json");
ob_clean();

/* =========================
   RECEBE DADOS
   ========================= */
$usuario = $_REQUEST['usuario'] ?? '';
$senha   = $_REQUEST['senha']   ?? '';

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
$port   = $db['port'] ?? 5432; // 🔴 CORREÇÃO AQUI
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
        "message" => "Erro ao conectar ao banco"
    ]);
    exit;
}

/* =========================
   BUSCA CLIENTE
   ========================= */
$stmt = $pdo->prepare("
    SELECT id, nome, usuario, senha
    FROM clientes
    WHERE usuario = :usuario
    LIMIT 1
");

$stmt->execute([
    ':usuario' => $usuario
]);

$cliente = $stmt->fetch();

if (!$cliente) {
    echo json_encode([
        "success" => false,
        "message" => "Usuário ou senha inválidos"
    ]);
    exit;
}

/* =========================
   VERIFICA SENHA
   ========================= */
if (!password_verify($senha, $cliente['senha'])) {
    echo json_encode([
        "success" => false,
        "message" => "Usuário ou senha inválidos"
    ]);
    exit;
}

/* =========================
   LOGIN OK
   ========================= */
echo json_encode([
    "success" => true,
    "cliente" => [
        "id"      => (int) $cliente['id'],
        "nome"    => $cliente['nome'],
        "usuario" => $cliente['usuario']
    ]
]);
exit;
