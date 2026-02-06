<?php
header('Content-Type: application/json');

// conexão direta (sem _DIR_)
$DATABASE_URL = getenv("DATABASE_URL");

if (!$DATABASE_URL) {
    echo json_encode([
        'success' => false,
        'message' => 'DATABASE_URL não definida'
    ]);
    exit;
}

$db = parse_url($DATABASE_URL);

$host = $db['host'];
$port = $db['port'] ?? 5432;
$dbname = ltrim($db['path'], '/');
$user = $db['user'];
$pass = $db['pass'];

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao conectar ao banco'
    ]);
    exit;
}

// aceita GET e POST
$nome    = $_REQUEST['nome']    ?? '';
$usuario = $_REQUEST['usuario'] ?? '';
$senha   = $_REQUEST['senha']   ?? '';

if ($nome === '' || $usuario === '' || $senha === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Todos os campos são obrigatórios'
    ]);
    exit;
}

$tipo = 'revendedor';
$senha_hash = password_hash($senha, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare("
        INSERT INTO admins (nome, usuario, senha, tipo)
        VALUES (:nome, :usuario, :senha, :tipo)
    ");

    $stmt->execute([
        ':nome'    => $nome,
        ':usuario' => $usuario,
        ':senha'   => $senha_hash,
        ':tipo'    => $tipo
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Revendedor criado com sucesso'
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao criar revendedor',
        'erro' => $e->getMessage()
    ]);
}
