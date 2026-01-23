<?php
header("Content-Type: application/json");

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
   CONEXÃO COM BANCO
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
        "pgsql:host=$host;port=$port;dbname=$dbname",
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
   BUSCA CLIENTE
   ========================= */
$stmt = $pdo->prepare("
    SELECT id, nome, usuario, senha, m3u_url, admin_id
    FROM clientes
    WHERE usuario = :usuario
    LIMIT 1
");
$stmt->execute([
    ":usuario" => $usuario
]);

$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

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
if ($senha !== $cliente['senha']) {
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
    "success"   => true,
    "cliente_id"=> $cliente['id'],
    "nome"      => $cliente['nome'],
    "usuario"   => $cliente['usuario'],
    "m3u_url"   => $cliente['m3u_url']
]);
