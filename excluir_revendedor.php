<?php
header("Content-Type: application/json");

/* =========================
   RECEBE ID
   ========================= */
$id = $_POST['id'] ?? '';

if ($id === '') {
    echo json_encode([
        "success" => false,
        "message" => "ID não informado"
    ]);
    exit;
}

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
   EXCLUI REVENDEDOR
   ========================= */
$stmt = $pdo->prepare("
    DELETE FROM admins
    WHERE id = :id
      AND tipo = 'revendedor'
");

$stmt->execute([
    ':id' => $id
]);

echo json_encode([
    "success" => true
]);
exit;
