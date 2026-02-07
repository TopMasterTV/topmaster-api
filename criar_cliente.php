<?php
header("Content-Type: application/json");

/* =========================
   RECEBE DADOS (GET ou POST)
   ========================= */
$nome     = $_REQUEST['nome']     ?? '';
$usuario  = $_REQUEST['usuario']  ?? '';
$senha    = $_REQUEST['senha']    ?? '';
$m3u_url  = $_REQUEST['m3u_url']  ?? '';
$admin_id = $_REQUEST['admin_id'] ?? '';

if (
    $nome === '' ||
    $usuario === '' ||
    $senha === '' ||
    $m3u_url === '' ||
    $admin_id === ''
) {
    echo json_encode([
        "success" => false,
        "message" => "Todos os campos são obrigatórios"
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
   CRIA CLIENTE
   ========================= */
$senha_hash = password_hash($senha, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare("
        INSERT INTO clientes (nome, usuario, senha, m3u_url, admin_id)
        VALUES (:nome, :usuario, :senha, :m3u_url, :admin_id)
    ");

    $stmt->execute([
        ":nome"     => $nome,
        ":usuario"  => $usuario,
        ":senha"    => $senha_hash,
        ":m3u_url"  => $m3u_url,
        ":admin_id" => $admin_id
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Cliente criado com sucesso"
    ]);

} catch (PDOException $e) {

    if (str_contains($e->getMessage(), 'duplicate')) {
        echo json_encode([
            "success" => false,
            "message" => "Usuário já existe"
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Erro ao criar cliente"
        ]);
    }
}
