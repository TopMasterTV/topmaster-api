<?php
header('Content-Type: application/json');

$url = getenv('DATABASE_URL');

if (!$url) {
    echo json_encode(array(
        "success" => false,
        "message" => "DATABASE_URL não encontrada"
    ));
    exit;
}

$db = parse_url($url);

$host   = isset($db['host']) ? $db['host'] : null;
$port   = isset($db['port']) ? $db['port'] : 5432;
$dbname = isset($db['path']) ? ltrim($db['path'], '/') : null;
$user   = isset($db['user']) ? $db['user'] : null;
$pass   = isset($db['pass']) ? $db['pass'] : null;

if (!$host || !$dbname || !$user || !$pass) {
    echo json_encode(array(
        "success" => false,
        "message" => "Dados de conexão incompletos"
    ));
    exit;
}

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $user,
        $pass,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
} catch (Exception $e) {
    echo json_encode(array(
        "success" => false,
        "message" => "Erro ao conectar ao banco"
    ));
    exit;
}

// Recebe dados do POST
$usuario = isset($_POST['usuario']) ? trim($_POST['usuario']) : '';
$senha   = isset($_POST['senha']) ? $_POST['senha'] : '';

if ($usuario === '' || $senha === '') {
    echo json_encode(array(
        "success" => false,
        "message" => "Usuário e senha são obrigatórios"
    ));
    exit;
}

// Busca admin
$sql = "SELECT id, usuario, senha, tipo FROM admins WHERE usuario = :usuario LIMIT 1";
$stmt = $pdo->prepare($sql);
$stmt->execute(array("usuario" => $usuario));

$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    echo json_encode(array(
        "success" => false,
        "message" => "Usuário ou senha inválidos"
    ));
    exit;
}

// Verifica senha criptografada
if (!password_verify($senha, $admin['senha'])) {
    echo json_encode(array(
        "success" => false,
        "message" => "Usuário ou senha inválidos"
    ));
    exit;
}

// Login OK
echo json_encode(array(
    "success"  => true,
    "admin_id" => $admin['id'],
    "tipo"     => $admin['tipo']
));
