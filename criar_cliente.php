<?php
header('Content-Type: application/json');

// Pega a variável de ambiente do Render
$DATABASE_URL = getenv('DATABASE_URL');

if (!$DATABASE_URL) {
    echo json_encode(array(
        "success" => false,
        "message" => "DATABASE_URL não encontrada"
    ));
    exit;
}

// Quebra a URL do banco
$db = parse_url($DATABASE_URL);

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

// Conecta no banco
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
$nome     = isset($_POST['nome']) ? trim($_POST['nome']) : '';
$usuario  = isset($_POST['usuario']) ? trim($_POST['usuario']) : '';
$senha    = isset($_POST['senha']) ? $_POST['senha'] : '';
$m3u_url  = isset($_POST['m3u_url']) ? trim($_POST['m3u_url']) : '';

if ($nome === '' || $usuario === '' || $senha === '' || $m3u_url === '') {
    echo json_encode(array(
        "success" => false,
        "message" => "Todos os campos são obrigatórios"
    ));
    exit;
}

// Criptografa a senha
$senha_hash = password_hash($senha, PASSWORD_BCRYPT);

// Verifica se usuário já existe
$check = $pdo->prepare("SELECT id FROM clientes WHERE usuario = :usuario");
$check->execute(array("usuario" => $usuario));

if ($check->fetch()) {
    echo json_encode(array(
        "success" => false,
        "message" => "Usuário já existe"
    ));
    exit;
}

// Insere cliente
$sql = "
INSERT INTO clientes (nome, usuario, senha, m3u_url, ativo)
VALUES (:nome, :usuario, :senha, :m3u_url, TRUE)
";

$stmt = $pdo->prepare($sql);

try {
    $stmt->execute(array(
        "nome"    => $nome,
        "usuario" => $usuario,
        "senha"   => $senha_hash,
        "m3u_url" => $m3u_url
    ));

    echo json_encode(array(
        "success" => true,
        "message" => "Cliente criado com sucesso"
    ));

} catch (Exception $e) {
    echo json_encode(array(
        "success" => false,
        "message" => "Erro ao criar cliente"
    ));
}
