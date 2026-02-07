<?php
header("Content-Type: application/json");

// Conexão com o banco usando DATABASE_URL do Render
$database_url = getenv("DATABASE_URL");

if (!$database_url) {
    echo json_encode([
        "success" => false,
        "message" => "DATABASE_URL não encontrada"
    ]);
    exit;
}

$db = parse_url($database_url);

$host = $db["host"];
$port = $db["port"];
$user = $db["user"];
$pass = $db["pass"];
$dbname = ltrim($db["path"], "/");

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

// Recebe os dados
$usuario = $_POST["usuario"] ?? "";
$senha   = $_POST["senha"] ?? "";

if ($usuario === "" || $senha === "") {
    echo json_encode([
        "success" => false,
        "message" => "Usuário e senha são obrigatórios"
    ]);
    exit;
}

// Busca o cliente
$sql = "SELECT id, nome, usuario, senha_hash, plano, status, validade, m3u_url 
        FROM clientes 
        WHERE usuario = :usuario 
        LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute(["usuario" => $usuario]);

$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cliente) {
    echo json_encode([
        "success" => false,
        "message" => "Usuário ou senha inválidos"
    ]);
    exit;
}

// Verifica senha criptografada
if (!password_verify($senha, $cliente["senha_hash"])) {
    echo json_encode([
        "success" => false,
        "message" => "Usuário ou senha inválidos"
    ]);
    exit;
}

// Verifica status
if ($cliente["status"] !== "ativo") {
    echo json_encode([
        "success" => false,
        "message" => "Cliente bloqueado ou inativo"
    ]);
    exit;
}

// Login OK
echo json_encode([
    "success" => true,
    "message" => "Login realizado com sucesso",
    "cliente" => [
        "id" => $cliente["id"],
        "nome" => $cliente["nome"],
        "usuario" => $cliente["usuario"],
        "plano" => $cliente["plano"],
        "validade" => $cliente["validade"],
        "m3u_url" => $cliente["m3u_url"]
    ]
]);
