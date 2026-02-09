<?php
header("Content-Type: application/json");

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
$port   = $db['port'] ?? 5432;
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
   LISTAR REVENDEDORES
   ========================= */
$stmt = $pdo->prepare("
    SELECT
        id,
        nome,
        usuario,
        senha,
        whatsapp
    FROM admins
    WHERE tipo = 'revendedor'
    ORDER BY id ASC
");

$stmt->execute();
$revendedores = $stmt->fetchAll();

/* =========================
   RETORNO
   ========================= */
echo json_encode([
    "success" => true,
    "revendedores" => $revendedores
]);
exit;
