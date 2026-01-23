<?php
header("Content-Type: application/json");

/* =========================
   RECEBE DADOS
   ========================= */
$admin_id = $_POST['admin_id'] ?? '';
$tipo     = $_POST['tipo']     ?? '';

if ($admin_id === '' || $tipo === '') {
    echo json_encode([
        "success" => false,
        "message" => "admin_id e tipo são obrigatórios"
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
   LISTAGEM DE CLIENTES
   ========================= */
try {

    if ($tipo === 'master') {
        // Admin master vê todos
        $sql = "
            SELECT id, nome, usuario, m3u_url, admin_id, criado_em
            FROM clientes
            ORDER BY id DESC
        ";
        $stmt = $pdo->query($sql);

    } else {
        // Admin funcionário vê só os dele
        $sql = "
            SELECT id, nome, usuario, m3u_url, admin_id, criado_em
            FROM clientes
            WHERE admin_id = :admin_id
            ORDER BY id DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ":admin_id" => $admin_id
        ]);
    }

    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "total"   => count($clientes),
        "clientes"=> $clientes
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao listar clientes",
        "error"   => $e->getMessage()
    ]);
}
