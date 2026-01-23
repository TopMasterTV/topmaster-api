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

/**
 * DADOS DO ADMIN MASTER
 * ⚠️ Você pode trocar depois, mas NÃO agora
 */
$nome     = "Erick";
$usuario  = "admin";
$senha    = "123456"; // depois trocamos
$tipo     = "master";

// Verifica se já existe admin master
$check = $pdo->prepare("SELECT id FROM admins WHERE tipo = 'master' LIMIT 1");
$check->execute();

if ($check->fetch()) {
    echo json_encode(array(
        "success" => false,
        "message" => "Admin master já existe"
    ));
    exit;
}

// Criptografa a senha
$senha_hash = password_hash($senha, PASSWORD_BCRYPT);

// Insere admin master
$sql = "
INSERT INTO admins (nome, usuario, senha, tipo)
VALUES (:nome, :usuario, :senha, :tipo)
";

$stmt = $pdo->prepare($sql);

try {
    $stmt->execute(array(
        "nome"    => $nome,
        "usuario" => $usuario,
        "senha"   => $senha_hash,
        "tipo"    => $tipo
    ));

    echo json_encode(array(
        "success" => true,
        "message" => "Admin master criado com sucesso",
        "usuario" => $usuario
    ));

} catch (Exception $e) {
    echo json_encode(array(
        "success" => false,
        "message" => "Erro ao criar admin master"
    ));
}
