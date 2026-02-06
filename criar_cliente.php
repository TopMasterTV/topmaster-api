<?php
header("Content-Type: application/json");

/* =========================
   RECEBE DADOS (POST ou GET)
   ========================= */
$nome     = $_POST['nome']     ?? $_GET['nome']     ?? '';
$usuario  = $_POST['usuario']  ?? $_GET['usuario']  ?? '';
$senha    = $_POST['senha']    ?? $_GET['senha']    ?? '';
$m3u_url  = $_POST['m3u_url']  ?? $_GET['m3u_url']  ?? '';
$admin_id = $_POST['admin_id'] ?? $_GET['admin_id'] ?? '';

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
   INSERE CLIENTE
   ========================= */
try {
    $sql = "
        INSERT INTO clientes (nome, usuario, senha, m3u_url, admin_id)
        VALUES (:nome, :usuario, :senha, :m3u_url, :admin_id)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ":nome"     => $nome,
        ":usuario"  => $usuario,
        ":senha"    => $senha,
        ":m3u_url"  => $m3u_url,
        ":admin_id" => $admin_id
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Cliente criado com sucesso"
    ]);

} catch (PDOException $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao criar cliente"
    ]);
}
